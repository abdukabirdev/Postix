@extends('layouts.driver-check')

@section('title', __('driver_check.menu.group_invites_log'))

@section('content')

<div
    x-data="dcGroupInvitesLog({
        endpoints: {
            logs: @js(route('api.telegram.group-invites.logs')),
            show: @js(route('api.telegram.group-invites.show')),
        },
        translations: @js(__('driver_check.invites')),
    })"
>
    <x-driver-check.shell>

        <x-driver-check.page-header
            :title="__('driver_check.menu.group_invites_log')"
            :description="__('driver_check.invites.log.description')"
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

        {{-- What the run does this moment --}}
        <div x-show="running" x-cloak class="dc-state" data-tone="run">
            <div class="dc-state__text">
                <p class="dc-state__title flex items-center gap-2"><span class="dc-dot animate-pulse text-success-500"></span><span x-text="doingLabel()"></span></p>
                <p class="dc-state__line" x-text="[whereLabel(), untilLabel()].filter(Boolean).join(' · ')"></p>
            </div>
            <p class="text-[12px] tabular-nums text-gray-400" x-text="clockLine()"></p>
        </div>

        <x-driver-check.block>
            <div class="dc-toolbar">
                <div class="min-w-[240px] max-w-[420px] flex-1" x-show="runs.length > 0" x-cloak>
                    <x-driver-check.select x-model="runId" x-on:change="pick()">
                        <template x-for="run in runs" :key="run.run_id">
                            <option :value="run.run_id" x-text="runLabel(run)" :selected="run.run_id === runId"></option>
                        </template>
                    </x-driver-check.select>
                </div>

                <x-driver-check.chips items="filters()" selected="filter" action="setFilter" />

                <div class="dc-toolbar__grow !min-w-[170px] !flex-none">
                    <x-driver-check.search model="search" :placeholder="__('driver_check.invites.log.search')" debounce="150ms" />
                </div>

                <div x-show="accountsInLog().length > 1" x-cloak>
                    <x-driver-check.select x-model="account" class="!w-auto" aria-label="{{ __('driver_check.invites.log.all_accounts') }}">
                        <option value="">{{ __('driver_check.invites.log.all_accounts') }}</option>
                        <template x-for="phone in accountsInLog()" :key="phone">
                            <option :value="phone" x-text="'+' + phone"></option>
                        </template>
                    </x-driver-check.select>
                </div>

                <div class="dc-toolbar__end">
                    <span x-show="running && !picked" x-cloak class="inline-flex items-center gap-1.5 text-[11.5px] text-gray-400"><span class="dc-live"></span><span x-text="t.log.live"></span></span>
                    <x-driver-check.button size="sm" variant="ghost" icon="close" x-show="hasFilters()" x-cloak x-on:click="resetFilters()">{{ __('telegram.ui.reset') }}</x-driver-check.button>
                    <x-driver-check.select x-model.number="limit" x-on:change="changeLimit()" class="!w-auto" aria-label="{{ __('driver_check.invites.log.limit') }}" title="{{ __('driver_check.invites.log.limit') }}">
                        @foreach ([100, 400, 1000] as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </x-driver-check.select>
                </div>
            </div>

            <p class="border-b border-gray-100 px-4 py-2 text-[12px] text-gray-500 dark:border-gray-800" x-show="runSummary(current())" x-text="runSummary(current())"></p>

            <div x-show="loading && lines.length === 0" class="dc-block__wait" aria-hidden="true">
                @foreach ([72, 54, 86, 40, 64, 78] as $width)
                    <div class="dc-skeleton h-3 rounded bg-gray-100 dark:bg-white/[0.06]" style="width: {{ $width }}%"></div>
                @endforeach
            </div>

            <p x-show="!loading && shown().length === 0" x-cloak class="dc-none">{{ __('driver_check.invites.log.empty') }}</p>

            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                <template x-for="line in shown()" :key="line.id">
                    <li class="grid grid-cols-[62px_14px_minmax(0,1fr)] items-start gap-x-3 px-4 py-2 hover:bg-gray-25 sm:grid-cols-[62px_14px_minmax(0,1fr)_minmax(0,240px)] dark:hover:bg-white/[0.02]">
                        <span class="mt-0.5 text-[12px] tabular-nums text-gray-400" x-text="clock(line.created_at)"></span>
                        <span class="dc-dot mt-1.5" :class="toneClass(lineTone(line))"></span>
                        <p class="text-[13px] text-gray-900 dark:text-gray-100" x-text="lineText(line)"></p>
                        <p class="col-start-3 truncate text-[11.5px] text-gray-400 sm:col-start-auto sm:text-right" x-text="[phone(line.account), line.group].filter(Boolean).join(' · ')"></p>
                    </li>
                </template>
            </ul>

            <x-slot:foot>
                <span x-text="t.log.shown_of.replace(':shown', number(shown().length)).replace(':all', number(lines.length))"></span>
            </x-slot:foot>
        </x-driver-check.block>
    </x-driver-check.shell>
</div>

@endsection
