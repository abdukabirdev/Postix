@extends('layouts.driver-check')

@section('title', __('driver_check.report.title'))

@section('content')

<div
    x-data="dcContactReport({
        endpoints: {
            report: @js(route('api.telegram.contact-sync.report') . '?days=14'),
            runs: @js(route('api.telegram.contact-sync.runs')),
            run: @js(route('api.telegram.contact-sync.run')),
            stop: @js(route('api.telegram.contact-sync.stop')),
            logs: @js(route('api.telegram.contact-sync.logs')),
        },
        translations: @js(__('driver_check.report')),
        events: @js(__('telegram.contact_sync.events')),
        ui: @js(__('telegram.ui')),
    })"
>
    <x-driver-check.shell>

        {{-- Header: refresh, what to run, then the one primary - run it again --}}
        <x-driver-check.page-header
            :title="__('driver_check.report.title')"
            :description="__('driver_check.report.description')"
        >
            <x-slot:actions>
                <x-driver-check.button
                    size="icon"
                    x-on:click="loadReport(); load()"
                    ::disabled="reportLoading"
                    :title="__('telegram.ui.refresh')"
                    :aria-label="__('telegram.ui.refresh')"
                >
                    <x-driver-check.icon name="refresh" class="h-4 w-4" ::class="reportLoading && 'animate-spin'" />
                </x-driver-check.button>

                <div x-show="groups.length > 1" x-cloak>
                    <x-driver-check.select
                        x-model="scope"
                        class="w-44"
                        :aria-label="__('driver_check.report.scope_label')"
                        ::disabled="busy"
                    >
                        <option value="">{{ __('driver_check.report.scope_all') }}</option>
                        <template x-for="group in groups" :key="group.id">
                            <option :value="group.id" x-text="groupName(group)"></option>
                        </template>
                    </x-driver-check.select>
                </div>

                <x-driver-check.button variant="primary" x-on:click="run()" ::disabled="busy">
                    <x-driver-check.icon name="refresh" class="h-4 w-4 animate-spin" x-show="busy" x-cloak />
                    <x-driver-check.icon name="play" class="h-4 w-4" x-show="!busy" />
                    <span x-text="busy ? t.running : t.run_again"></span>
                </x-driver-check.button>

                <x-driver-check.button variant="danger" icon="stop" x-show="schedule?.running" x-cloak x-on:click="stop()" ::disabled="stopping">
                    <span x-text="stopping ? t.stopping : t.stop"></span>
                </x-driver-check.button>
            </x-slot:actions>
        </x-driver-check.page-header>

        <x-driver-check.error-alert :title="__('driver_check.report.error')" model="reportError" retry="loadReport()" />

        <template x-if="report">
            <div class="flex flex-col gap-5">

                {{-- The state of the daily run: the one line that says "fine" or "look here" --}}
                <section class="flex items-start gap-3 rounded-2xl border p-4" :class="bannerClass()">
                    <span class="dc-dot mt-1.5" :class="dotClass()"></span>

                    <div class="min-w-0 flex-1">
                        <template x-if="schedule.overdue">
                            <div>
                                <p class="text-[15px] font-semibold text-error-700 dark:text-error-300">{{ __('driver_check.report.schedule.overdue_title') }}</p>
                                <p class="mt-0.5 text-[13px] text-error-600 dark:text-error-400" x-text="t.schedule.overdue_text.replace(':time', schedule.at)"></p>
                            </div>
                        </template>

                        <template x-if="!schedule.overdue">
                            <div>
                                <p class="text-[15px] font-semibold text-gray-950 dark:text-white" x-text="statusText()"></p>
                                <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">
                                    <template x-if="schedule.enabled">
                                        <span>
                                            <span x-text="t.schedule.daily_at.replace(':time', schedule.at)"></span>
                                            <span class="mx-1.5 text-gray-300">·</span>
                                            {{ __('driver_check.report.schedule.next') }}
                                            <span class="font-medium text-gray-700 dark:text-gray-200" x-text="date(schedule.next_run_at)"></span>
                                        </span>
                                    </template>
                                    <template x-if="!schedule.enabled">
                                        <span>{{ __('driver_check.sync.settings.enabled_hint') }}</span>
                                    </template>
                                </p>
                            </div>
                        </template>
                    </div>

                    <a href="{{ route('driver-check.contact-settings') }}" class="hidden shrink-0 text-[12.5px] font-medium text-gray-600 underline-offset-2 hover:text-gray-950 hover:underline sm:inline dark:text-gray-300 dark:hover:text-white">
                        {{ __('driver_check.sync.settings.title') }} →
                    </a>
                </section>

                <section class="grid grid-cols-2 gap-3 xl:grid-cols-4">
                    <x-driver-check.stat-card :label="__('driver_check.report.stats.added')" icon="check-circle">
                        <span x-text="number(today?.added)">0</span>
                    </x-driver-check.stat-card>

                    <x-driver-check.stat-card :label="__('driver_check.report.stats.failed')" icon="alert">
                        <span :class="today?.failed > 0 && 'text-error-600 dark:text-error-400'" x-text="number(today?.failed)">0</span>
                    </x-driver-check.stat-card>

                    <x-driver-check.stat-card :label="__('driver_check.report.stats.accounts')" icon="users">
                        <span x-text="schedule.last_result ? number(schedule.last_result.accounts) : dash">0</span>
                    </x-driver-check.stat-card>

                    <x-driver-check.stat-card :label="__('driver_check.report.stats.limit')" icon="shield">
                        <span x-text="number(schedule.daily_limit)">0</span>
                    </x-driver-check.stat-card>
                </section>

                <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">

                    {{-- History of runs / day by day --}}
                    <x-driver-check.surface class="overflow-hidden">
                        <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-2.5 dark:border-gray-800">
                            <div class="inline-flex gap-0.5 rounded-lg bg-gray-100 p-0.5 dark:bg-white/[0.06]" role="tablist">
                                <button type="button" role="tab" x-on:click="tab = 'runs'" :aria-selected="tab === 'runs'"
                                    :class="tab === 'runs' ? 'bg-white text-gray-950 shadow-sm dark:bg-gray-700 dark:text-white' : 'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white'"
                                    class="h-8 rounded-md px-3 text-[13px] font-medium transition sm:h-7">{{ __('driver_check.report.tabs.runs') }}</button>
                                <button type="button" role="tab" x-on:click="tab = 'days'" :aria-selected="tab === 'days'"
                                    :class="tab === 'days' ? 'bg-white text-gray-950 shadow-sm dark:bg-gray-700 dark:text-white' : 'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white'"
                                    class="h-8 rounded-md px-3 text-[13px] font-medium transition sm:h-7">{{ __('driver_check.report.tabs.days') }}</button>
                            </div>

                            <span x-show="tab === 'runs'" class="inline-flex h-5 items-center rounded bg-gray-100 px-1.5 text-[11px] font-bold tabular-nums text-gray-600 dark:bg-white/[0.07] dark:text-gray-300" x-text="number(pagination.total)">0</span>
                        </div>

                        {{-- ===== Runs ===== --}}
                        <div x-show="tab === 'runs'">
                            <div class="hidden md:block">
                                <div class="overflow-x-auto">
                                    <table class="w-full min-w-[860px] text-left">
                                        <thead class="dc-thead border-b border-gray-200 bg-gray-25 dark:border-gray-800 dark:bg-transparent">
                                            <tr class="text-[10.5px] uppercase tracking-[0.08em] text-gray-500 dark:text-gray-400">
                                                <th class="px-4 py-2.5 font-semibold">{{ __('driver_check.report.runs.started') }}</th>
                                                <th class="px-3 py-2.5 font-semibold">{{ __('driver_check.report.runs.status') }}</th>
                                                <th class="px-3 py-2.5 text-right font-semibold">{{ __('driver_check.report.runs.duration') }}</th>
                                                <th class="px-3 py-2.5 text-right font-semibold">{{ __('driver_check.report.runs.accounts') }}</th>
                                                <th class="px-3 py-2.5 text-right font-semibold">{{ __('driver_check.report.runs.members') }}</th>
                                                <th class="px-3 py-2.5 text-right font-semibold">{{ __('driver_check.report.runs.new') }}</th>
                                                <th class="px-3 py-2.5 text-right font-semibold">{{ __('driver_check.report.runs.added') }}</th>
                                                <th class="px-3 py-2.5 text-right font-semibold">{{ __('driver_check.report.runs.skipped') }}</th>
                                                <th class="px-3 py-2.5 text-right font-semibold">{{ __('driver_check.report.runs.failed') }}</th>
                                                <th class="w-10 px-3 py-2.5"><span class="sr-only">{{ __('driver_check.report.runs.open') }}</span></th>
                                            </tr>
                                        </thead>

                                        <tbody class="divide-y divide-gray-100 text-[13px] tabular-nums dark:divide-gray-800">
                                            <x-driver-check.skeleton-rows :cols="10" />

                                            <template x-for="row in rows" :key="row.run_id">
                                                <tr class="dc-row group cursor-pointer transition-colors hover:bg-gray-25 dark:hover:bg-white/[0.025]" x-on:click="openRun(row)">
                                                    <td class="px-4 py-3">
                                                        <p class="font-medium text-gray-900 dark:text-white" x-text="date(row.started_at)"></p>
                                                        <p class="text-[11.5px] text-gray-400" x-text="triggerLabel(row)"></p>
                                                    </td>
                                                    <td class="px-3 py-3">
                                                        <x-driver-check.badge ::class="runClass(row.status)">
                                                            <span class="h-1.5 w-1.5 rounded-full" :class="runDot(row.status)"></span>
                                                            <span x-text="runLabel(row.status)"></span>
                                                        </x-driver-check.badge>
                                                    </td>
                                                    <td class="px-3 py-3 text-right text-gray-600 dark:text-gray-300" x-text="duration(row.duration_seconds)"></td>
                                                    <td class="px-3 py-3 text-right font-medium text-gray-800 dark:text-gray-100" x-text="number(row.accounts)"></td>
                                                    <td class="px-3 py-3 text-right text-gray-600 dark:text-gray-300" x-text="number(row.members)"></td>
                                                    <td class="px-3 py-3 text-right font-medium text-success-700 dark:text-success-400" x-text="row.new === null ? dash : number(row.new)"></td>
                                                    <td class="px-3 py-3 text-right font-semibold text-gray-950 dark:text-white" x-text="number(row.added)"></td>
                                                    <td class="px-3 py-3 text-right text-gray-500" x-text="number(row.skipped)"></td>
                                                    <td class="px-3 py-3 text-right" :class="row.failed > 0 ? 'font-semibold text-error-600 dark:text-error-400' : 'text-gray-400'" x-text="number(row.failed)"></td>
                                                    <td class="px-3 py-3 text-right text-gray-300 group-hover:text-gray-500"><x-driver-check.icon name="chevron-right" class="h-4 w-4" /></td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            {{-- Cards below md --}}
                            <div class="divide-y divide-gray-100 md:hidden dark:divide-gray-800">
                                <x-driver-check.skeleton-cards />

                                <template x-for="row in rows" :key="'card-' + row.run_id">
                                    <button type="button" class="dc-tap flex w-full flex-col gap-2.5 p-3.5 text-left" x-on:click="openRun(row)">
                                        <div class="flex items-center justify-between gap-2">
                                            <div>
                                                <p class="text-sm font-semibold text-gray-900 dark:text-white" x-text="date(row.started_at)"></p>
                                                <p class="text-[11.5px] text-gray-400" x-text="triggerLabel(row)"></p>
                                            </div>
                                            <x-driver-check.badge ::class="runClass(row.status)">
                                                <span class="h-1.5 w-1.5 rounded-full" :class="runDot(row.status)"></span>
                                                <span x-text="runLabel(row.status)"></span>
                                            </x-driver-check.badge>
                                        </div>

                                        <dl class="grid grid-cols-5 gap-2 rounded-xl bg-gray-50 p-3 dark:bg-white/[0.02]">
                                            <x-driver-check.kv :label="__('driver_check.report.runs.accounts')"><span class="font-medium tabular-nums" x-text="number(row.accounts)"></span></x-driver-check.kv>
                                            <x-driver-check.kv :label="__('driver_check.report.runs.new')"><span class="font-medium tabular-nums text-success-700 dark:text-success-400" x-text="row.new === null ? dash : number(row.new)"></span></x-driver-check.kv>
                                            <x-driver-check.kv :label="__('driver_check.report.runs.added')"><span class="font-semibold tabular-nums" x-text="number(row.added)"></span></x-driver-check.kv>
                                            <x-driver-check.kv :label="__('driver_check.report.runs.skipped')"><span class="tabular-nums" x-text="number(row.skipped)"></span></x-driver-check.kv>
                                            <x-driver-check.kv :label="__('driver_check.report.runs.failed')"><span class="tabular-nums" :class="row.failed > 0 && 'font-semibold text-error-600'" x-text="number(row.failed)"></span></x-driver-check.kv>
                                        </dl>
                                    </button>
                                </template>
                            </div>

                            <x-driver-check.empty-state
                                icon="sync"
                                :title="__('driver_check.report.runs.empty_title')"
                                :description="__('driver_check.report.runs.empty_text')"
                            />

                            <x-driver-check.pagination />
                        </div>

                        {{-- ===== Day by day: a day without a run shows as a hole ===== --}}
                        <div x-show="tab === 'days'" x-cloak class="overflow-x-auto">
                            <table class="w-full min-w-[720px] text-left">
                                <thead class="dc-thead border-b border-gray-200 bg-gray-25 dark:border-gray-800 dark:bg-transparent">
                                    <tr class="text-[10.5px] uppercase tracking-[0.08em] text-gray-500 dark:text-gray-400">
                                        <th class="px-4 py-2.5 font-semibold">{{ __('driver_check.report.days.date') }}</th>
                                        <th class="px-3 py-2.5 text-right font-semibold">{{ __('driver_check.report.days.runs') }}</th>
                                        <th class="px-3 py-2.5 text-right font-semibold">{{ __('driver_check.report.days.accounts') }}</th>
                                        <th class="px-3 py-2.5 text-right font-semibold">{{ __('driver_check.report.days.members') }}</th>
                                        <th class="px-3 py-2.5 text-right font-semibold">{{ __('driver_check.report.days.found') }}</th>
                                        <th class="px-3 py-2.5 text-right font-semibold">{{ __('driver_check.report.days.added') }}</th>
                                        <th class="px-3 py-2.5 text-right font-semibold">{{ __('driver_check.report.days.skipped') }}</th>
                                        <th class="px-3 py-2.5 text-right font-semibold">{{ __('driver_check.report.days.failed') }}</th>
                                        <th class="px-4 py-2.5 text-right font-semibold">{{ __('driver_check.report.days.flood') }}</th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-gray-100 text-[13px] tabular-nums dark:divide-gray-800">
                                    <template x-for="(day, index) in days" :key="day.date">
                                        <tr class="transition-colors hover:bg-gray-25 dark:hover:bg-white/[0.025]">
                                            <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white" x-text="dayLabel(day, index)"></td>

                                            <template x-if="isHole(day, index)">
                                                <td colspan="8" class="px-3 py-2.5 text-right">
                                                    <span class="inline-flex items-center gap-1.5 text-[12px] font-medium text-warning-700 dark:text-warning-400">
                                                        <span class="h-1.5 w-1.5 rounded-full bg-warning-500"></span>
                                                        {{ __('driver_check.report.days.no_run') }}
                                                    </span>
                                                </td>
                                            </template>

                                            <template x-if="!isHole(day, index)"><td class="px-3 py-2.5 text-right text-gray-600 dark:text-gray-300" x-text="number(day.runs)"></td></template>
                                            <template x-if="!isHole(day, index)"><td class="px-3 py-2.5 text-right text-gray-600 dark:text-gray-300" x-text="number(day.accounts)"></td></template>
                                            <template x-if="!isHole(day, index)"><td class="px-3 py-2.5 text-right text-gray-600 dark:text-gray-300" x-text="number(day.members)"></td></template>
                                            <template x-if="!isHole(day, index)"><td class="px-3 py-2.5 text-right text-gray-600 dark:text-gray-300" x-text="number(day.found)"></td></template>
                                            <template x-if="!isHole(day, index)"><td class="px-3 py-2.5 text-right font-semibold text-gray-950 dark:text-white" x-text="number(day.added)"></td></template>
                                            <template x-if="!isHole(day, index)"><td class="px-3 py-2.5 text-right text-gray-500" x-text="number(day.skipped)"></td></template>
                                            <template x-if="!isHole(day, index)"><td class="px-3 py-2.5 text-right" :class="day.failed > 0 ? 'font-semibold text-error-600 dark:text-error-400' : 'text-gray-400'" x-text="number(day.failed)"></td></template>
                                            <template x-if="!isHole(day, index)"><td class="px-4 py-2.5 text-right" :class="day.flood > 0 ? 'font-semibold text-warning-600 dark:text-warning-400' : 'text-gray-400'" x-text="number(day.flood)"></td></template>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </x-driver-check.surface>

                    <div class="flex flex-col gap-5">

                        {{-- The last run, in words --}}
                        <x-driver-check.surface class="overflow-hidden">
                            <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.report.schedule.title') }}</h2>
                            </div>

                            <dl class="divide-y divide-gray-100 text-[13px] dark:divide-gray-800">
                                <div class="flex items-center justify-between gap-4 px-4 py-2.5">
                                    <dt class="text-gray-500">{{ __('driver_check.report.schedule.last_start') }}</dt>
                                    <dd class="text-right font-medium tabular-nums text-gray-800 dark:text-gray-100" :title="date(schedule.last_started_at)" x-text="schedule.last_started_at ? relative(schedule.last_started_at) : t.schedule.never"></dd>
                                </div>
                                <div class="flex items-center justify-between gap-4 px-4 py-2.5">
                                    <dt class="text-gray-500">{{ __('driver_check.report.schedule.last_end') }}</dt>
                                    <dd class="text-right font-medium tabular-nums text-gray-800 dark:text-gray-100" x-text="schedule.last_finished_at ? date(schedule.last_finished_at) : dash"></dd>
                                </div>
                                <div class="flex items-center justify-between gap-4 px-4 py-2.5">
                                    <dt class="text-gray-500">{{ __('driver_check.report.schedule.duration') }}</dt>
                                    <dd class="text-right font-medium tabular-nums text-gray-800 dark:text-gray-100" x-text="duration(schedule.last_duration_seconds)"></dd>
                                </div>
                            </dl>
                        </x-driver-check.surface>

                        {{-- Who added how many today --}}
                        <x-driver-check.surface class="overflow-hidden">
                            <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.report.accounts.title') }}</h2>
                            </div>

                            <p x-show="report.accounts_today.length === 0" x-cloak class="px-4 py-6 text-center text-[13px] text-gray-500">
                                {{ __('driver_check.report.accounts.empty') }}
                            </p>

                            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                                <template x-for="account in report.accounts_today" :key="account.id">
                                    <li class="flex items-center justify-between gap-3 px-4 py-2.5">
                                        <div class="min-w-0">
                                            <p class="truncate text-[13px] font-medium tabular-nums text-gray-900 dark:text-white" x-text="phone(account.phone)"></p>
                                            <p class="truncate text-[11.5px] text-gray-400" x-text="account.username ? handle(account.username) : ''"></p>
                                        </div>

                                        <div class="flex shrink-0 items-center gap-3 text-[13px] tabular-nums">
                                            <span class="font-semibold text-gray-950 dark:text-white" :title="t.accounts.added" x-text="number(account.added)"></span>
                                            <span x-show="account.failed > 0" x-cloak class="font-semibold text-error-600 dark:text-error-400" :title="t.accounts.failed" x-text="'−' + number(account.failed)"></span>
                                        </div>
                                    </li>
                                </template>
                            </ul>
                        </x-driver-check.surface>
                    </div>
                </div>
            </div>
        </template>
    </x-driver-check.shell>

    {{-- ================= One run ================= --}}
    <x-driver-check.modal open="detailOpen" close="closeRun()" size="sm:max-w-4xl">
        <x-slot:heading>
            <span x-text="detail ? date(detail.run.started_at) : t.detail.title"></span>
        </x-slot:heading>

        <div x-show="detailLoading" class="space-y-3">
            <div class="dc-skeleton h-16 rounded-lg bg-gray-100 dark:bg-white/[0.06]"></div>
            <div class="dc-skeleton h-32 rounded-lg bg-gray-100 dark:bg-white/[0.06]"></div>
        </div>

        <p x-show="detailError" x-cloak class="rounded-lg border border-error-200 bg-error-25 p-3 text-[13px] text-error-700" x-text="detailError"></p>

        <template x-if="detail">
            <div class="flex flex-col gap-5">

                {{-- The run at a glance --}}
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                        <p class="dc-eyebrow">{{ __('driver_check.report.runs.status') }}</p>
                        <x-driver-check.badge class="mt-1.5" ::class="runClass(detail.run.status)">
                            <span class="h-1.5 w-1.5 rounded-full" :class="runDot(detail.run.status)"></span>
                            <span x-text="runLabel(detail.run.status)"></span>
                        </x-driver-check.badge>
                        <p class="mt-1.5 text-[11.5px] text-gray-400" x-text="triggerLabel(detail.run) + ' · ' + duration(detail.run.duration_seconds)"></p>
                    </div>

                    <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                        <p class="dc-eyebrow">{{ __('driver_check.report.runs.accounts') }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-950 dark:text-white" x-text="number(detail.run.accounts)"></p>
                    </div>

                    <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                        <p class="dc-eyebrow">{{ __('driver_check.report.runs.new') }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-success-700 dark:text-success-400" x-text="detail.run.new === null ? dash : number(detail.run.new)"></p>
                        <p class="text-[11.5px] text-gray-400" x-text="t.runs.members + ': ' + number(detail.run.members)"></p>
                    </div>

                    <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                        <p class="dc-eyebrow">{{ __('driver_check.report.runs.added') }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-950 dark:text-white" x-text="number(detail.run.added)"></p>
                        <p class="text-[11.5px] text-gray-400" x-text="t.runs.skipped + ': ' + number(detail.run.skipped)"></p>
                    </div>

                    <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                        <p class="dc-eyebrow">{{ __('driver_check.report.runs.failed') }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums" :class="detail.run.failed > 0 ? 'text-error-600 dark:text-error-400' : 'text-gray-950 dark:text-white'" x-text="number(detail.run.failed)"></p>
                        <p class="text-[11.5px] text-gray-400" x-text="t.runs.held + ': ' + number(detail.run.held)"></p>
                    </div>
                </div>

                <p class="text-[12.5px] text-gray-500 dark:text-gray-400">
                    <span class="font-semibold text-gray-700 dark:text-gray-200">{{ __('driver_check.report.detail.settings') }}:</span>
                    <span x-text="settingsLine()"></span>
                </p>

                {{-- The accounts that took part --}}
                <section>
                    <h3 class="mb-2 text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.report.detail.accounts_title') }}</h3>

                    <p x-show="detail.accounts.length === 0" x-cloak class="rounded-lg border border-dashed border-gray-300 p-4 text-center text-[13px] text-gray-500 dark:border-gray-700">{{ __('driver_check.report.detail.no_accounts') }}</p>

                    <div x-show="detail.accounts.length > 0" class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
                        <table class="w-full min-w-[640px] text-left text-[13px] tabular-nums">
                            <thead class="border-b border-gray-200 bg-gray-25 text-[10.5px] uppercase tracking-[0.08em] text-gray-500 dark:border-gray-800 dark:bg-white/[0.015] dark:text-gray-400">
                                <tr>
                                    <th class="px-3 py-2 font-semibold">{{ __('driver_check.report.detail.account') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('driver_check.report.detail.groups_read') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('driver_check.report.detail.joined') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('driver_check.report.runs.added') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('driver_check.report.runs.skipped') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('driver_check.report.runs.failed') }}</th>
                                    <th class="px-3 py-2 font-semibold">{{ __('driver_check.report.detail.stopped') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                <template x-for="account in detail.accounts" :key="account.id">
                                    <tr>
                                        <td class="px-3 py-2">
                                            <p class="font-medium text-gray-900 dark:text-white" x-text="phone(account.phone)"></p>
                                            <p class="text-[11.5px] text-gray-400" x-text="account.username ? handle(account.username) : ''"></p>
                                        </td>
                                        <td class="px-3 py-2 text-right text-gray-600 dark:text-gray-300" x-text="number(account.groups)"></td>
                                        <td class="px-3 py-2 text-right text-gray-600 dark:text-gray-300" x-text="number(account.joined)"></td>
                                        <td class="px-3 py-2 text-right font-semibold text-gray-950 dark:text-white" x-text="number(account.added)"></td>
                                        <td class="px-3 py-2 text-right text-gray-500" x-text="number(account.skipped)"></td>
                                        <td class="px-3 py-2 text-right" :class="account.failed > 0 ? 'font-semibold text-error-600' : 'text-gray-400'" x-text="number(account.failed)"></td>
                                        <td class="px-3 py-2">
                                            <template x-if="account.stopped">
                                                <div>
                                                    <x-driver-check.badge ::class="eventClass(account.stopped)"><span x-text="eventLabel(account.stopped)"></span></x-driver-check.badge>
                                                    <p class="dc-break mt-0.5 text-[11.5px] text-gray-400" x-text="account.stopped_reason"></p>
                                                </div>
                                            </template>
                                            <span x-show="!account.stopped" class="text-gray-300" x-text="dash"></span>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </section>

                {{-- What each group gave --}}
                <section x-show="detail.groups.length > 0" x-cloak>
                    <h3 class="mb-2 text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.report.detail.groups_title') }}</h3>

                    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
                        <table class="w-full min-w-[480px] text-left text-[13px] tabular-nums">
                            <thead class="border-b border-gray-200 bg-gray-25 text-[10.5px] uppercase tracking-[0.08em] text-gray-500 dark:border-gray-800 dark:bg-white/[0.015] dark:text-gray-400">
                                <tr>
                                    <th class="px-3 py-2 font-semibold">{{ __('driver_check.report.detail.group') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('driver_check.report.detail.members') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('driver_check.report.runs.new') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('driver_check.report.runs.added') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('driver_check.report.runs.skipped') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('driver_check.report.runs.failed') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                <template x-for="group in detail.groups" :key="group.id">
                                    <tr>
                                        <td class="px-3 py-2 font-medium text-gray-900 dark:text-white" x-text="group.title"></td>
                                        <td class="px-3 py-2 text-right text-gray-600 dark:text-gray-300" x-text="group.members === null ? dash : number(group.members)"></td>
                                        <td class="px-3 py-2 text-right font-medium text-success-700 dark:text-success-400" x-text="group.new === null ? dash : number(group.new)"></td>
                                        <td class="px-3 py-2 text-right font-semibold text-gray-950 dark:text-white" x-text="number(group.added)"></td>
                                        <td class="px-3 py-2 text-right text-gray-500" x-text="number(group.skipped)"></td>
                                        <td class="px-3 py-2 text-right" :class="group.failed > 0 ? 'font-semibold text-error-600' : 'text-gray-400'" x-text="number(group.failed)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </section>

                {{-- The run's own log, on demand --}}
                <section>
                    <button type="button" class="inline-flex items-center gap-1.5 text-[13px] font-semibold text-gray-700 hover:text-gray-950 dark:text-gray-200 dark:hover:text-white" x-on:click="toggleLog()">
                        <x-driver-check.icon name="chevron-right" class="h-4 w-4 transition" ::class="logOpen && 'rotate-90'" />
                        <span x-text="logOpen ? t.detail.log_hide : t.detail.log_show"></span>
                    </button>

                    <div x-show="logOpen" x-cloak class="mt-2 overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
                        <ul class="max-h-72 divide-y divide-gray-100 overflow-y-auto text-[12.5px] dark:divide-gray-800">
                            <template x-for="(row, i) in log" :key="i">
                                <li class="flex items-start gap-3 px-3 py-2">
                                    <span class="w-[76px] shrink-0 pt-0.5 tabular-nums text-gray-400" x-text="date(row.created_at, true).slice(-5)"></span>
                                    <x-driver-check.badge ::class="eventClass(row.event)"><span x-text="eventLabel(row.event)"></span></x-driver-check.badge>
                                    <span class="w-28 shrink-0 pt-0.5 tabular-nums text-gray-500" x-text="row.account ? phone(row.account.phone) : ''"></span>
                                    <span class="dc-break min-w-0 flex-1 pt-0.5 text-gray-700 dark:text-gray-200">
                                        <span x-text="row.message"></span>
                                        <span class="text-gray-400" x-text="logContext(row)"></span>
                                    </span>
                                </li>
                            </template>
                        </ul>

                        <div class="flex justify-center border-t border-gray-100 bg-gray-25 p-2 dark:border-gray-800 dark:bg-white/[0.015]" x-show="logPage.current < logPage.last">
                            <x-driver-check.button size="sm" x-on:click="moreLog()" ::disabled="logLoading">{{ __('driver_check.report.detail.log_more') }}</x-driver-check.button>
                        </div>
                    </div>
                </section>
            </div>
        </template>
    </x-driver-check.modal>
</div>

@endsection
