@extends('layouts.driver-check')

@section('title', $part === 'driver-check' ? __('driver_check.tree.pages.driver_check_process') : __('telegram.watchdog.title'))

@section('content')

<div
    x-data="dcListenerProcesses({
        endpoints: {
            index: @js(url('/api/telegram/listener-processes')),
            base: @js(url('/api/telegram/listener-processes')),
        },
        translations: @js(__('telegram.listeners.processes')),
        states: @js(__('telegram.listeners.state')),
        part: @js($part),
    })"
>
    <x-driver-check.shell>

        {{-- ============================================================
             Header: the buttons act on every account, Driver Check included
        ============================================================= --}}
        <x-driver-check.page-header
            :title="$part === 'driver-check' ? __('driver_check.tree.pages.driver_check_process') : __('telegram.watchdog.title')"
            :description="$part === 'driver-check' ? __('driver_check.pages.driver_check_process') : __('telegram.watchdog.description')"
        >
            @if ($part === 'all')
            <x-slot:actions>
                {{-- Refresh and the destructive stop first, the one primary (start) last --}}
                <x-driver-check.button
                    size="icon"
                    x-on:click="load()"
                    ::disabled="loading"
                    :title="__('telegram.ui.refresh')"
                    :aria-label="__('telegram.ui.refresh')"
                >
                    <x-driver-check.icon name="refresh" class="h-4 w-4" ::class="loading && 'animate-spin'" />
                </x-driver-check.button>

                <x-driver-check.button variant="danger" x-on:click="askConfirm('stop')" ::disabled="!!busy || !status.can_control">
                    <x-driver-check.icon name="stop" class="h-4 w-4" />
                    {{ __('telegram.listeners.processes.stop') }}
                </x-driver-check.button>

                <x-driver-check.button x-on:click="askConfirm('restart')" ::disabled="!!busy || !status.can_control">
                    <x-driver-check.icon name="refresh" class="h-4 w-4" />
                    {{ __('telegram.listeners.processes.restart_all') }}
                </x-driver-check.button>

                <x-driver-check.button variant="primary" x-on:click="control('start')" ::disabled="!!busy || status.running">
                    <x-driver-check.icon name="refresh" class="h-4 w-4 animate-spin" x-show="busy === 'start'" x-cloak />
                    <x-driver-check.icon name="play" class="h-4 w-4" x-show="busy !== 'start'" />
                    {{ __('telegram.listeners.processes.start') }}
                </x-driver-check.button>
            </x-slot:actions>
            @endif
        </x-driver-check.page-header>

        @if ($part === 'all')
        {{-- ============================================================
             Overall state: the one loud thing on the page
        ============================================================= --}}
        <section class="flex items-center gap-4 rounded-2xl border px-4 py-4 sm:px-5 sm:py-5" :class="bannerClass()">
            <span class="dc-dot shrink-0 !h-3 !w-3" :class="bannerDot()"></span>

            <div class="min-w-0 flex-1">
                <p class="text-lg font-semibold leading-6" x-text="summaryTitle()"></p>
                <p class="mt-0.5 text-[13px] leading-relaxed opacity-90" x-text="summaryHint()"></p>
            </div>

            <p class="hidden shrink-0 text-[11px] opacity-70 sm:block" x-text="status.running ? t.watchdog_up + (status.pid ? ' · pid ' + status.pid : '') : t.watchdog_down"></p>
        </section>


        <p
            x-show="error"
            x-cloak
            class="dc-break rounded-xl bg-error-50 px-3 py-2.5 text-xs text-error-600 dark:bg-error-500/10 dark:text-error-400"
            x-text="error"
        ></p>

        {{-- ============================================================
             Every account: Driver Check and the listeners in one list
        ============================================================= --}}
        <x-driver-check.surface class="overflow-hidden">
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                <template x-for="row in rows" :key="row.key">
                    <li class="flex flex-col gap-2 px-4 py-3.5 sm:flex-row sm:items-center sm:gap-3">
                        <div class="flex min-w-0 flex-1 items-start gap-3">
                            <span class="dc-dot mt-1.5 shrink-0" :class="dotClass(row)"></span>

                            <div class="min-w-0 flex-1">
                                <p class="dc-break text-sm font-semibold text-gray-900 dark:text-white" x-text="row.name"></p>
                                <p class="mt-0.5 text-[12px] tabular-nums text-gray-500 dark:text-gray-400">
                                    <span x-show="!row.is_driver_check" class="font-mono" x-text="row.key + ' · '"></span><span x-text="row.account || t.no_account"></span>
                                </p>

                                <p
                                    x-show="reason(row)"
                                    x-cloak
                                    class="dc-break mt-1 text-[12px] leading-relaxed text-error-600 dark:text-error-400"
                                >
                                    <span x-text="reason(row)"></span>
                                    <a x-show="row.blocker" href="{{ route('driver-check.sessions') }}" class="ml-1 font-medium underline">{{ __('telegram.listeners.processes.open_sessions') }}</a>
                                </p>
                            </div>
                        </div>

                        <div class="flex shrink-0 items-center gap-2 pl-[19px] sm:pl-0">
                            <x-driver-check.badge class="w-28 justify-center" ::class="badgeClass(row)">
                                <span x-text="states[stateOf(row)]"></span>
                            </x-driver-check.badge>

                            <x-driver-check.button
                                x-show="row.is_enabled && row.can_restart"
                                x-cloak
                                size="sm"
                                x-on:click="control('restart', row.key)"
                                ::disabled="!!busy"
                            >
                                <x-driver-check.icon name="refresh" class="h-4 w-4" ::class="busy === row.key && 'animate-spin'" />
                                <span x-text="t.restart_one"></span>
                            </x-driver-check.button>
                        </div>
                    </li>
                </template>

                <li x-show="loaded && rows.length === 0" x-cloak class="px-4 py-6 text-center text-[13px] text-gray-500" x-text="t.none"></li>
            </ul>

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 px-4 py-3 dark:border-gray-800">
                <p class="text-[12px] leading-relaxed text-gray-500 dark:text-gray-400">{{ __('telegram.listeners.processes.hint') }}</p>

                <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <a
                        href="{{ route('driver-check.watchdog.driver-check') }}"
                        class="text-[13px] font-medium text-brand-600 hover:underline dark:text-brand-400"
                    >{{ __('driver_check.tree.pages.driver_check_process') }} →</a>
                    <a
                        href="{{ url('/driver-check/listeners') }}"
                        class="text-[13px] font-medium text-brand-600 hover:underline dark:text-brand-400"
                    >{{ __('telegram.listeners.processes.manage') }}</a>
                </div>
            </div>
        </x-driver-check.surface>

        @endif

        @if ($part === 'driver-check')
        {{-- ============================================================
             Driver Check's own details: the part that was the whole page
        ============================================================= --}}
        <div
            class="flex flex-col gap-5"
            x-data="dcWatchdog({
                endpoints: {
                    show: @js(route('api.telegram.watchdog.show')),
                    start: @js(route('api.telegram.watchdog.start')),
                    restart: @js(route('api.telegram.watchdog.restart-listener')),
                    stop: @js(route('api.telegram.watchdog.stop')),
                },
                sessionsUrl: @js(route('driver-check.sessions')),
                queueUrl: @js(route('driver-check.queue')),
                translations: @js(__('telegram.watchdog')),
                ui: @js(__('telegram.ui')),
            })"
        >
        <x-driver-check.error-alert :title="__('telegram.watchdog.errors.title')" />

        {{-- The buttons the page had no room for: look again, start, restart the listener, stop everything --}}
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('telegram.listeners.processes.driver_check_details') }}</h2>

            <div class="flex flex-wrap items-center gap-2">
                <x-driver-check.button size="icon" x-on:click="load()" ::disabled="loading" :title="__('telegram.ui.refresh')" :aria-label="__('telegram.ui.refresh')">
                    <x-driver-check.icon name="refresh" class="h-4 w-4" ::class="loading && 'animate-spin'" />
                </x-driver-check.button>
                <x-driver-check.button icon="refresh" x-on:click="askConfirm('restart')" ::disabled="!!busy || !status?.can_signal">
                    {{ __('telegram.watchdog.actions.restart') }}
                </x-driver-check.button>
                <x-driver-check.button variant="danger" icon="stop" x-on:click="askConfirm('stop')" ::disabled="!!busy || !status?.can_signal">
                    {{ __('telegram.watchdog.actions.stop') }}
                </x-driver-check.button>
                <x-driver-check.button variant="primary" icon="play" x-on:click="start()" ::disabled="!!busy">
                    {{ __('telegram.watchdog.actions.start') }}
                </x-driver-check.button>
            </div>
        </div>

        <x-driver-check.modal open="confirmOpen" close="closeConfirm()" size="sm:max-w-md">
            <x-slot:heading>
                <span x-text="confirmAction === 'stop' ? t.confirm.stop_title : t.confirm.restart_title"></span>
            </x-slot:heading>

            <p class="text-[13px] leading-relaxed text-gray-600 dark:text-gray-300" x-text="confirmAction === 'stop' ? t.confirm.stop_text : t.confirm.restart_text"></p>
            <p x-show="confirmError" x-cloak class="dc-break mt-3 rounded-lg bg-error-50 px-3 py-2.5 text-xs text-error-600 dark:bg-error-500/10 dark:text-error-400" x-text="confirmError"></p>

            <x-slot:footer>
                <x-driver-check.button x-on:click="closeConfirm()" ::disabled="!!busy">{{ __('telegram.ui.cancel') }}</x-driver-check.button>
                <x-driver-check.button variant="danger" x-on:click="confirm()" ::disabled="!!busy">{{ __('telegram.watchdog.confirm.confirm') }}</x-driver-check.button>
            </x-slot:footer>
        </x-driver-check.modal>

        {{-- First load --}}
        <div x-show="!status && loading" class="flex flex-col gap-5">
            <div class="dc-skeleton h-20 rounded-2xl bg-gray-100 dark:bg-white/[0.06]"></div>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <template x-for="i in 4" :key="'sk-' + i">
                    <div class="dc-skeleton h-24 rounded-2xl bg-gray-100 dark:bg-white/[0.06]"></div>
                </template>
            </div>
            <div class="dc-skeleton h-64 rounded-2xl bg-gray-100 dark:bg-white/[0.06]"></div>
        </div>

        <template x-if="status">
            <div
                class="flex flex-col gap-5"
                x-data="{ dotTone: { success: 'text-success-500', progress: 'text-blue-light-500', waiting: 'text-warning-500', error: 'text-error-500', muted: 'text-gray-400' } }"
            >

                {{-- ====================================================
                     Overall state: the one loud thing on the page
                ===================================================== --}}
                <section class="flex items-center gap-4 rounded-2xl border px-4 py-4 sm:px-5 sm:py-5" :class="bannerClass()">
                    <span class="dc-dot shrink-0 !h-3 !w-3" :class="dotTone[stateTone()]"></span>

                    <div class="min-w-0 flex-1">
                        <p class="text-lg font-semibold leading-6" x-text="t.state[state()]"></p>
                        <p class="mt-0.5 text-[13px] leading-relaxed opacity-90" x-text="t.state_hint[state()]"></p>
                    </div>

                    <p class="hidden shrink-0 text-[11px] opacity-70 sm:block" x-text="t.auto_refresh"></p>
                </section>

                <p
                    x-show="!status.can_signal"
                    x-cloak
                    class="flex items-start gap-2 rounded-lg border border-gray-200 bg-gray-25 px-3 py-2.5 text-[12px] text-gray-600 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-400"
                >
                    <x-driver-check.icon name="alert" class="mt-px h-4 w-4 shrink-0" />
                    <span x-text="t.signals_unavailable"></span>
                </p>

                {{-- ====================================================
                     Activity
                ===================================================== --}}
                <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <x-driver-check.stat-card :label="__('telegram.watchdog.activity.last_check')">
                        <span
                            class="inline-block align-top text-[20px] leading-7"
                            :title="status.activity.last_check_at ? date(status.activity.last_check_at) : ''"
                            x-text="status.activity.last_check_at ? relative(status.activity.last_check_at) : ui.never"
                        ></span>
                    </x-driver-check.stat-card>

                    <x-driver-check.stat-card :label="__('telegram.watchdog.activity.last_report')">
                        <span
                            class="inline-block align-top text-[20px] leading-7"
                            :title="status.activity.last_report_at ? date(status.activity.last_report_at) : ''"
                            x-text="status.activity.last_report_at ? relative(status.activity.last_report_at) : ui.never"
                        ></span>
                    </x-driver-check.stat-card>

                    <x-driver-check.stat-card :label="__('telegram.watchdog.activity.checks_today')">
                        <span x-text="number(status.activity.checks_today)"></span>
                    </x-driver-check.stat-card>

                    <x-driver-check.stat-card :label="__('telegram.watchdog.activity.pending')">
                        <span x-text="number(status.activity.pending)"></span>
                    </x-driver-check.stat-card>
                </section>

                {{-- ====================================================
                     Components: one row each, dot + name + detail + state
                ===================================================== --}}
                <x-driver-check.surface class="overflow-hidden">
                    <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach (['watchdog', 'spare', 'listener'] as $role)
                            <li class="flex items-center gap-3 px-4 py-3.5">
                                <span class="dc-dot shrink-0" :class="dotTone[processTone('{{ $role }}')]"></span>

                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold text-gray-900 dark:text-white">
                                        {{ __('telegram.watchdog.processes.' . $role) }}
                                    </p>
                                    <p class="mt-0.5 text-[12px] leading-snug text-gray-500 dark:text-gray-400">
                                        {{ __('telegram.watchdog.processes.' . $role . '_hint') }}
                                    </p>
                                </div>

                                <p class="hidden shrink-0 text-[12px] tabular-nums text-gray-500 sm:block dark:text-gray-400">
                                    {{ __('telegram.watchdog.processes.pid') }}:
                                    <span class="font-mono text-gray-800 dark:text-gray-200" x-text="process('{{ $role }}').pid ?? dash"></span>
                                </p>

                                <x-driver-check.badge class="w-24 justify-center" ::class="processBadge('{{ $role }}')">
                                    <span x-text="processLabel('{{ $role }}')"></span>
                                </x-driver-check.badge>
                            </li>
                        @endforeach

                        {{-- Queue worker (the start button goes through it) --}}
                        <li class="flex flex-col gap-2 px-4 py-3.5">
                            <div class="flex items-center gap-3">
                                <span class="dc-dot shrink-0" :class="status.queue_worker_alive ? 'text-success-500' : 'text-error-500'"></span>

                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('telegram.watchdog.worker.title') }}</p>
                                    <a :href="queueUrl" class="text-[12px] font-medium text-brand-600 hover:underline dark:text-brand-400">
                                        {{ __('telegram.watchdog.worker.open_queue') }} →
                                    </a>
                                </div>

                                <x-driver-check.badge
                                    class="w-24 justify-center"
                                    ::class="status.queue_worker_alive
                                        ? 'bg-success-50 text-success-700 ring-1 ring-inset ring-success-600/20 dark:bg-success-500/10 dark:text-success-400'
                                        : 'bg-error-50 text-error-700 ring-1 ring-inset ring-error-600/20 dark:bg-error-500/10 dark:text-error-400'"
                                >
                                    <span x-text="status.queue_worker_alive ? t.worker.alive : t.worker.dead"></span>
                                </x-driver-check.badge>
                            </div>

                            <p
                                x-show="!status.queue_worker_alive"
                                x-cloak
                                class="pl-[19px] text-[12px] leading-relaxed text-gray-600 dark:text-gray-400"
                                x-text="t.worker.dead_hint"
                            ></p>

                            <p
                                x-show="status.start_queued > 0"
                                x-cloak
                                class="ml-[19px] rounded-lg bg-warning-50 px-2.5 py-1.5 text-[12px] text-warning-800 dark:bg-warning-500/10 dark:text-warning-300"
                                x-text="t.worker.start_queued.replace(':count', status.start_queued)"
                            ></p>
                        </li>

                        {{-- Main account --}}
                        <li class="flex flex-col gap-2 px-4 py-3.5">
                            <div class="flex items-center gap-3">
                                <span
                                    class="dc-dot shrink-0"
                                    :class="account() && account().is_authorized ? 'text-success-500' : 'text-error-500'"
                                ></span>

                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('telegram.watchdog.account.title') }}</p>

                                    <template x-if="account()">
                                        <p class="truncate text-[12px] tabular-nums text-gray-500 dark:text-gray-400">
                                            <span x-text="accountName()"></span>
                                            <span x-text="' · ' + phone(account().phone)"></span>
                                            <template x-if="account().username">
                                                <span x-text="' · ' + handle(account().username)"></span>
                                            </template>
                                        </p>
                                    </template>
                                </div>

                                <template x-if="account()">
                                    <p class="hidden shrink-0 text-[12px] tabular-nums text-gray-500 sm:block dark:text-gray-400">
                                        {{ __('telegram.watchdog.account.status') }}:
                                        <span class="font-mono text-gray-800 dark:text-gray-200" x-text="account().status || dash"></span>
                                        · ID <span class="text-gray-800 dark:text-gray-200" x-text="account().id"></span>
                                    </p>
                                </template>

                                <template x-if="account()">
                                    <x-driver-check.badge
                                        class="w-24 justify-center"
                                        ::class="account().is_authorized
                                            ? 'bg-success-50 text-success-700 ring-1 ring-inset ring-success-600/20 dark:bg-success-500/10 dark:text-success-400'
                                            : 'bg-error-50 text-error-700 ring-1 ring-inset ring-error-600/20 dark:bg-error-500/10 dark:text-error-400'"
                                    >
                                        <span x-text="account().is_authorized ? t.account.authorized : t.account.not_authorized"></span>
                                    </x-driver-check.badge>
                                </template>

                                <a :href="sessionsUrl" class="hidden shrink-0 text-[12px] font-medium text-brand-600 hover:underline sm:inline dark:text-brand-400">
                                    {{ __('telegram.watchdog.account.open_sessions') }} →
                                </a>
                            </div>

                            <a :href="sessionsUrl" class="pl-[19px] text-[12px] font-medium text-brand-600 hover:underline sm:hidden dark:text-brand-400">
                                {{ __('telegram.watchdog.account.open_sessions') }} →
                            </a>

                            <template x-if="account()">
                                <p
                                    x-show="!account().session_exists"
                                    x-cloak
                                    class="pl-[19px] text-[12px] text-error-600 dark:text-error-400"
                                    x-text="t.account.session_missing"
                                ></p>
                            </template>

                            <template x-if="!account()">
                                <div class="ml-[19px] rounded-lg bg-error-50 px-3 py-2.5 text-[12px] text-error-700 dark:bg-error-500/10 dark:text-error-400">
                                    <p class="font-semibold" x-text="status.account_configured ? t.account.not_found : t.account.missing"></p>
                                    <p x-show="!status.account_configured" class="mt-0.5" x-text="t.account.missing_hint"></p>
                                </div>
                            </template>
                        </li>
                    </ul>
                </x-driver-check.surface>

                {{-- ====================================================
                     Why it went down last
                ===================================================== --}}
                <template x-if="restartNotice() || healthMarker()">
                    <x-driver-check.surface class="overflow-hidden">
                        <h2 class="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-gray-900 dark:border-gray-800 dark:text-white">{{ __('telegram.watchdog.restart.title') }}</h2>

                        <div class="flex flex-col gap-3 p-4">
                            <template x-if="restartNotice()">
                                <dl class="grid grid-cols-2 gap-x-3 gap-y-3 sm:grid-cols-5">
                                    <x-driver-check.kv :label="__('telegram.watchdog.restart.reason')">
                                        <span class="font-mono text-[12px]" x-text="restartNotice().reason ?? dash"></span>
                                    </x-driver-check.kv>

                                    <x-driver-check.kv :label="__('telegram.watchdog.restart.exit_code')">
                                        <span class="tabular-nums" x-text="restartNotice().exit_code ?? dash"></span>
                                    </x-driver-check.kv>

                                    <x-driver-check.kv :label="__('telegram.watchdog.restart.uptime')">
                                        <span x-text="duration(restartNotice().uptime_seconds)"></span>
                                    </x-driver-check.kv>

                                    <x-driver-check.kv :label="__('telegram.watchdog.restart.restarts')">
                                        <span class="tabular-nums" x-text="restartNotice().restart_count ?? dash"></span>
                                    </x-driver-check.kv>

                                    <x-driver-check.kv :label="__('telegram.watchdog.restart.at')">
                                        <span x-text="restartNotice().recorded_at ? relative(restartNotice().recorded_at) : dash"></span>
                                    </x-driver-check.kv>

                                    <x-driver-check.kv :label="__('telegram.watchdog.restart.detail')" class="col-span-2 sm:col-span-5">
                                        <span class="dc-break font-mono text-[12px]" x-text="restartNotice().detail || dash"></span>
                                    </x-driver-check.kv>
                                </dl>
                            </template>

                            <p
                                x-show="healthMarker()"
                                x-cloak
                                class="rounded-lg bg-warning-50 px-3 py-2 text-[12px] text-warning-800 dark:bg-warning-500/10 dark:text-warning-300"
                            >
                                <span class="font-semibold" x-text="t.restart.marker + ':'"></span>
                                <span class="font-mono" x-text="healthMarker()?.reason"></span>
                                <span x-text="healthMarker()?.marked_at ? '· ' + relative(healthMarker().marked_at) : ''"></span>
                            </p>
                        </div>
                    </x-driver-check.surface>
                </template>
            </div>
        </template>
        </div>
        @endif
    </x-driver-check.shell>

    {{-- ================================================================
         Confirmation: restart / stop of everything
    ================================================================= --}}
    <x-driver-check.modal open="confirmOpen" close="closeConfirm()" size="sm:max-w-md">
        <x-slot:heading>
            <span x-text="confirmAction === 'stop' ? t.confirm.stop_title : t.confirm.restart_title"></span>
        </x-slot:heading>

        <p
            class="text-[13px] leading-relaxed text-gray-600 dark:text-gray-300"
            x-text="confirmAction === 'stop' ? t.confirm.stop_text : t.confirm.restart_text"
        ></p>

        <p
            x-show="confirmError"
            x-cloak
            class="dc-break mt-3 rounded-lg bg-error-50 px-3 py-2.5 text-xs text-error-600 dark:bg-error-500/10 dark:text-error-400"
            x-text="confirmError"
        ></p>

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

@endsection
