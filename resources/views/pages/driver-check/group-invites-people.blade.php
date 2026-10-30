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
                    x-on:click="load()"
                    ::disabled="loading"
                    :title="__('telegram.ui.refresh')"
                    :aria-label="__('telegram.ui.refresh')"
                >
                    <x-driver-check.icon name="refresh" class="h-4 w-4" ::class="loading && 'animate-spin'" />
                </x-driver-check.button>
            </x-slot:actions>
        </x-driver-check.page-header>

        @include('pages.driver-check.partials.invites-nav')

        <x-driver-check.error-alert :title="__('driver_check.invites.load_failed')" retry="load()" />

        <x-driver-check.surface class="overflow-hidden">

            {{-- Where people stand; then which group, which status, who --}}
            <div class="flex flex-col gap-3 border-b border-gray-100 p-4 dark:border-gray-800">
                <x-driver-check.chips items="flagChips()" selected="filters.flag" action="setFlag" />

                <div x-show="filters.flag === 'untried'" x-cloak>
                    <x-driver-check.chips items="reachChips()" selected="filters.reach" action="setReach" />
                    <p class="mt-1.5 text-[11.5px] text-gray-400">{{ __('driver_check.invites.people.reach_hint') }}</p>
                </div>

                <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_220px_200px]">
                    <x-driver-check.input x-model="filters.search" x-on:input.debounce.450ms="load()" :placeholder="__('driver_check.invites.people.search')" />

                    <x-driver-check.select x-model="filters.group_id" x-on:change="filters.status = filters.group_id ? filters.status : ''; load()">
                        <option value="">{{ __('driver_check.invites.people.any_group') }}</option>
                        <template x-for="g in groups" :key="g.id">
                            <option :value="String(g.id)" x-text="g.title + (g.archived ? ' · ' + t.people.archived : '')" :selected="String(g.id) === filters.group_id"></option>
                        </template>
                    </x-driver-check.select>

                    <x-driver-check.select x-model="filters.status" x-on:change="load()">
                        <option value="">{{ __('driver_check.invites.people.any_status') }}</option>
                        @foreach (['added', 'invited', 'left', 'not_added', 'failed', 'already', 'sent'] as $status)
                            <option value="{{ $status }}">{{ __("driver_check.invites.statuses.{$status}") }}</option>
                        @endforeach
                    </x-driver-check.select>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-[13px] text-gray-500">
                        <span class="font-semibold tabular-nums text-gray-950 dark:text-white" x-text="number(meta?.total ?? 0)"></span>
                        <span x-text="t.people.found"></span>
                        <span x-show="group" x-cloak x-text="t.people.in_group.replace(':group', group?.title ?? '')"></span>
                        <button type="button" x-show="filtered()" x-cloak class="ml-2 font-medium text-gray-600 underline-offset-2 hover:underline dark:text-gray-300" x-on:click="reset()">{{ __('driver_check.invites.people.clear') }}</button>
                    </p>

                    <x-driver-check.button size="sm" icon="refresh" x-show="filters.flag === 'cannot' && (counts?.cannot ?? 0) > 0" x-cloak x-on:click="resetRefused()" ::disabled="busy">
                        {{ __('driver_check.invites.people.reset') }}
                    </x-driver-check.button>
                </div>
            </div>

            <div x-show="loading && rows.length === 0" class="space-y-2 p-4">
                <template x-for="i in 6" :key="'sk-' + i">
                    <div class="dc-skeleton h-14 rounded-xl bg-gray-100 dark:bg-white/[0.06]"></div>
                </template>
            </div>

            <p x-show="!loading && rows.length === 0" x-cloak class="px-4 py-14 text-center text-[13px] text-gray-500">
                {{ __('driver_check.invites.people.empty') }}
            </p>

            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                <template x-for="row in rows" :key="row.id">
                    <li class="grid gap-2 px-4 py-3 md:grid-cols-[minmax(0,2fr)_minmax(0,2fr)_minmax(0,3fr)] md:items-start md:gap-5">
                        {{-- Who --}}
                        <div class="min-w-0">
                            <p class="truncate text-[13.5px] font-medium text-gray-950 dark:text-white" x-text="row.name || (row.username ? '@' + row.username : 'ID ' + row.telegram_user_id)"></p>
                            <p class="truncate text-[12px] text-gray-500">
                                <a :href="telegramUrl(row)" target="_blank" rel="noopener" class="hover:underline" x-text="row.username ? '@' + row.username : 'ID ' + row.telegram_user_id"></a>
                                <span x-show="row.phone" x-cloak class="tabular-nums" x-text="' · ' + phone(row.phone)"></span>
                            </p>
                        </div>

                        {{-- Where they stand, and why --}}
                        <div class="min-w-0">
                            <x-driver-check.badge x-bind:class="flagTone(row.flag)">
                                <span x-text="flagLabel(row.flag)"></span>
                            </x-driver-check.badge>
                            <p x-show="reasonLine(row)" x-cloak class="mt-1 text-[12px] leading-snug text-gray-500" x-text="reasonLine(row)"></p>
                        </div>

                        {{-- What became of them in each of our groups --}}
                        <div class="flex min-w-0 flex-wrap gap-1.5">
                            <span x-show="row.groups.length === 0" class="text-[12px] text-gray-400">{{ __('driver_check.invites.people.no_tries') }}</span>
                            <template x-for="g in tries(row)" :key="g.group_id">
                                <span
                                    class="inline-flex max-w-full items-center gap-1.5 rounded-md border px-2 py-0.5 text-[11.5px]"
                                    :class="String(g.group_id) === filters.group_id ? 'border-gray-400 dark:border-gray-500' : 'border-gray-200 dark:border-gray-700'"
                                    :title="tryTitle(g)"
                                >
                                    <span class="truncate font-medium text-gray-700 dark:text-gray-200" :class="g.archived && 'line-through decoration-gray-400'" x-text="g.title"></span>
                                    <span class="shrink-0 rounded px-1 py-px text-[10.5px] font-semibold" :class="statusTone(g.status)" x-text="statusLabel(g.status)"></span>
                                </span>
                            </template>
                        </div>
                    </li>
                </template>
            </ul>

            <div x-show="hasMore" x-cloak class="border-t border-gray-100 px-4 py-3 text-center dark:border-gray-800">
                <x-driver-check.button size="sm" x-on:click="load(true)" ::disabled="loading">
                    {{ __('driver_check.list.more') }}
                </x-driver-check.button>
            </div>
        </x-driver-check.surface>
    </x-driver-check.shell>
</div>

@endsection
