@extends('layouts.driver-check')

@section('title', __('driver_check.menu.group_invites_analysis'))

@section('content')

<div
    x-data="dcGroupInvitesAnalysis({
        endpoints: {
            show: @js(route('api.telegram.group-invites.analysis')),
            export: @js(route('api.telegram.group-invites.analysis.export')),
        },
        translations: @js(__('driver_check.invites')),
    })"
>
    <x-driver-check.shell>

        <x-driver-check.page-header
            :title="__('driver_check.menu.group_invites_analysis')"
            :description="__('driver_check.invites.analysis.description')"
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
                <x-driver-check.button href="#" x-bind:href="exportUrl('stops')" icon="download">
                    {{ __('driver_check.invites.analysis.export_stops') }}
                </x-driver-check.button>
                <x-driver-check.button href="#" x-bind:href="exportUrl('days')" icon="download">
                    {{ __('driver_check.invites.analysis.export_days') }}
                </x-driver-check.button>
            </x-slot:actions>
        </x-driver-check.page-header>

        @include('pages.driver-check.partials.invites-nav')

        <x-driver-check.error-alert :title="__('driver_check.invites.load_failed')" retry="load()" />

        <x-driver-check.chips items="periods()" selected="days" action="setPeriod" />

        <div x-show="!data && loading" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <template x-for="i in 8" :key="'sk-' + i">
                <div class="dc-skeleton h-24 rounded-2xl bg-gray-100 dark:bg-white/[0.06]"></div>
            </template>
        </div>

        <template x-if="data">
            <div class="flex flex-col gap-5">

                {{-- The period in numbers --}}
                <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <x-driver-check.stat-card :label="__('driver_check.invites.analysis.totals.added')">
                        <span x-text="number(data.totals.added)"></span>
                        <x-slot:hint><span x-text="t.analysis.totals.left_hint.replace(':n', number(data.totals.left))"></span></x-slot:hint>
                    </x-driver-check.stat-card>
                    <x-driver-check.stat-card :label="__('driver_check.invites.analysis.totals.not_added')">
                        <span x-text="number(data.totals.not_added)"></span>
                        <x-slot:hint><span x-text="t.analysis.totals.failed_hint.replace(':n', number(data.totals.failed))"></span></x-slot:hint>
                    </x-driver-check.stat-card>
                    <x-driver-check.stat-card :label="__('driver_check.invites.analysis.totals.calls')">
                        <span x-text="number(data.totals.calls)"></span>
                        <x-slot:hint><span x-text="t.analysis.totals.lookups_hint.replace(':n', number(data.totals.lookups))"></span></x-slot:hint>
                    </x-driver-check.stat-card>
                    <x-driver-check.stat-card :label="__('driver_check.invites.analysis.totals.stops')">
                        <span x-text="number(data.totals.stops)"></span>
                        <x-slot:hint><span x-text="t.analysis.totals.waits_hint.replace(':n', number(data.totals.waits))"></span></x-slot:hint>
                    </x-driver-check.stat-card>
                </section>

                {{-- Added, day by day: one series, its numbers on hover and in the "By day" table --}}
                <x-driver-check.surface class="overflow-hidden">
                    <div class="flex items-start justify-between gap-3 px-4 pt-4">
                        <div>
                            <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.analysis.trend_title') }}</h2>
                            <p class="mt-0.5 text-[12px] text-gray-400">{{ __('driver_check.invites.analysis.trend_hint') }}</p>
                        </div>
                        <p class="min-h-[2.5rem] text-right text-[12px] leading-snug tabular-nums text-gray-600 dark:text-gray-300">
                            <template x-if="hover">
                                <span>
                                    <span class="block font-semibold text-gray-950 dark:text-white" x-text="day(hover.day)"></span>
                                    <span x-text="hoverLine(hover)"></span>
                                </span>
                            </template>
                        </p>
                    </div>

                    <div class="relative px-4 pb-3 pt-4">
                        <div class="pointer-events-none absolute inset-x-4 top-4 flex items-center gap-2">
                            <span class="text-[10.5px] tabular-nums text-gray-400" x-text="number(trendMax())"></span>
                            <span class="h-px flex-1 border-t border-dashed border-gray-200 dark:border-gray-800"></span>
                        </div>

                        <div class="flex h-40 items-end gap-[2px] border-b border-gray-200 pt-5 dark:border-gray-700" x-on:mouseleave="hover = null">
                            <template x-for="(point, index) in trend()" :key="point.day">
                                <div
                                    class="group flex h-full min-w-0 flex-1 cursor-default items-end"
                                    x-on:mouseenter="hover = point"
                                    :aria-label="day(point.day) + ': ' + hoverLine(point)"
                                >
                                    <div
                                        class="w-full rounded-t-[4px] transition-colors"
                                        :class="hover?.day === point.day ? 'bg-brand-600' : 'bg-brand-500'"
                                        :style="`height: ${barHeight(point)}%`"
                                    ></div>
                                </div>
                            </template>
                        </div>

                        <div class="mt-1.5 flex gap-[2px]">
                            <template x-for="(point, index) in trend()" :key="'l-' + point.day">
                                <span class="min-w-0 flex-1 overflow-visible whitespace-nowrap text-center text-[10.5px] tabular-nums text-gray-400" x-text="showsLabel(index) ? shortDay(point.day) : ''"></span>
                            </template>
                        </div>
                    </div>
                </x-driver-check.surface>

                {{-- Why the accounts were stopped --}}
                <section>
                    <div class="mb-3">
                        <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.analysis.reasons_title') }}</h2>
                        <p class="mt-0.5 text-[12px] text-gray-400">{{ __('driver_check.invites.analysis.reasons_hint') }}</p>
                    </div>

                    <p x-show="data.reasons.length === 0" x-cloak class="rounded-2xl border border-dashed border-gray-200 px-4 py-8 text-center text-[13px] text-gray-500 dark:border-gray-800">
                        {{ __('driver_check.invites.analysis.no_stops') }}
                    </p>

                    <div class="grid gap-3 lg:grid-cols-2">
                        <template x-for="reason in data.reasons" :key="reason.kind">
                            <x-driver-check.surface class="p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-[14px] font-semibold" :class="kindTone(reason.kind)" x-text="kindLabel(reason.kind)"></p>
                                        <p class="mt-0.5 text-[12px] leading-snug text-gray-400" x-text="kindHint(reason.kind)"></p>
                                    </div>
                                    <p class="shrink-0 text-right">
                                        <span class="block text-[24px] font-semibold leading-none tabular-nums text-gray-950 dark:text-white" x-text="number(reason.stops)"></span>
                                        <span class="text-[11.5px] text-gray-400" x-text="t.analysis.on_accounts.replace(':n', reason.accounts)"></span>
                                    </p>
                                </div>

                                <dl class="mt-4 grid grid-cols-3 gap-3 rounded-xl bg-gray-25 p-3 text-center dark:bg-white/[0.02]">
                                    <div>
                                        <dt class="text-[10.5px] uppercase tracking-wide text-gray-400">{{ __('driver_check.invites.analysis.calls_median') }}</dt>
                                        <dd class="mt-0.5 text-[18px] font-semibold tabular-nums text-gray-950 dark:text-white" x-text="number(reason.calls_median)"></dd>
                                    </div>
                                    <div>
                                        <dt class="text-[10.5px] uppercase tracking-wide text-gray-400">{{ __('driver_check.invites.analysis.calls_avg') }}</dt>
                                        <dd class="mt-0.5 text-[18px] font-semibold tabular-nums text-gray-950 dark:text-white" x-text="number(reason.calls_avg)"></dd>
                                    </div>
                                    <div>
                                        <dt class="text-[10.5px] uppercase tracking-wide text-gray-400">{{ __('driver_check.invites.analysis.calls_range') }}</dt>
                                        <dd class="mt-0.5 text-[18px] font-semibold tabular-nums text-gray-950 dark:text-white" x-text="number(reason.calls_min) + '–' + number(reason.calls_max)"></dd>
                                    </div>
                                </dl>

                                <p class="mt-3 text-[12px] leading-relaxed tabular-nums text-gray-500" x-text="t.analysis.before_stop
                                    .replace(':invited', number(reason.invited_avg))
                                    .replace(':not_added', number(reason.not_added_avg))
                                    .replace(':lookups', number(reason.lookups_avg))
                                    .replace(':waits', number(reason.waits_avg))
                                    .replace(':minutes', number(reason.minutes_avg))
                                    .replace(':today', number(reason.calls_today_avg))"></p>
                                <p x-show="Object.keys(reason.rpcs || {}).length > 1" x-cloak class="mt-1 text-[11.5px] text-gray-400" x-text="rpcLine(reason.rpcs)"></p>
                            </x-driver-check.surface>
                        </template>
                    </div>
                </section>

                {{-- The details --}}
                <x-driver-check.surface class="overflow-hidden">
                    <div class="flex flex-col gap-2 border-b border-gray-100 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
                        <x-driver-check.chips items="tabs()" selected="tab" action="setTab" />
                        <x-driver-check.select x-show="tab === 'days' && accounts().length > 1" x-cloak x-model="account" class="sm:w-56">
                            <option value="">{{ __('driver_check.invites.analysis.all_accounts') }}</option>
                            <template x-for="phoneNumber in accounts()" :key="phoneNumber">
                                <option :value="phoneNumber" x-text="phone(phoneNumber)"></option>
                            </template>
                        </x-driver-check.select>
                    </div>

                    {{-- Every stop --}}
                    <div x-show="tab === 'stops'" class="overflow-x-auto">
                        <p x-show="data.stops.length === 0" x-cloak class="px-4 py-10 text-center text-[13px] text-gray-500">{{ __('driver_check.invites.analysis.no_stops') }}</p>
                        <table x-show="data.stops.length > 0" class="w-full min-w-[980px] text-left text-[12.5px]">
                            <thead class="bg-gray-25 text-[10.5px] uppercase tracking-wide text-gray-400 dark:bg-white/[0.02]">
                                <tr>
                                    <th class="px-4 py-2 font-medium">{{ __('driver_check.invites.analysis.cols.at') }}</th>
                                    <th class="px-4 py-2 font-medium">{{ __('driver_check.invites.analysis.cols.account') }}</th>
                                    <th class="px-4 py-2 font-medium">{{ __('driver_check.invites.analysis.cols.reason') }}</th>
                                    <th class="px-4 py-2 font-medium">{{ __('driver_check.invites.analysis.cols.what') }}</th>
                                    <th class="px-4 py-2 text-right font-medium">{{ __('driver_check.invites.analysis.cols.calls') }}</th>
                                    <th class="px-4 py-2 text-right font-medium">{{ __('driver_check.invites.analysis.cols.invited') }}</th>
                                    <th class="px-4 py-2 text-right font-medium">{{ __('driver_check.invites.analysis.cols.lookups') }}</th>
                                    <th class="px-4 py-2 text-right font-medium">{{ __('driver_check.invites.analysis.cols.today') }}</th>
                                    <th class="px-4 py-2 text-right font-medium">{{ __('driver_check.invites.analysis.cols.floods') }}</th>
                                    <th class="px-4 py-2 font-medium">{{ __('driver_check.invites.analysis.cols.until') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 tabular-nums dark:divide-gray-800">
                                <template x-for="stop in data.stops" :key="stop.id">
                                    <tr>
                                        <td class="whitespace-nowrap px-4 py-2 text-gray-500" x-text="time(stop.at)"></td>
                                        <td class="whitespace-nowrap px-4 py-2 font-medium text-gray-950 dark:text-white" x-text="phone(stop.account)"></td>
                                        <td class="px-4 py-2">
                                            <span class="font-medium" :class="kindTone(stop.kind)" x-text="kindLabel(stop.kind)"></span>
                                            <span class="block text-[11.5px] text-gray-400" x-text="errorLabel(stop.rpc)"></span>
                                        </td>
                                        <td class="px-4 py-2 text-gray-500" x-text="stopWhat(stop)"></td>
                                        <td class="px-4 py-2 text-right font-semibold text-gray-950 dark:text-white" x-text="number(stop.counters.calls ?? 0)"></td>
                                        <td class="px-4 py-2 text-right" x-text="number(stop.counters.invited ?? 0)"></td>
                                        <td class="px-4 py-2 text-right" x-text="number(stop.counters.lookups ?? 0)"></td>
                                        <td class="px-4 py-2 text-right" x-text="number(stop.counters.calls_today ?? 0)"></td>
                                        <td class="px-4 py-2 text-right" x-text="number(stop.counters.peer_floods_before ?? 0)"></td>
                                        <td class="whitespace-nowrap px-4 py-2 text-gray-500" x-text="stop.until ? time(stop.until) : '—'"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    {{-- A row per day per account --}}
                    <div x-show="tab === 'days'" x-cloak class="overflow-x-auto">
                        <p x-show="dayRows().length === 0" x-cloak class="px-4 py-10 text-center text-[13px] text-gray-500">{{ __('driver_check.invites.analysis.no_days') }}</p>
                        <table x-show="dayRows().length > 0" class="w-full min-w-[820px] text-left text-[12.5px]">
                            <thead class="bg-gray-25 text-[10.5px] uppercase tracking-wide text-gray-400 dark:bg-white/[0.02]">
                                <tr>
                                    <th class="px-4 py-2 font-medium">{{ __('driver_check.invites.analysis.cols.day') }}</th>
                                    <th class="px-4 py-2 font-medium">{{ __('driver_check.invites.analysis.cols.account') }}</th>
                                    @foreach (['calls', 'added', 'not_added', 'failed', 'already', 'lookups', 'waits', 'stops', 'left'] as $col)
                                        <th class="px-4 py-2 text-right font-medium">{{ __("driver_check.invites.analysis.cols.{$col}") }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 tabular-nums dark:divide-gray-800">
                                <template x-for="row in dayRows()" :key="row.day + '-' + row.account_id">
                                    <tr>
                                        <td class="whitespace-nowrap px-4 py-2 text-gray-500" x-text="day(row.day)"></td>
                                        <td class="whitespace-nowrap px-4 py-2 font-medium text-gray-950 dark:text-white" x-text="row.account ? phone(row.account) : '—'"></td>
                                        <td class="px-4 py-2 text-right" x-text="number(row.calls)"></td>
                                        <td class="px-4 py-2 text-right font-semibold text-gray-950 dark:text-white" x-text="number(row.added)"></td>
                                        <td class="px-4 py-2 text-right" x-text="number(row.not_added)"></td>
                                        <td class="px-4 py-2 text-right" x-text="number(row.failed)"></td>
                                        <td class="px-4 py-2 text-right" x-text="number(row.already)"></td>
                                        <td class="px-4 py-2 text-right" x-text="number(row.lookups)"></td>
                                        <td class="px-4 py-2 text-right" x-text="number(row.waits)"></td>
                                        <td class="px-4 py-2 text-right" :class="row.stops > 0 && 'font-semibold text-error-600 dark:text-error-400'" x-text="number(row.stops)"></td>
                                        <td class="px-4 py-2 text-right" x-text="number(row.left)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    {{-- What each group got and lost --}}
                    <div x-show="tab === 'groups'" x-cloak class="overflow-x-auto">
                        <p x-show="data.groups.length === 0" x-cloak class="px-4 py-10 text-center text-[13px] text-gray-500">{{ __('driver_check.invites.analysis.no_days') }}</p>
                        <table x-show="data.groups.length > 0" class="w-full min-w-[720px] text-left text-[12.5px]">
                            <thead class="bg-gray-25 text-[10.5px] uppercase tracking-wide text-gray-400 dark:bg-white/[0.02]">
                                <tr>
                                    <th class="px-4 py-2 font-medium">{{ __('driver_check.invites.analysis.cols.group') }}</th>
                                    @foreach (['added', 'left', 'not_added', 'failed', 'already', 'members'] as $col)
                                        <th class="px-4 py-2 text-right font-medium">{{ __("driver_check.invites.analysis.cols.{$col}") }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 tabular-nums dark:divide-gray-800">
                                <template x-for="group in data.groups" :key="group.id">
                                    <tr>
                                        <td class="px-4 py-2 font-medium text-gray-950 dark:text-white">
                                            <span x-text="group.title"></span>
                                            <span x-show="group.archived" x-cloak class="ml-1 text-[11px] font-normal text-gray-400" x-text="t.people.archived"></span>
                                        </td>
                                        <td class="px-4 py-2 text-right font-semibold text-gray-950 dark:text-white" x-text="number(group.added)"></td>
                                        <td class="px-4 py-2 text-right">
                                            <span x-text="number(group.left)"></span>
                                            <span x-show="group.added > 0 && group.left > 0" x-cloak class="text-[11px] text-gray-400" x-text="' · ' + percent(group.left, group.added) + '%'"></span>
                                        </td>
                                        <td class="px-4 py-2 text-right" x-text="number(group.not_added)"></td>
                                        <td class="px-4 py-2 text-right" x-text="number(group.failed)"></td>
                                        <td class="px-4 py-2 text-right" x-text="number(group.already)"></td>
                                        <td class="px-4 py-2 text-right text-gray-500" x-text="(group.members_before ?? '?') + ' → ' + (group.members_after ?? '?')"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </x-driver-check.surface>
            </div>
        </template>
    </x-driver-check.shell>
</div>

@endsection
