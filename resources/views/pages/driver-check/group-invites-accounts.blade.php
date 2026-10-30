@extends('layouts.driver-check')

@section('title', __('driver_check.menu.group_invites_accounts'))

@section('content')

<div
    x-data="dcGroupInvitesAccounts({
        endpoints: {
            index: @js(route('api.telegram.group-invites.accounts')),
        },
        translations: @js(__('driver_check.invites')),
    })"
>
    <x-driver-check.shell>

        <x-driver-check.page-header
            :title="__('driver_check.menu.group_invites_accounts')"
            :description="__('driver_check.invites.accounts.description')"
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

        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <x-driver-check.chips items="views()" selected="view" action="setView" />
            <p class="text-[12.5px] tabular-nums text-gray-500" x-text="t.accounts.limits_line
                .replace(':times', number(limits.batches_per_day))
                .replace(':size', number(limits.batch_size))
                .replace(':lookups', number(limits.usernames_per_day))"></p>
        </div>

        <div x-show="!loaded && loading" class="grid gap-3 lg:grid-cols-2">
            <template x-for="i in 4" :key="'sk-' + i">
                <div class="dc-skeleton h-48 rounded-2xl bg-gray-100 dark:bg-white/[0.06]"></div>
            </template>
        </div>

        <p x-show="loaded && shown().length === 0" x-cloak class="py-12 text-center text-[13px] text-gray-500">{{ __('driver_check.invites.accounts.empty') }}</p>

        <div class="grid gap-4 lg:grid-cols-2">
            <template x-for="account in shown()" :key="account.id">
                <x-driver-check.surface class="flex flex-col overflow-hidden" x-bind:class="account.excluded && 'opacity-70'">

                    {{-- Who, in or out, and how it stands --}}
                    <div class="flex items-start justify-between gap-3 px-4 pt-4">
                        <div class="flex min-w-0 items-start gap-2.5">
                            <span class="dc-dot mt-[7px] shrink-0" :class="dot(account)"></span>
                            <div class="min-w-0">
                                <p class="truncate text-[15px] font-semibold tabular-nums text-gray-950 dark:text-white" x-text="phone(account.phone) || ('#' + account.id)"></p>
                                <p class="text-[12px] leading-snug" :class="account.held ? 'text-error-600 dark:text-error-400' : (account.rest_until ? 'text-warning-600 dark:text-warning-400' : 'text-gray-500')" x-text="stateLine(account)"></p>
                            </div>
                        </div>

                        <label class="flex shrink-0 cursor-pointer items-center gap-2 text-[12px] font-medium text-gray-600 dark:text-gray-300" :class="account.listener && 'cursor-not-allowed opacity-60'">
                            <span x-text="account.excluded ? t.accounts.out : t.accounts.in"></span>
                            <span class="relative inline-flex h-6 w-10 items-center">
                                <input type="checkbox" class="peer sr-only" :checked="!account.excluded" :disabled="account.listener || busy === account.id" x-on:change="toggle(account)">
                                <span class="absolute inset-0 rounded-full bg-gray-300 transition peer-checked:bg-brand-500 dark:bg-gray-700"></span>
                                <span class="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-4"></span>
                            </span>
                        </label>
                    </div>

                    {{-- Its day --}}
                    <div class="px-4 pt-4">
                        <div class="flex items-baseline justify-between gap-3 text-[12px] text-gray-500">
                            <span>{{ __('driver_check.invites.accounts.calls_today') }}</span>
                            <span class="tabular-nums">
                                <span class="text-[14px] font-semibold text-gray-950 dark:text-white" x-text="number(account.calls_today)"></span>
                                <span x-text="' / ' + number(account.cap)"></span>
                            </span>
                        </div>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                            <div class="h-full rounded-full transition-all" :class="account.cap_lowered ? 'bg-warning-500' : 'bg-brand-500'" :style="`width: ${callsShare(account)}%`"></div>
                        </div>
                        <p x-show="account.cap_lowered" x-cloak class="mt-1 text-[11.5px] text-warning-600 dark:text-warning-400" x-text="t.accounts.cap_lowered.replace(':n', account.peer_floods)"></p>
                    </div>

                    {{-- The numbers --}}
                    <dl class="mt-4 grid grid-cols-3 border-y border-gray-100 text-center dark:border-gray-800">
                        <div class="px-2 py-2.5">
                            <dt class="text-[10.5px] uppercase tracking-wide text-gray-400">{{ __('driver_check.invites.accounts.added_today') }}</dt>
                            <dd class="mt-0.5 text-[17px] font-semibold tabular-nums text-gray-950 dark:text-white" x-text="number(account.added_today)"></dd>
                        </div>
                        <div class="border-x border-gray-100 px-2 py-2.5 dark:border-gray-800">
                            <dt class="text-[10.5px] uppercase tracking-wide text-gray-400">{{ __('driver_check.invites.accounts.added') }}</dt>
                            <dd class="mt-0.5 text-[17px] font-semibold tabular-nums text-gray-950 dark:text-white">
                                <span x-text="number(account.added)"></span>
                                <span x-show="account.left > 0" x-cloak class="text-[11px] font-normal text-gray-400" x-text="t.accounts.of_them_left.replace(':n', number(account.left))"></span>
                            </dd>
                        </div>
                        <div class="px-2 py-2.5">
                            <dt class="text-[10.5px] uppercase tracking-wide text-gray-400">{{ __('driver_check.invites.accounts.lookups_today') }}</dt>
                            <dd class="mt-0.5 text-[17px] font-semibold tabular-nums text-gray-950 dark:text-white" x-text="number(account.lookups_today) + ' / ' + number(limits.usernames_per_day)"></dd>
                        </div>
                    </dl>

                    <dl class="grid grid-cols-2 gap-x-4 gap-y-1.5 px-4 py-3 text-[12.5px]">
                        <div class="flex justify-between gap-2">
                            <dt class="text-gray-500">{{ __('driver_check.invites.accounts.contacts') }}</dt>
                            <dd class="tabular-nums text-gray-950 dark:text-white" x-text="number(account.contacts)"></dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-gray-500">{{ __('driver_check.invites.accounts.not_added') }}</dt>
                            <dd class="tabular-nums text-gray-950 dark:text-white" x-text="number(account.not_added)"></dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-gray-500">{{ __('driver_check.invites.accounts.contacts_can') }}</dt>
                            <dd class="tabular-nums text-success-700 dark:text-success-400" x-text="number(account.contacts_can)"></dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-gray-500">{{ __('driver_check.invites.accounts.failed') }}</dt>
                            <dd class="tabular-nums text-gray-950 dark:text-white" x-text="number(account.failed)"></dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-gray-500">{{ __('driver_check.invites.accounts.contacts_cannot') }}</dt>
                            <dd class="tabular-nums text-error-700 dark:text-error-400" x-text="number(account.contacts_cannot)"></dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-gray-500">{{ __('driver_check.invites.accounts.sent') }}</dt>
                            <dd class="tabular-nums text-gray-950 dark:text-white" x-text="number(account.sent)"></dd>
                        </div>
                    </dl>

                    <div class="mt-auto flex items-center justify-between gap-3 border-t border-gray-100 bg-gray-25 px-4 py-2.5 dark:border-gray-800 dark:bg-white/[0.015]">
                        <p class="min-w-0 truncate text-[11.5px] text-gray-500" x-text="lastStopLine(account) || t.accounts.no_stops"></p>
                        <x-driver-check.button size="sm" x-show="canEnable(account)" x-cloak x-on:click="enable(account)" ::disabled="busy === account.id" x-bind:title="account.held_reason || account.rest_reason || ''">
                            {{ __('driver_check.invites.accounts.enable') }}
                        </x-driver-check.button>
                    </div>
                </x-driver-check.surface>
            </template>
        </div>
    </x-driver-check.shell>
</div>

@endsection
