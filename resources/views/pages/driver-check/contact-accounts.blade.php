@extends('layouts.driver-check')

@section('title', __('driver_check.menu.accounts'))

@section('content')

<div
    x-data="dcContactAccounts({
        endpoints: {
            list: @js(route('api.telegram.contact-sync.accounts.list')),
            base: @js(url('/api/telegram/contact-sync/accounts')),
        },
        translations: @js(__('driver_check.accounts')),
    })"
>
    <x-driver-check.shell>

        {{-- Header: refresh, the careful one (clear the database), then the one primary - sync them all --}}
        <x-driver-check.page-header
            :title="__('driver_check.accounts.title')"
            :description="__('driver_check.accounts.description')"
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

                <x-driver-check.button x-on:click="clearDatabaseAll()" ::disabled="locked" class="!text-error-600 dark:!text-error-400">
                    <x-driver-check.icon name="trash" class="h-4 w-4" />
                    {{ __('driver_check.accounts.clear_db_all') }}
                </x-driver-check.button>

                <x-driver-check.button variant="primary" x-on:click="syncAll()" ::disabled="locked">
                    <x-driver-check.icon name="refresh" class="h-4 w-4 animate-spin" x-show="working" x-cloak />
                    <x-driver-check.icon name="sync" class="h-4 w-4" x-show="!working" />
                    {{ __('driver_check.accounts.sync_all') }}
                </x-driver-check.button>
            </x-slot:actions>
        </x-driver-check.page-header>

        <x-driver-check.error-alert :title="__('driver_check.accounts.error')" />

        {{-- What the two numbers are --}}
        <div class="flex flex-col gap-1 rounded-xl border border-dashed border-gray-300 px-4 py-3 text-[12.5px] text-gray-500 sm:flex-row sm:gap-6 dark:border-gray-700 dark:text-gray-400">
            <p><span class="font-semibold text-gray-700 dark:text-gray-200">{{ __('driver_check.accounts.in_database') }}</span> — {{ __('driver_check.accounts.legend_db') }}</p>
            <p><span class="font-semibold text-gray-700 dark:text-gray-200">{{ __('driver_check.accounts.in_account') }}</span> — {{ __('driver_check.accounts.legend_account') }}</p>
        </div>

        {{-- Its place is always there; only its look comes and goes, so nothing below it moves. --}}
        <p
            class="inline-flex h-5 items-center gap-2 text-[12.5px] font-medium text-blue-light-700 transition-opacity dark:text-blue-light-400"
            :class="working ? 'opacity-100' : 'opacity-0'"
            :aria-hidden="!working"
        >
            <span class="dc-dot animate-pulse text-blue-light-500"></span>
            {{ __('driver_check.accounts.running_note') }}
        </p>

        <div x-show="!data && loading" class="dc-skeleton h-56 rounded-2xl bg-gray-100 dark:bg-white/[0.06]"></div>

        <template x-if="data">
            <x-driver-check.surface class="overflow-hidden">
                <p x-show="accounts.length === 0" x-cloak class="px-4 py-10 text-center text-[13px] text-gray-500">{{ __('driver_check.accounts.empty') }}</p>

                {{-- Table (lg and up) --}}
                <div class="hidden lg:block" x-show="accounts.length > 0">
                    <div class="overflow-x-auto">
                        {{-- Fixed layout: a column keeps its width whatever its text says, so a new status or a longer "3 minutes ago" cannot move the others. --}}
                        <table class="w-full min-w-[1040px] table-fixed text-left">
                            <colgroup>
                                <col class="w-[24%]">
                                <col class="w-[8%]">
                                <col class="w-[9%]">
                                <col class="w-[17%]">
                                <col class="w-[12%]">
                                <col class="w-[30%]">
                            </colgroup>

                            <thead class="dc-thead border-b border-gray-200 bg-gray-25 dark:border-gray-800 dark:bg-transparent">
                                <tr class="text-[10.5px] uppercase tracking-[0.08em] text-gray-500 dark:text-gray-400">
                                    <th class="px-4 py-2.5 font-semibold">{{ __('driver_check.accounts.account') }}</th>
                                    <th class="px-3 py-2.5 text-right font-semibold">{{ __('driver_check.accounts.in_database') }}</th>
                                    <th class="px-3 py-2.5 text-right font-semibold">{{ __('driver_check.accounts.in_account') }}</th>
                                    <th class="px-3 py-2.5 font-semibold">{{ __('driver_check.accounts.agreement') }}</th>
                                    <th class="px-3 py-2.5 font-semibold">{{ __('driver_check.accounts.last_sync') }}</th>
                                    <th class="px-4 py-2.5 text-right font-semibold">{{ __('driver_check.accounts.actions') }}</th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                <template x-for="account in accounts" :key="account.id">
                                    <tr class="dc-row align-middle transition-colors hover:bg-gray-25 dark:hover:bg-white/[0.025]">
                                        <td class="px-4 py-3">
                                            <p class="text-sm font-medium tabular-nums text-gray-900 dark:text-white" x-text="phone(account.phone)"></p>
                                            <div class="mt-0.5 flex flex-wrap items-center gap-1.5">
                                                <span x-show="account.username" class="text-[12px] text-gray-400" x-text="'@' + account.username"></span>
                                                <span x-show="account.is_primary" x-cloak class="rounded bg-brand-50 px-1.5 text-[10px] font-bold uppercase tracking-wide text-brand-700 dark:bg-brand-500/15 dark:text-brand-300" x-text="t.tag.listener"></span>
                                                <span x-show="account.excluded && !account.is_primary" x-cloak class="rounded bg-gray-200 px-1.5 text-[10px] font-bold uppercase tracking-wide text-gray-600 dark:bg-white/10 dark:text-gray-300" x-text="t.tag.excluded"></span>
                                                <span x-show="account.held" x-cloak class="rounded bg-error-50 px-1.5 text-[10px] font-bold uppercase tracking-wide text-error-700 dark:bg-error-500/10 dark:text-error-400" x-text="t.tag.held"></span>
                                            </div>
                                            <p x-show="account.is_primary" x-cloak class="mt-1 max-w-xs text-[11.5px] leading-snug text-gray-400" x-text="t.listener_hint"></p>
                                            <div x-show="account.held" x-cloak class="mt-1 flex items-center gap-2 text-[11.5px] text-error-600 dark:text-error-400">
                                                <span class="dc-break" x-text="t.held_reason + ': ' + (account.held_reason || dash) + (account.held_until ? ' · ' + t.held_until.replace(':time', date(account.held_until)) : '')"></span>
                                                <button type="button" class="font-medium underline" x-on:click="enable(account)" :disabled="locked" x-text="t.enable"></button>
                                            </div>
                                        </td>

                                        <td class="px-3 py-3 text-right text-[15px] font-semibold tabular-nums text-gray-950 dark:text-white" x-text="number(account.in_database)"></td>

                                        <td class="px-3 py-3 text-right text-[15px] font-semibold tabular-nums">
                                            <span x-show="account.in_account !== null" class="text-gray-950 dark:text-white" x-text="number(account.in_account)"></span>
                                            <span x-show="account.in_account === null" class="text-gray-300 dark:text-gray-600" x-text="dash"></span>
                                        </td>

                                        <td class="px-3 py-3">
                                            <x-driver-check.badge ::class="agreeClass(account)" ::title="agreeHint(account)">
                                                <span class="h-1.5 w-1.5 rounded-full" :class="agreeDot(account)"></span>
                                                <span x-text="agreeLabel(account)"></span>
                                            </x-driver-check.badge>

                                            {{-- Something queued, running or gone wrong on this account: always one line high, so the row never grows --}}
                                            <p class="mt-1 h-4 truncate text-[11.5px] font-medium leading-4"
                                                :class="account.status === 'failed' ? 'text-error-600 dark:text-error-400' : 'text-blue-light-700 dark:text-blue-light-400'"
                                                :title="account.error || ''"
                                                x-text="account.status === 'idle' ? '' : statusLabel(account) + (account.error ? ': ' + account.error : '')"></p>
                                        </td>

                                        <td class="whitespace-nowrap px-3 py-3 text-[12.5px] tabular-nums text-gray-500 dark:text-gray-400">
                                            <span x-show="account.synced_at" :title="account.synced_at" x-text="relative(account.synced_at)"></span>
                                            <span x-show="!account.synced_at" x-text="t.never"></span>
                                        </td>

                                        <td class="px-4 py-3">
                                            <div class="flex items-center justify-end gap-1.5">
                                                <x-driver-check.button size="sm" x-on:click="sync(account)" ::disabled="!canWork(account)">
                                                    <x-driver-check.icon name="sync" class="h-3.5 w-3.5" ::class="(busy === 'sync-' + account.id || (account.status === 'running' && account.action === 'import')) && 'animate-spin'" />
                                                    {{ __('driver_check.accounts.sync') }}
                                                </x-driver-check.button>

                                                <x-driver-check.button size="sm" x-on:click="clearAccount(account)" ::disabled="!canWork(account)" class="!text-error-600 dark:!text-error-400">
                                                    {{ __('driver_check.accounts.clear_account') }}
                                                </x-driver-check.button>

                                                <x-driver-check.button size="sm" variant="ghost" x-on:click="clearDatabase(account)" ::disabled="locked" class="!text-error-600 dark:!text-error-400">
                                                    {{ __('driver_check.accounts.clear_db') }}
                                                </x-driver-check.button>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Cards below lg --}}
                <div class="divide-y divide-gray-100 lg:hidden dark:divide-gray-800">
                    <template x-for="account in accounts" :key="'card-' + account.id">
                        <div class="flex flex-col gap-3 p-3.5">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold tabular-nums text-gray-900 dark:text-white" x-text="phone(account.phone)"></p>
                                    <p x-show="account.username" class="text-[12px] text-gray-400" x-text="'@' + account.username"></p>
                                    <p x-show="account.is_primary" x-cloak class="mt-1 text-[11.5px] leading-snug text-gray-400" x-text="t.listener_hint"></p>
                                </div>

                                <x-driver-check.badge ::class="agreeClass(account)">
                                    <span class="h-1.5 w-1.5 rounded-full" :class="agreeDot(account)"></span>
                                    <span x-text="agreeLabel(account)"></span>
                                </x-driver-check.badge>
                            </div>

                            <dl class="grid grid-cols-3 gap-2 rounded-xl bg-gray-50 p-3 dark:bg-white/[0.02]">
                                <x-driver-check.kv :label="__('driver_check.accounts.in_database')"><span class="text-base font-semibold tabular-nums" x-text="number(account.in_database)"></span></x-driver-check.kv>
                                <x-driver-check.kv :label="__('driver_check.accounts.in_account')"><span class="text-base font-semibold tabular-nums" x-text="account.in_account === null ? dash : number(account.in_account)"></span></x-driver-check.kv>
                                <x-driver-check.kv :label="__('driver_check.accounts.last_sync')"><span class="text-[12px]" x-text="account.synced_at ? relative(account.synced_at) : t.never"></span></x-driver-check.kv>
                            </dl>

                            <p class="h-4 truncate text-[12px] font-medium leading-4" :class="account.status === 'failed' ? 'text-error-600' : 'text-blue-light-700'" :title="account.error || ''" x-text="account.status === 'idle' ? '' : statusLabel(account) + (account.error ? ': ' + account.error : '')"></p>

                            <div class="grid grid-cols-2 gap-2">
                                <x-driver-check.button x-on:click="sync(account)" ::disabled="!canWork(account)" class="col-span-2">{{ __('driver_check.accounts.sync') }}</x-driver-check.button>
                                <x-driver-check.button x-on:click="clearAccount(account)" ::disabled="!canWork(account)" class="!text-error-600">{{ __('driver_check.accounts.clear_account') }}</x-driver-check.button>
                                <x-driver-check.button x-on:click="clearDatabase(account)" ::disabled="locked" class="!text-error-600">{{ __('driver_check.accounts.clear_db') }}</x-driver-check.button>
                            </div>
                        </div>
                    </template>
                </div>
            </x-driver-check.surface>
        </template>
    </x-driver-check.shell>
</div>

@endsection
