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

        @include('pages.driver-check.partials.invites-nav')

        {{-- What the run does this moment --}}
        <x-driver-check.surface x-show="running" x-cloak class="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:items-center sm:gap-4">
            <span class="dc-dot shrink-0 animate-pulse text-success-500"></span>
            <p class="text-[14px] font-semibold text-gray-950 dark:text-white" x-text="doingLabel()"></p>
            <p class="text-[12.5px] text-gray-500" x-text="[whereLabel(), untilLabel()].filter(Boolean).join(' · ')"></p>
            <p class="text-[12px] tabular-nums text-gray-400 sm:ml-auto" x-text="clockLine()"></p>
        </x-driver-check.surface>

        <x-driver-check.surface class="overflow-hidden">
            <div class="flex flex-col gap-3 border-b border-gray-100 p-4 lg:flex-row lg:items-center lg:justify-between dark:border-gray-800">
                <div class="min-w-0 lg:max-w-md lg:flex-1">
                    <x-driver-check.select x-model="runId" x-on:change="pick()" x-show="runs.length > 0" x-cloak>
                        <template x-for="run in runs" :key="run.run_id">
                            <option :value="run.run_id" x-text="runLabel(run)" :selected="run.run_id === runId"></option>
                        </template>
                    </x-driver-check.select>
                    <p class="mt-1.5 truncate text-[12px] text-gray-500" x-text="runSummary(current())"></p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <span x-show="running && !picked" x-cloak class="text-[11.5px] text-gray-400" x-text="t.log.live"></span>
                    <x-driver-check.chips items="filters()" selected="filter" action="setFilter" />
                </div>
            </div>

            <p x-show="!loading && shown().length === 0" x-cloak class="px-4 py-14 text-center text-[13px] text-gray-500">{{ __('driver_check.invites.log.empty') }}</p>

            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                <template x-for="line in shown()" :key="line.id">
                    <li class="grid grid-cols-[62px_14px_minmax(0,1fr)] items-start gap-x-3 px-4 py-2 sm:grid-cols-[62px_14px_minmax(0,1fr)_minmax(0,240px)]">
                        <span class="mt-0.5 text-[12px] tabular-nums text-gray-400" x-text="clock(line.created_at)"></span>
                        <span class="dc-dot mt-1.5" :class="toneClass(lineTone(line))"></span>
                        <p class="text-[13px] text-gray-900 dark:text-gray-100" x-text="lineText(line)"></p>
                        <p class="col-start-3 truncate text-[11.5px] text-gray-400 sm:col-start-auto sm:text-right" x-text="[phone(line.account), line.group].filter(Boolean).join(' · ')"></p>
                    </li>
                </template>
            </ul>
        </x-driver-check.surface>
    </x-driver-check.shell>
</div>

@endsection
