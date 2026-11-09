@extends('layouts.driver-check')

@section('title', __('driver_check.tree.pages.control'))

{{--
    The control desk: every start and stop of the panel on one page. Each card
    runs the component of its own section's page (the same data, the same
    buttons), so a button pressed here is the one on that page; the link in a
    card's corner opens the section for the details.
--}}

@php
    $cardHead = 'flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800';
    $cardTitle = 'flex min-w-0 items-center gap-2 text-sm font-semibold text-gray-950 dark:text-white';
    $cardLink = 'shrink-0 text-[12.5px] font-medium text-brand-600 hover:underline dark:text-brand-400';
    $cardFoot = 'mt-auto flex flex-wrap items-center justify-end gap-2 border-t border-gray-100 px-4 py-3 dark:border-gray-800';
@endphp

@section('content')
    <x-driver-check.shell>

        <x-driver-check.page-header
            :title="__('driver_check.tree.pages.control')"
            :description="__('driver_check.control.description')"
        />

        <div class="grid items-stretch gap-4 xl:grid-cols-2">

            {{-- ============================================================
                 The accounts' processes: Driver Check and the listeners
            ============================================================= --}}
            <div
                x-data="dcListenerProcesses({
                    endpoints: {
                        index: @js(url('/api/telegram/listener-processes')),
                        base: @js(url('/api/telegram/listener-processes')),
                    },
                    translations: @js(__('telegram.listeners.processes')),
                    states: @js(__('telegram.listeners.state')),
                })"
            >
                <x-driver-check.surface class="flex h-full flex-col overflow-hidden">
                    <div class="{{ $cardHead }}">
                        <h2 class="{{ $cardTitle }}">
                            <x-driver-check.icon name="pulse" class="h-4 w-4 text-gray-400" />
                            {{ __('driver_check.control.cards.listeners') }}
                        </h2>
                        <a href="{{ route('driver-check.watchdog') }}" class="{{ $cardLink }}">{{ __('driver_check.control.open') }} →</a>
                    </div>

                    <div class="flex flex-col gap-3 p-4">
                        <section class="flex items-center gap-3 rounded-xl border px-3.5 py-3" :class="bannerClass()">
                            <span class="dc-dot shrink-0" :class="bannerDot()"></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[14px] font-semibold leading-5" x-text="summaryTitle()"></p>
                                <p class="mt-0.5 text-[12px] leading-snug opacity-90" x-text="summaryHint()"></p>
                            </div>
                        </section>

                        <p x-show="error" x-cloak class="dc-break rounded-lg bg-error-50 px-3 py-2 text-xs text-error-600 dark:bg-error-500/10 dark:text-error-400" x-text="error"></p>

                        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                            <template x-for="row in rows" :key="row.key">
                                <li class="flex items-center gap-3 py-2">
                                    <span class="dc-dot shrink-0" :class="dotClass(row)"></span>
                                    <span class="min-w-0 flex-1 truncate text-[13px] font-medium text-gray-900 dark:text-white" x-text="row.name"></span>
                                    <x-driver-check.badge class="shrink-0" ::class="badgeClass(row)">
                                        <span x-text="states[stateOf(row)]"></span>
                                    </x-driver-check.badge>
                                </li>
                            </template>
                            <li x-show="loaded && rows.length === 0" x-cloak class="py-3 text-center text-[13px] text-gray-500" x-text="t.none"></li>
                        </ul>
                    </div>

                    <div class="{{ $cardFoot }}">
                        <x-driver-check.button size="sm" variant="danger" icon="stop" x-on:click="askConfirm('stop')" ::disabled="!!busy || !status.can_control">
                            {{ __('telegram.listeners.processes.stop') }}
                        </x-driver-check.button>
                        <x-driver-check.button size="sm" icon="refresh" x-on:click="askConfirm('restart')" ::disabled="!!busy || !status.can_control">
                            {{ __('telegram.listeners.processes.restart_all') }}
                        </x-driver-check.button>
                        <x-driver-check.button size="sm" variant="primary" x-on:click="control('start')" ::disabled="!!busy || status.running">
                            <x-driver-check.icon name="refresh" class="h-4 w-4 animate-spin" x-show="busy === 'start'" x-cloak />
                            <x-driver-check.icon name="play" class="h-4 w-4" x-show="busy !== 'start'" />
                            {{ __('telegram.listeners.processes.start') }}
                        </x-driver-check.button>
                    </div>
                </x-driver-check.surface>

                <x-driver-check.modal open="confirmOpen" close="closeConfirm()" size="sm:max-w-md">
                    <x-slot:heading>
                        <span x-text="confirmAction === 'stop' ? t.confirm.stop_title : t.confirm.restart_title"></span>
                    </x-slot:heading>

                    <p class="text-[13px] leading-relaxed text-gray-600 dark:text-gray-300" x-text="confirmAction === 'stop' ? t.confirm.stop_text : t.confirm.restart_text"></p>

                    <p x-show="confirmError" x-cloak class="dc-break mt-3 rounded-lg bg-error-50 px-3 py-2.5 text-xs text-error-600 dark:bg-error-500/10 dark:text-error-400" x-text="confirmError"></p>

                    <x-slot:footer>
                        <x-driver-check.button x-on:click="closeConfirm()" ::disabled="!!busy">
                            {{ __('telegram.ui.cancel') }}
                        </x-driver-check.button>
                        <x-driver-check.button variant="danger" x-on:click="confirm()" ::disabled="!!busy">
                            <x-driver-check.icon name="refresh" class="h-4 w-4 animate-spin" x-show="!!busy" x-cloak />
                            {{ __('telegram.listeners.processes.confirm.confirm') }}
                        </x-driver-check.button>
                    </x-slot:footer>
                </x-driver-check.modal>
            </div>

            {{-- ============================================================
                 The daily contact sync
            ============================================================= --}}
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
                <x-driver-check.surface class="flex h-full flex-col overflow-hidden">
                    <div class="{{ $cardHead }}">
                        <h2 class="{{ $cardTitle }}">
                            <x-driver-check.icon name="book" class="h-4 w-4 text-gray-400" />
                            {{ __('driver_check.control.cards.contacts') }}
                        </h2>
                        <a href="{{ route('driver-check.contact-report') }}" class="{{ $cardLink }}">{{ __('driver_check.control.open') }} →</a>
                    </div>

                    <div class="flex flex-col gap-3 p-4">
                        <div x-show="!report && reportLoading" class="dc-skeleton h-16 rounded-xl bg-gray-100 dark:bg-white/[0.06]"></div>
                        <p x-show="reportError" x-cloak class="dc-break rounded-lg bg-error-50 px-3 py-2 text-xs text-error-600 dark:bg-error-500/10 dark:text-error-400" x-text="reportError"></p>

                        <template x-if="report">
                            <div class="flex flex-col gap-3">
                                <section class="flex items-start gap-3 rounded-xl border px-3.5 py-3" :class="bannerClass()">
                                    <span class="dc-dot mt-1.5 shrink-0" :class="dotClass()"></span>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-[14px] font-semibold leading-5 text-gray-950 dark:text-white" x-text="schedule.overdue ? @js(__('driver_check.report.schedule.overdue_title')) : statusText()"></p>
                                        <p class="mt-0.5 text-[12px] text-gray-500 dark:text-gray-400">
                                            <template x-if="schedule.enabled">
                                                <span>
                                                    <span x-text="t.schedule.daily_at.replace(':time', schedule.at)"></span>
                                                    <span class="mx-1 text-gray-300">·</span>
                                                    {{ __('driver_check.report.schedule.next') }}
                                                    <span class="font-medium text-gray-700 dark:text-gray-200" x-text="date(schedule.next_run_at)"></span>
                                                </span>
                                            </template>
                                            <template x-if="!schedule.enabled">
                                                <span>{{ __('driver_check.sync.settings.enabled_hint') }}</span>
                                            </template>
                                        </p>
                                    </div>
                                </section>

                                <dl class="grid grid-cols-2 gap-3">
                                    <div class="rounded-xl bg-gray-50 px-3 py-2.5 dark:bg-white/[0.03]">
                                        <dt class="text-[11.5px] text-gray-500">{{ __('driver_check.report.stats.added') }}</dt>
                                        <dd class="text-lg font-semibold tabular-nums text-gray-950 dark:text-white" x-text="number(today?.added)"></dd>
                                    </div>
                                    <div class="rounded-xl bg-gray-50 px-3 py-2.5 dark:bg-white/[0.03]">
                                        <dt class="text-[11.5px] text-gray-500">{{ __('driver_check.report.stats.failed') }}</dt>
                                        <dd class="text-lg font-semibold tabular-nums" :class="today?.failed > 0 ? 'text-error-600 dark:text-error-400' : 'text-gray-950 dark:text-white'" x-text="number(today?.failed)"></dd>
                                    </div>
                                </dl>
                            </div>
                        </template>
                    </div>

                    <div class="{{ $cardFoot }}">
                        <x-driver-check.button size="sm" variant="danger" icon="stop" x-show="schedule?.running" x-cloak x-on:click="stop()" ::disabled="stopping">
                            <span x-text="stopping ? t.stopping : t.stop"></span>
                        </x-driver-check.button>
                        <x-driver-check.button size="sm" variant="primary" x-on:click="run()" ::disabled="busy || !report">
                            <x-driver-check.icon name="refresh" class="h-4 w-4 animate-spin" x-show="busy" x-cloak />
                            <x-driver-check.icon name="play" class="h-4 w-4" x-show="!busy" />
                            <span x-text="busy ? t.running : t.run_again"></span>
                        </x-driver-check.button>
                    </div>
                </x-driver-check.surface>
            </div>

            {{-- ============================================================
                 Group invites: what runs now, and its four buttons
            ============================================================= --}}
            <div
                x-data="dcGroupInvites({
                    endpoints: {
                        show: @js(route('api.telegram.group-invites.show')),
                        run: @js(route('api.telegram.group-invites.run')),
                        check: @js(route('api.telegram.group-invites.check')),
                        checkLeft: @js(route('api.telegram.group-invites.check-left')),
                        stop: @js(route('api.telegram.group-invites.stop')),
                    },
                    links: {
                        people: @js(route('driver-check.group-invites.people')),
                    },
                    translations: @js(__('driver_check.invites')),
                })"
            >
                <x-driver-check.surface class="flex h-full flex-col overflow-hidden">
                    <div class="{{ $cardHead }}">
                        <h2 class="{{ $cardTitle }}">
                            <x-driver-check.icon name="send" class="h-4 w-4 text-gray-400" />
                            {{ __('driver_check.control.cards.invites') }}
                        </h2>
                        <a href="{{ route('driver-check.group-invites') }}" class="{{ $cardLink }}">{{ __('driver_check.control.open') }} →</a>
                    </div>

                    <div class="p-4">
                        <div x-show="!data && loading" class="dc-skeleton h-16 rounded-xl bg-gray-100 dark:bg-white/[0.06]"></div>
                        <p x-show="error" x-cloak class="dc-break rounded-lg bg-error-50 px-3 py-2 text-xs text-error-600 dark:bg-error-500/10 dark:text-error-400" x-text="error"></p>

                        <template x-if="data">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="dc-dot" :class="running ? 'animate-pulse text-success-500' : 'text-gray-300 dark:text-gray-600'"></span>
                                    <p class="dc-eyebrow" x-text="running ? t.now.title_running : t.now.title_idle"></p>
                                    <span x-show="running && now" x-cloak class="rounded-md bg-gray-100 px-1.5 py-0.5 text-[11px] font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300" x-text="modeLabel(now)"></span>
                                </div>

                                <template x-if="running">
                                    <div class="mt-2">
                                        <p class="text-[15px] font-semibold text-gray-950 dark:text-white" x-text="doingLabel()"></p>
                                        <p class="mt-0.5 text-[12.5px] text-gray-500">
                                            <span x-text="whereLabel()"></span>
                                            <span x-show="untilLabel()" x-text="(whereLabel() ? ' · ' : '') + untilLabel()"></span>
                                        </p>
                                        <div x-show="progress() !== null" x-cloak class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                                            <div class="h-full rounded-full bg-brand-500 transition-all duration-500" :style="`width: ${progress() ?? 0}%`"></div>
                                        </div>
                                        <p class="mt-2 text-[12px] tabular-nums text-gray-400" x-text="clockLine()"></p>
                                        <p x-show="now?.stop_asked" x-cloak class="mt-1 text-[12.5px] font-medium text-warning-600 dark:text-warning-400" x-text="t.now.stopping"></p>
                                        <p x-show="silent()" x-cloak class="mt-1 text-[12.5px] font-medium text-error-600 dark:text-error-400" x-text="silentLabel()"></p>
                                    </div>
                                </template>

                                <template x-if="!running">
                                    <div class="mt-2">
                                        <p class="text-[14px] font-semibold text-gray-950 dark:text-white" x-text="lastRunLabel()"></p>
                                        <p x-show="now?.totals" x-cloak class="mt-1 text-[12.5px] tabular-nums text-gray-600 dark:text-gray-300" x-text="summaryText(now?.totals || {})"></p>
                                        <p class="mt-1 text-[12px] text-gray-400" x-text="scheduleLabel() + ' · ' + audienceLabel()"></p>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>

                    <div class="{{ $cardFoot }}">
                        <template x-if="data && !running">
                            <div class="flex flex-wrap gap-2">
                                <x-driver-check.button size="sm" icon="check-circle" x-on:click="check()" ::disabled="busy || data.added.sent === 0" :title="__('driver_check.invites.check_hint')">
                                    <span x-text="t.check + (data.added.sent > 0 ? ' · ' + number(data.added.sent) : '')"></span>
                                </x-driver-check.button>
                                <x-driver-check.button size="sm" icon="logout" x-on:click="checkLeft()" ::disabled="busy || data.added.still === 0" :title="__('driver_check.invites.overview.left_hint')">
                                    {{ __('driver_check.invites.overview.left_button') }}
                                </x-driver-check.button>
                            </div>
                        </template>

                        <x-driver-check.button size="sm" variant="primary" icon="play" x-show="!running" x-on:click="run()" ::disabled="busy || !data">
                            {{ __('driver_check.invites.run') }}
                        </x-driver-check.button>
                        <x-driver-check.button size="sm" variant="danger" icon="stop" x-show="running" x-cloak x-on:click="stop()" ::disabled="busy || now?.stop_asked">
                            <span x-text="now?.stop_asked ? t.now.doing.stopping : t.stop"></span>
                        </x-driver-check.button>
                    </div>
                </x-driver-check.surface>
            </div>

            {{-- ============================================================
                 Drivers into a group: what runs now, continue and stop
            ============================================================= --}}
            <div
                x-data="dcGroupInvitesDrivers({
                    endpoints: {
                        show: @js(route('api.telegram.group-invites.drivers')),
                        rows: @js(route('api.telegram.group-invites.drivers.rows')),
                        settings: @js(route('api.telegram.group-invites.drivers.settings')),
                        all: @js(route('api.telegram.group-invites.drivers.all')),
                        run: @js(route('api.telegram.group-invites.drivers.run')),
                        retry: @js(route('api.telegram.group-invites.drivers.retry')),
                        stop: @js(route('api.telegram.group-invites.drivers.stop')),
                    },
                    translations: @js(__('driver_check.invites')),
                    drivers: @js(__('driver_check.drivers_group')),
                    compact: true,
                })"
            >
                <x-driver-check.surface class="flex h-full flex-col overflow-hidden">
                    <div class="{{ $cardHead }}">
                        <h2 class="{{ $cardTitle }}">
                            <x-driver-check.icon name="truck" class="h-4 w-4 text-gray-400" />
                            {{ __('driver_check.control.cards.drivers') }}
                        </h2>
                        <a href="{{ route('driver-check.group-invites.drivers') }}" class="{{ $cardLink }}">{{ __('driver_check.control.open') }} →</a>
                    </div>

                    <div class="p-4">
                        <div x-show="!data && loading" class="dc-skeleton h-16 rounded-xl bg-gray-100 dark:bg-white/[0.06]"></div>
                        <p x-show="error" x-cloak class="dc-break rounded-lg bg-error-50 px-3 py-2 text-xs text-error-600 dark:bg-error-500/10 dark:text-error-400" x-text="error"></p>

                        <template x-if="data">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="dc-dot" :class="running ? 'animate-pulse text-success-500' : 'text-gray-300 dark:text-gray-600'"></span>
                                    <p class="dc-eyebrow" x-text="running ? d.now.title_running : d.now.title_idle"></p>
                                </div>

                                <p x-show="!data.group" x-cloak class="mt-2 text-[13px] text-gray-500" x-text="d.no_group"></p>

                                <template x-if="data.group">
                                    <div class="mt-2">
                                        <p class="text-[14px] font-semibold text-gray-950 dark:text-white" x-text="running ? doingLabel() : lastRunLabel()"></p>
                                        <p x-show="running" x-cloak class="mt-0.5 text-[12.5px] text-gray-500" x-text="whereLabel()"></p>
                                        <p x-show="now?.totals" x-cloak class="mt-1 text-[12.5px] tabular-nums text-gray-600 dark:text-gray-300" x-text="summary(now?.totals)"></p>
                                        <p x-show="groupError()" x-cloak class="mt-1 text-[12.5px] font-medium text-error-600 dark:text-error-400" x-text="groupError()"></p>
                                        <p x-show="paused()" x-cloak class="mt-1 text-[12.5px] font-medium text-warning-600 dark:text-warning-400" x-text="pausedLabel()"></p>
                                        <p class="mt-1 text-[12px] tabular-nums text-gray-400">
                                            <span x-text="data.group.title"></span>
                                            <span x-text="' · ' + d.statuses.invited + ' ' + number(counts().invited) + ' · ' + d.statuses.pending + ' ' + number(counts().pending)"></span>
                                        </p>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>

                    <div class="{{ $cardFoot }}">
                        <x-driver-check.button size="sm" icon="users" x-show="!running" x-on:click="addAll()" ::disabled="busy || !data?.group || (data?.counts?.not_queued ?? 0) === 0">
                            <span x-text="d.add_all + ((data?.counts?.not_queued ?? 0) > 0 ? ' · ' + number(data.counts.not_queued) : '')"></span>
                        </x-driver-check.button>
                        <x-driver-check.button size="sm" variant="primary" icon="play" x-show="!running" x-on:click="run()" ::disabled="busy || !data?.group || (counts().pending === 0 && !paused())">
                            <span x-text="d.run + (counts().pending > 0 ? ' · ' + number(counts().pending) : '')"></span>
                        </x-driver-check.button>
                        <x-driver-check.button size="sm" variant="danger" icon="stop" x-show="running || !paused()" x-cloak x-on:click="stop()" ::disabled="busy || now?.stop_asked || (!running && !data?.group)">
                            <span x-text="now?.stop_asked ? d.stopping : d.stop"></span>
                        </x-driver-check.button>
                    </div>
                </x-driver-check.surface>
            </div>

            {{-- ============================================================
                 Join group: the watchdog, the switch, a new search
            ============================================================= --}}
            <div
                x-data="dcGroupJoin({
                    endpoints: {
                        show: @js(route('api.telegram.group-join.show')),
                        keywords: @js(url('/api/telegram/group-join/keywords')),
                        accounts: @js(url('/api/telegram/group-join/accounts')),
                        logs: @js(route('api.telegram.group-join.logs')),
                    },
                    translations: @js(__('driver_check.join')),
                    ui: @js(__('telegram.ui')),
                })"
            >
                {{-- The switch: a component of its own inside the join card (an attribute of <x-...> does not run @js) --}}
                <div
                    class="h-full"
                    x-data="dcControlJoin({
                        endpoints: { settings: @js(route('api.telegram.group-join.settings')) },
                        translations: @js(__('driver_check.control.join')),
                    })"
                >
                <x-driver-check.surface class="flex h-full flex-col overflow-hidden">
                    <div class="{{ $cardHead }}">
                        <h2 class="{{ $cardTitle }}">
                            <x-driver-check.icon name="search" class="h-4 w-4 text-gray-400" />
                            {{ __('driver_check.control.cards.join') }}
                        </h2>
                        <a href="{{ route('driver-check.group-join') }}" class="{{ $cardLink }}">{{ __('driver_check.control.open') }} →</a>
                    </div>

                    <div class="p-4">
                        <div x-show="!status && loading" class="dc-skeleton h-16 rounded-xl bg-gray-100 dark:bg-white/[0.06]"></div>
                        <p x-show="error" x-cloak class="dc-break rounded-lg bg-error-50 px-3 py-2 text-xs text-error-600 dark:bg-error-500/10 dark:text-error-400" x-text="error"></p>

                        <template x-if="status">
                            <div class="flex flex-col gap-3">
                                <div class="flex items-start gap-3">
                                    <span
                                        class="dc-dot mt-[7px] shrink-0"
                                        :class="!status.enabled ? 'text-gray-400' : (status.watchdog.running ? 'animate-pulse text-success-500' : 'text-warning-500')"
                                    ></span>
                                    <div class="text-[13px] text-gray-700 dark:text-gray-300">
                                        <p x-show="!status.enabled">{{ __('driver_check.join.worker.off') }}</p>
                                        <p x-show="status.enabled && status.watchdog.running" x-cloak x-text="t.worker.running.replace(':pid', status.watchdog.pid ?? '—').replace(':n', status.accounts.filter((a) => a.running).length).replace(':all', status.accounts.length)"></p>
                                        <p x-show="status.enabled && !status.watchdog.running" x-cloak>{{ __('driver_check.join.worker.starting') }}</p>
                                    </div>
                                </div>

                                <dl class="grid grid-cols-3 gap-3">
                                    <div class="rounded-xl bg-gray-50 px-3 py-2.5 dark:bg-white/[0.03]">
                                        <dt class="text-[11.5px] text-gray-500">{{ __('driver_check.join.totals.joined') }}</dt>
                                        <dd class="text-lg font-semibold tabular-nums text-gray-950 dark:text-white" x-text="number(status.totals.joined)"></dd>
                                    </div>
                                    <div class="rounded-xl bg-gray-50 px-3 py-2.5 dark:bg-white/[0.03]">
                                        <dt class="text-[11.5px] text-gray-500">{{ __('driver_check.join.totals.joins_today') }}</dt>
                                        <dd class="text-lg font-semibold tabular-nums text-gray-950 dark:text-white" x-text="number(status.totals.joins_today)"></dd>
                                    </div>
                                    <div class="rounded-xl bg-gray-50 px-3 py-2.5 dark:bg-white/[0.03]">
                                        <dt class="text-[11.5px] text-gray-500">{{ __('driver_check.join.totals.approved') }}</dt>
                                        <dd class="text-lg font-semibold tabular-nums text-gray-950 dark:text-white" x-text="number(status.totals.approved)"></dd>
                                    </div>
                                </dl>
                            </div>
                        </template>
                    </div>

                    <div class="{{ $cardFoot }}">
                        <template x-if="status && status.accounts.length === 0">
                            <a href="{{ route('driver-check.group-join.settings') }}" class="mr-auto text-[12.5px] font-medium text-brand-600 hover:underline dark:text-brand-400">{{ __('driver_check.join.worker.turn_on') }}</a>
                        </template>

                        <x-driver-check.button size="sm" icon="search" x-on:click="searchNow()" ::disabled="!!busy || !status || status.keywords.length === 0">
                            {{ __('driver_check.join.keywords.search_now') }}
                        </x-driver-check.button>
                        <x-driver-check.button size="sm" variant="danger" icon="stop" x-show="status?.enabled" x-cloak x-on:click="setEnabled(false, () => load())" ::disabled="switching">
                            {{ __('driver_check.control.join.off') }}
                        </x-driver-check.button>
                        <x-driver-check.button size="sm" variant="primary" icon="play" x-show="status && !status.enabled" x-cloak x-on:click="setEnabled(true, () => load())" ::disabled="switching || !status || status.accounts.length === 0">
                            {{ __('driver_check.control.join.on') }}
                        </x-driver-check.button>
                    </div>
                </x-driver-check.surface>
                </div>
            </div>

            {{-- ============================================================
                 Our join request bot: listening or not, the switch
            ============================================================= --}}
            <div
                x-data="dcGroupInvitesBot({
                    endpoints: {
                        show: @js(route('api.telegram.group-invites.bot')),
                        update: @js(route('api.telegram.group-invites.bot.update')),
                        groups: @js(route('api.telegram.group-invites.bot.groups')),
                        connect: @js(route('api.telegram.group-invites.bot.connect')),
                        disconnect: @js(route('api.telegram.group-invites.bot.disconnect')),
                        reports: @js(route('api.telegram.group-invites.bot.reports')),
                        reportTest: @js(route('api.telegram.group-invites.bot.reports.test')),
                    },
                    translations: @js(__('driver_check.invites')),
                })"
            >
                <x-driver-check.surface class="flex h-full flex-col overflow-hidden">
                    <div class="{{ $cardHead }}">
                        <h2 class="{{ $cardTitle }}">
                            <x-driver-check.icon name="bot" class="h-4 w-4 text-gray-400" />
                            {{ __('driver_check.control.cards.bot') }}
                        </h2>
                        <a href="{{ route('driver-check.group-invites.bot') }}" class="{{ $cardLink }}">{{ __('driver_check.control.open') }} →</a>
                    </div>

                    <div class="p-4">
                        <div x-show="!loaded && loading" class="dc-skeleton h-16 rounded-xl bg-gray-100 dark:bg-white/[0.06]"></div>

                        <template x-if="loaded">
                            <div class="flex flex-col gap-3">
                                <div class="flex items-start gap-3">
                                    <span class="dc-dot mt-[7px] shrink-0" :class="state().tone"></span>
                                    <div class="min-w-0 text-[13px]">
                                        <p class="font-semibold text-gray-950 dark:text-white" x-text="bot.connected ? '@' + bot.username : @js(__('driver_check.invites.bot.not_connected'))"></p>
                                        <p class="mt-0.5 text-gray-500" x-text="state().text"></p>
                                    </div>
                                </div>

                                <dl x-show="bot.connected" class="grid grid-cols-2 gap-3">
                                    <div class="rounded-xl bg-gray-50 px-3 py-2.5 dark:bg-white/[0.03]">
                                        <dt class="text-[11.5px] text-gray-500">{{ __('driver_check.invites.bot.today_people') }}</dt>
                                        <dd class="text-lg font-semibold tabular-nums text-gray-950 dark:text-white" x-text="number(data.today.people)"></dd>
                                    </div>
                                    <div class="rounded-xl bg-gray-50 px-3 py-2.5 dark:bg-white/[0.03]">
                                        <dt class="text-[11.5px] text-gray-500">{{ __('driver_check.invites.bot.today_failed') }}</dt>
                                        <dd class="text-lg font-semibold tabular-nums" :class="data.today.failed > 0 ? 'text-error-600 dark:text-error-400' : 'text-gray-950 dark:text-white'" x-text="number(data.today.failed)"></dd>
                                    </div>
                                </dl>
                            </div>
                        </template>
                    </div>

                    <div class="{{ $cardFoot }}">
                        <x-driver-check.button size="sm" variant="danger" icon="stop" x-show="loaded && bot.connected && bot.enabled" x-cloak x-on:click="toggle()" ::disabled="busy">
                            {{ __('driver_check.control.bot.off') }}
                        </x-driver-check.button>
                        <x-driver-check.button size="sm" variant="primary" icon="play" x-show="loaded && bot.connected && !bot.enabled" x-cloak x-on:click="toggle()" ::disabled="busy">
                            {{ __('driver_check.control.bot.on') }}
                        </x-driver-check.button>
                        <x-driver-check.button size="sm" icon="key" x-show="loaded && !bot.connected" x-cloak :href="route('driver-check.group-invites.bot')">
                            {{ __('driver_check.tree.pages.bot_connection') }}
                        </x-driver-check.button>
                    </div>
                </x-driver-check.surface>
            </div>

            {{-- ============================================================
                 The queue every background step goes through
            ============================================================= --}}
            <div
                x-data="dcControlQueue({
                    endpoints: { summary: @js(route('api.telegram.queue.summary')) },
                    translations: @js(__('telegram.queue')),
                })"
            >
                <x-driver-check.surface class="flex h-full flex-col overflow-hidden">
                    <div class="{{ $cardHead }}">
                        <h2 class="{{ $cardTitle }}">
                            <x-driver-check.icon name="layers" class="h-4 w-4 text-gray-400" />
                            {{ __('driver_check.control.cards.queue') }}
                        </h2>
                        <a href="{{ route('driver-check.queue') }}" class="{{ $cardLink }}">{{ __('driver_check.control.open') }} →</a>
                    </div>

                    <div class="p-4">
                        <p x-show="error" x-cloak class="dc-break rounded-lg bg-error-50 px-3 py-2 text-xs text-error-600 dark:bg-error-500/10 dark:text-error-400" x-text="error"></p>
                        <div x-show="!summary && !error" class="dc-skeleton h-16 rounded-xl bg-gray-100 dark:bg-white/[0.06]"></div>

                        <template x-if="summary">
                            <div class="flex flex-col gap-3">
                                <div class="flex items-start gap-3">
                                    <span class="dc-dot mt-[7px] shrink-0" :class="dot()"></span>
                                    <div class="min-w-0 text-[13px]">
                                        <p class="font-semibold text-gray-950 dark:text-white" x-text="t.worker[state()]"></p>
                                        <p x-show="summary.worker?.pulse_at" class="mt-0.5 text-gray-500" x-text="t.worker.pulse + ': ' + relative(summary.worker?.pulse_at)"></p>
                                        <p x-show="state() !== 'alive'" x-cloak class="mt-0.5 text-[12px] text-gray-500" x-text="state() === 'dead' ? t.worker.dead_hint : t.worker.never_hint"></p>
                                    </div>
                                </div>

                                <dl class="grid grid-cols-3 gap-3">
                                    <div class="rounded-xl bg-gray-50 px-3 py-2.5 dark:bg-white/[0.03]">
                                        <dt class="text-[11.5px] text-gray-500">{{ __('telegram.queue.stats.ready') }}</dt>
                                        <dd class="text-lg font-semibold tabular-nums text-gray-950 dark:text-white" x-text="number(main()?.ready)"></dd>
                                    </div>
                                    <div class="rounded-xl bg-gray-50 px-3 py-2.5 dark:bg-white/[0.03]">
                                        <dt class="text-[11.5px] text-gray-500">{{ __('telegram.queue.stats.delayed') }}</dt>
                                        <dd class="text-lg font-semibold tabular-nums text-gray-950 dark:text-white" x-text="number(main()?.delayed)"></dd>
                                    </div>
                                    <a href="{{ route('driver-check.queue') }}" class="rounded-xl bg-gray-50 px-3 py-2.5 transition hover:bg-gray-100 dark:bg-white/[0.03] dark:hover:bg-white/[0.06]">
                                        <dt class="text-[11.5px] text-gray-500">{{ __('telegram.queue.stats.failed') }}</dt>
                                        <dd class="text-lg font-semibold tabular-nums" :class="failed() > 0 ? 'text-error-600 dark:text-error-400' : 'text-gray-950 dark:text-white'" x-text="number(failed())"></dd>
                                    </a>
                                </dl>
                            </div>
                        </template>
                    </div>
                </x-driver-check.surface>
            </div>

        </div>
    </x-driver-check.shell>
@endsection
