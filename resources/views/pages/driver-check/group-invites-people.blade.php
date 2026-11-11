@extends('layouts.driver-check')

@section('title', __('driver_check.menu.group_invites_people'))

@section('content')

<div
    x-data="dcGroupInvitesPeople({
        endpoints: {
            index: @js(route('api.telegram.group-invites.people')),
            reset: @js(route('api.telegram.group-invites.people.reset')),
        },
        translations: @js(__('driver_check.invites')),
    })"
>
    <x-driver-check.shell>

        <x-driver-check.page-header
            :title="__('driver_check.menu.group_invites_people')"
            :description="__('driver_check.invites.people.description')"
        >
            <x-slot:actions>
                <x-driver-check.button
                    size="icon"
                    x-on:click="counts = null; load()"
                    ::disabled="loading"
                    :title="__('telegram.ui.refresh')"
                    :aria-label="__('telegram.ui.refresh')"
                >
                    <x-driver-check.icon name="refresh" class="h-4 w-4" ::class="(loading || totalLoading) && 'animate-spin'" />
                </x-driver-check.button>
            </x-slot:actions>
        </x-driver-check.page-header>

        <x-driver-check.error-alert :title="__('driver_check.invites.load_failed')" retry="load()" />

        {{-- Where people stand: a number and a filter in one --}}
        <div class="dc-stats">
            <template x-for="chip in flagChips()" :key="chip.value">
                <button type="button" class="dc-stat" :aria-pressed="filters.flag === chip.value" x-on:click="setFlag(chip.value)">
                    <p class="dc-stat__label" x-text="chip.label.split(' · ')[0]"></p>
                    <p class="dc-stat__value" x-text="counts ? chip.label.split(' · ')[1] : '…'"></p>
                </button>
            </template>
        </div>

        <x-driver-check.block>
            {{-- Which group, which status, who --}}
            <div class="dc-toolbar">
                <div class="dc-toolbar__grow">
                    <x-driver-check.search model="filters.search" :placeholder="__('driver_check.invites.people.search')" debounce="450ms" />
                </div>

                <x-driver-check.select x-model="filters.group_id" x-on:change="filters.status = filters.group_id ? filters.status : ''; load()" class="!w-52">
                    <option value="">{{ __('driver_check.invites.people.any_group') }}</option>
                    <template x-for="g in groups" :key="g.id">
                        <option :value="String(g.id)" x-text="g.title + (g.archived ? ' · ' + t.people.archived : '')" :selected="String(g.id) === filters.group_id"></option>
                    </template>
                </x-driver-check.select>

                <x-driver-check.select x-model="filters.status" x-on:change="load()" class="!w-48">
                    <option value="">{{ __('driver_check.invites.people.any_status') }}</option>
                    @foreach (['added', 'invited', 'left', 'not_added', 'failed', 'already', 'sent', 'joined'] as $status)
                        <option value="{{ $status }}">{{ __("driver_check.invites.statuses.{$status}") }}</option>
                    @endforeach
                </x-driver-check.select>

                <div x-show="filters.flag === 'untried'" x-cloak>
                    <x-driver-check.chips items="reachChips()" selected="filters.reach" action="setReach" :title="__('driver_check.invites.people.reach_hint')" />
                </div>

                <div class="dc-toolbar__end">
                    <x-driver-check.button size="sm" icon="refresh" x-show="filters.flag === 'cannot' && (counts?.cannot ?? 0) > 0" x-cloak x-on:click="resetRefused()" ::disabled="busy">
                        {{ __('driver_check.invites.people.reset') }}
                    </x-driver-check.button>
                    <x-driver-check.button size="sm" variant="ghost" icon="close" x-show="filtered()" x-cloak x-on:click="reset()">{{ __('driver_check.invites.people.clear') }}</x-driver-check.button>
                    <x-driver-check.select x-model.number="perPage" x-on:change="changePerPage()" class="!w-auto" aria-label="{{ __('telegram.ui.per_page') }}">
                        @foreach ([30, 50, 100] as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </x-driver-check.select>
                </div>
            </div>

            <div x-show="loading && rows.length === 0" class="dc-block__wait" aria-hidden="true">
                @foreach ([72, 54, 86, 40, 64, 78] as $width)
                    <div class="dc-skeleton h-3 rounded bg-gray-100 dark:bg-white/[0.06]" style="width: {{ $width }}%"></div>
                @endforeach
            </div>

            <p x-show="!loading && rows.length === 0" x-cloak class="dc-none">{{ __('driver_check.invites.people.empty') }}</p>

            <div x-show="rows.length > 0" x-cloak>
                <x-driver-check.table min="860px">
                    <thead>
                        <tr>
                            <th>{{ __('driver_check.invites.people.cols.person') }}</th>
                            <th>{{ __('driver_check.invites.people.cols.stand') }}</th>
                            <th>{{ __('driver_check.invites.people.cols.groups') }}</th>
                            <th class="dc-acts"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in rows" :key="row.id">
                            <tr>
                                {{-- Who --}}
                                <td>
                                    <span class="dc-main block max-w-[260px] truncate" x-text="row.name || (row.username ? '@' + row.username : 'ID ' + row.telegram_user_id)"></span>
                                    <span class="dc-sub2">
                                        <span x-text="row.username ? '@' + row.username : 'ID ' + row.telegram_user_id"></span>
                                        <span x-show="row.phone" x-cloak class="tabular-nums" x-text="' · ' + phone(row.phone)"></span>
                                    </span>
                                </td>

                                {{-- Where they stand, and why --}}
                                <td>
                                    <x-driver-check.badge x-bind:class="flagTone(row.flag)">
                                        <span x-text="flagLabel(row.flag)"></span>
                                    </x-driver-check.badge>
                                    <span x-show="reasonLine(row)" x-cloak class="dc-sub2 max-w-[260px] truncate !whitespace-normal" x-text="reasonLine(row)"></span>
                                </td>

                                {{-- What became of them in each of our groups --}}
                                <td>
                                    <div class="flex min-w-0 flex-wrap gap-1.5">
                                        <span x-show="row.groups.length === 0" class="text-[12px] text-gray-400">{{ __('driver_check.invites.people.no_tries') }}</span>
                                        <template x-for="g in tries(row)" :key="g.group_id">
                                            <span
                                                class="inline-flex max-w-full items-center gap-1.5 rounded-md border px-2 py-0.5 text-[11.5px]"
                                                :class="String(g.group_id) === filters.group_id ? 'border-gray-400 dark:border-gray-500' : 'border-gray-200 dark:border-gray-700'"
                                                :title="tryTitle(g)"
                                            >
                                                <span class="max-w-[140px] truncate font-medium text-gray-700 dark:text-gray-200" :class="g.archived && 'line-through decoration-gray-400'" x-text="g.title"></span>
                                                <span class="shrink-0 rounded px-1 py-px text-[10.5px] font-semibold" :class="statusTone(g.status)" x-text="statusLabel(g.status)"></span>
                                            </span>
                                        </template>
                                    </div>
                                </td>

                                <td class="dc-acts">
                                    <a :href="telegramUrl(row)" target="_blank" rel="noopener" class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-gray-400 transition hover:border-gray-200 hover:bg-white hover:text-brand-600 dark:hover:border-gray-700 dark:hover:bg-white/[0.05]" title="{{ __('driver_check.invites.people.open_telegram') }}" aria-label="{{ __('driver_check.invites.people.open_telegram') }}">
                                        <x-driver-check.icon name="send" class="h-4 w-4" />
                                    </a>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </x-driver-check.table>
            </div>

            <x-slot:foot>
                <span>
                    <span class="font-semibold tabular-nums text-gray-950 dark:text-white" x-text="total === null ? '…' : number(total)"></span>
                    <span x-text="t.people.found"></span>
                    <span x-show="group" x-cloak x-text="t.people.in_group.replace(':group', group?.title ?? '')"></span>
                    <span class="text-gray-400" x-show="rows.length > 0" x-text="' · ' + t.people.shown.replace(':n', number(rows.length))"></span>
                </span>
                <x-driver-check.button size="sm" x-show="hasMore" x-cloak x-on:click="load(true)" ::disabled="loading">
                    {{ __('driver_check.list.more') }}
                </x-driver-check.button>
            </x-slot:foot>
        </x-driver-check.block>
    </x-driver-check.shell>
</div>

@endsection
