@extends('layouts.driver-check')

@section('title', __('driver_check.menu.group_invites'))

@section('content')

<div
    x-data="dcGroupInvites({
        endpoints: {
            show: @js(route('api.telegram.group-invites.show')),
            settings: @js(route('api.telegram.group-invites.settings')),
            run: @js(route('api.telegram.group-invites.run')),
            check: @js(route('api.telegram.group-invites.check')),
            results: @js(route('api.telegram.group-invites.results')),
            retry: @js(route('api.telegram.group-invites.retry')),
            groups: @js(url('/api/telegram/group-invites/groups')),
            accounts: @js(url('/api/telegram/group-invites/accounts')),
        },
        translations: @js(__('driver_check.invites')),
        ui: @js(__('telegram.ui')),
    })"
>
    <x-driver-check.shell>

        <x-driver-check.page-header
            :title="__('driver_check.menu.group_invites')"
            :description="__('driver_check.invites.description')"
        >
            <x-slot:actions>
                <x-driver-check.button
                    size="icon"
                    x-on:click="load(); loadResults()"
                    ::disabled="loading"
                    :title="__('telegram.ui.refresh')"
                    :aria-label="__('telegram.ui.refresh')"
                >
                    <x-driver-check.icon name="refresh" class="h-4 w-4" ::class="loading && 'animate-spin'" />
                </x-driver-check.button>

                <x-driver-check.button
                    icon="check-circle"
                    x-show="totals.sent > 0"
                    x-cloak
                    x-on:click="check()"
                    ::disabled="busy || running"
                    :title="__('driver_check.invites.check_hint')"
                >
                    {{ __('driver_check.invites.check') }}
                </x-driver-check.button>

                <x-driver-check.button variant="primary" icon="play" x-on:click="run()" ::disabled="busy || running">
                    <span x-text="running ? t.running : t.run"></span>
                </x-driver-check.button>
            </x-slot:actions>
        </x-driver-check.page-header>

        <x-driver-check.error-alert :title="__('driver_check.invites.load_failed')" retry="load()" />

        <div class="flex flex-col gap-5">

            {{-- The three that matter first: added, could be but were not, cannot be (junk). --}}
            <section class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
                <x-driver-check.stat-card :label="__('driver_check.invites.totals.invited')">
                    <span class="text-success-600 dark:text-success-400" x-text="number(totals.invited)"></span>
                    <x-slot:hint>
                        <span x-text="t.totals.invited_hint.replace(':today', number(totals.today)).replace(':username', number(totals.by_username))"></span>
                    </x-slot:hint>
                </x-driver-check.stat-card>
                <x-driver-check.stat-card :label="__('driver_check.invites.totals.not_added')" :hint="__('driver_check.invites.totals.not_added_hint')">
                    <span class="text-warning-600 dark:text-warning-400" x-text="number(totals.not_added)"></span>
                </x-driver-check.stat-card>
                <x-driver-check.stat-card :label="__('driver_check.invites.totals.failed')">
                    <span class="text-error-600 dark:text-error-400" x-text="number(totals.failed)"></span>
                    <x-slot:hint>
                        <span x-text="t.totals.failed_hint.replace(':n', number(totals.refused))"></span>
                    </x-slot:hint>
                </x-driver-check.stat-card>
                <x-driver-check.stat-card :label="__('driver_check.invites.totals.already')" :hint="__('driver_check.invites.totals.already_hint')">
                    <span x-text="number(totals.already)"></span>
                </x-driver-check.stat-card>
                <x-driver-check.stat-card :label="__('driver_check.invites.totals.sent')" :hint="__('driver_check.invites.totals.sent_hint')">
                    <span x-text="number(totals.sent)"></span>
                </x-driver-check.stat-card>
                <x-driver-check.stat-card :label="__('driver_check.invites.totals.batches')">
                    <span x-text="number(totals.batches_today)"></span>
                </x-driver-check.stat-card>
            </section>

            <div class="grid items-start gap-5 xl:grid-cols-2">

                <x-driver-check.surface class="overflow-hidden">
                    <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                        <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.groups.title') }}</h2>
                        <span x-show="running" x-cloak class="dc-dot animate-pulse text-blue-light-500"></span>
                    </div>

                    <form class="flex gap-2 border-b border-gray-100 p-4 dark:border-gray-800" x-on:submit.prevent="addGroup()">
                        <x-driver-check.input class="min-w-0 flex-1" x-model="link" placeholder="{{ __('driver_check.invites.groups.placeholder') }}" />
                        <x-driver-check.button type="submit" variant="primary" icon="plus" ::disabled="busy || link.trim() === ''">
                            {{ __('driver_check.invites.groups.add') }}
                        </x-driver-check.button>
                    </form>

                    <p x-show="groups.length === 0" x-cloak class="px-4 py-8 text-center text-[13px] text-gray-500 dark:text-gray-400">
                        {{ __('driver_check.invites.groups.empty') }}
                    </p>

                    <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                        <template x-for="group in groups" :key="group.id">
                            <li class="flex items-start gap-3 px-4 py-3">
                                <button
                                    type="button"
                                    class="relative mt-0.5 inline-flex h-6 w-10 shrink-0 items-center"
                                    x-on:click="toggleGroup(group)"
                                    :aria-pressed="group.is_active"
                                >
                                    <span class="absolute inset-0 rounded-full transition" :class="group.is_active ? 'bg-brand-500' : 'bg-gray-300 dark:bg-gray-700'"></span>
                                    <span class="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition" :class="group.is_active && 'translate-x-4'"></span>
                                </button>

                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-[13px] font-semibold text-gray-950 dark:text-white" x-text="group.title || group.link"></p>
                                    <p class="truncate text-[12px] text-gray-500" x-text="group.link"></p>
                                    <p class="mt-1 text-[12px] tabular-nums text-gray-500">
                                        <span class="text-success-600 dark:text-success-400" x-text="t.groups.invited.replace(':n', number(group.invited))"></span>
                                        <span class="px-1">·</span>
                                        <span class="text-warning-600 dark:text-warning-400" x-text="t.groups.not_added.replace(':n', number(group.not_added))"></span>
                                        <span class="px-1">·</span>
                                        <span class="text-error-600 dark:text-error-400" x-text="t.groups.failed.replace(':n', number(group.failed))"></span>
                                        <template x-if="group.already > 0">
                                            <span><span class="px-1">·</span><span x-text="t.groups.already.replace(':n', number(group.already))"></span></span>
                                        </template>
                                        <template x-if="group.sent > 0">
                                            <span><span class="px-1">·</span><span x-text="t.groups.sent.replace(':n', number(group.sent))"></span></span>
                                        </template>
                                    </p>
                                    <p
                                        x-show="group.members_before !== null || group.members_after !== null"
                                        x-cloak
                                        class="mt-0.5 text-[12px] tabular-nums text-gray-500"
                                        :title="t.groups.members_hint"
                                    >
                                        <span x-text="membersLine(group)"></span>
                                        <span
                                            x-show="membersGrew(group) !== null"
                                            class="ml-1 font-semibold"
                                            :class="membersGrew(group) > 0 ? 'text-success-600 dark:text-success-400' : 'text-gray-400'"
                                            x-text="'(' + (membersGrew(group) > 0 ? '+' : '') + number(membersGrew(group)) + ')'"
                                        ></span>
                                    </p>
                                    <p x-show="group.last_error" x-cloak class="mt-1 text-[12px] text-error-600 dark:text-error-400" x-text="errorLabel(group.last_error, true)" :title="group.last_error"></p>
                                </div>

                                <button type="button" class="shrink-0 text-gray-400 hover:text-error-600" x-on:click="deleteGroup(group)" :aria-label="t.groups.delete">
                                    <x-driver-check.icon name="trash" class="h-4 w-4" />
                                </button>
                            </li>
                        </template>
                    </ul>
                </x-driver-check.surface>

                <template x-if="settings">
                <form x-on:submit.prevent="save()">
                    <x-driver-check.surface class="overflow-hidden">
                        <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                            <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.settings.title') }}</h2>
                            <p class="mt-1 text-[12px] leading-snug text-gray-500" x-text="t.settings.per_account.replace(':n', number(perAccount()))"></p>
                        </div>

                        <div class="grid gap-4 p-4 sm:grid-cols-2">
                            <label class="flex cursor-pointer items-start gap-3 sm:col-span-2">
                                <span class="relative mt-0.5 inline-flex h-6 w-10 shrink-0 items-center">
                                    <input type="checkbox" x-model="settings.enabled" class="peer sr-only">
                                    <span class="absolute inset-0 rounded-full bg-gray-300 transition peer-checked:bg-brand-500 dark:bg-gray-700"></span>
                                    <span class="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-4"></span>
                                </span>
                                <span>
                                    <span class="block text-[13px] font-semibold text-gray-900 dark:text-white">{{ __('driver_check.invites.settings.enabled') }}</span>
                                    <span class="block text-[11.5px] leading-snug text-gray-400">{{ __('driver_check.invites.settings.enabled_hint') }}</span>
                                </span>
                            </label>

                            <x-driver-check.field :label="__('driver_check.invites.settings.schedule_at')" :hint="__('driver_check.invites.settings.schedule_at_hint')">
                                <x-driver-check.input type="time" x-model="settings.schedule_at" required />
                            </x-driver-check.field>

                            <x-driver-check.field :label="__('driver_check.invites.settings.batch_size')" :hint="__('driver_check.invites.settings.batch_size_hint')">
                                <x-driver-check.input type="number" inputmode="numeric" x-model="settings.batch_size" ::min="limits.batch_size?.[0]" ::max="limits.batch_size?.[1]" required />
                            </x-driver-check.field>

                            <x-driver-check.field :label="__('driver_check.invites.settings.batches_per_day')" :hint="__('driver_check.invites.settings.batches_per_day_hint')">
                                <x-driver-check.input type="number" inputmode="numeric" x-model="settings.batches_per_day" ::min="limits.batches_per_day?.[0]" ::max="limits.batches_per_day?.[1]" required />
                            </x-driver-check.field>

                            <x-driver-check.field :label="__('driver_check.invites.settings.delay_min')">
                                <x-driver-check.input type="number" inputmode="numeric" x-model="settings.delay_min" ::min="limits.delay_min?.[0]" ::max="limits.delay_min?.[1]" required />
                            </x-driver-check.field>

                            <x-driver-check.field :label="__('driver_check.invites.settings.delay_max')">
                                <x-driver-check.input type="number" inputmode="numeric" x-model="settings.delay_max" ::min="settings.delay_min" ::max="limits.delay_min?.[1]" required />
                            </x-driver-check.field>

                            <label class="flex cursor-pointer items-start gap-3 sm:col-span-2">
                                <span class="relative mt-0.5 inline-flex h-6 w-10 shrink-0 items-center">
                                    <input type="checkbox" x-model="settings.use_usernames" class="peer sr-only">
                                    <span class="absolute inset-0 rounded-full bg-gray-300 transition peer-checked:bg-brand-500 dark:bg-gray-700"></span>
                                    <span class="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-4"></span>
                                </span>
                                <span>
                                    <span class="block text-[13px] font-semibold text-gray-900 dark:text-white">{{ __('driver_check.invites.settings.use_usernames') }}</span>
                                    <span class="block text-[11.5px] leading-snug text-gray-400">{{ __('driver_check.invites.settings.use_usernames_hint') }}</span>
                                </span>
                            </label>

                            <div x-show="settings.use_usernames" x-cloak class="sm:col-span-2">
                                <x-driver-check.field :label="__('driver_check.invites.settings.usernames_per_day')" :hint="__('driver_check.invites.settings.usernames_per_day_hint')">
                                    <x-driver-check.input type="number" inputmode="numeric" x-model="settings.usernames_per_day" ::min="limits.usernames_per_day?.[0]" ::max="limits.usernames_per_day?.[1]" required />
                                </x-driver-check.field>
                            </div>
                        </div>

                        <div class="flex items-center justify-end gap-2 border-t border-gray-100 px-4 py-3 dark:border-gray-800">
                            <span x-show="dirty" x-cloak class="mr-auto text-[12px] text-gray-500">{{ __('driver_check.invites.settings.dirty') }}</span>
                            <x-driver-check.button type="submit" variant="primary" ::disabled="busy || !dirty">
                                {{ __('driver_check.invites.settings.save') }}
                            </x-driver-check.button>
                        </div>
                    </x-driver-check.surface>
                </form>
                </template>
            </div>

            <x-driver-check.surface class="overflow-hidden">
                <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                    <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.accounts.title') }}</h2>
                    <span class="text-[12px] tabular-nums text-gray-500" x-text="t.accounts.taking_part.replace(':n', takingPart())"></span>
                </div>

                <p x-show="accounts.length === 0" x-cloak class="px-4 py-8 text-center text-[13px] text-gray-500">{{ __('driver_check.invites.accounts.empty') }}</p>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[860px] text-left text-[13px]">
                        <thead class="text-[11px] uppercase tracking-wide text-gray-400">
                            <tr>
                                <th class="px-4 py-2 font-medium">{{ __('driver_check.invites.accounts.account') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('driver_check.invites.accounts.contacts') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('driver_check.invites.accounts.batches') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('driver_check.invites.accounts.usernames') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('driver_check.invites.accounts.invited') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('driver_check.invites.accounts.not_added') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('driver_check.invites.accounts.failed') }}</th>
                                <th class="px-4 py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <template x-for="account in accounts" :key="account.id">
                                <tr>
                                    <td class="px-4 py-2.5">
                                        <span class="font-medium text-gray-950 dark:text-white" x-text="account.phone"></span>
                                        <span x-show="isExcluded(account)" x-cloak class="ml-2 text-[11px] text-gray-400" x-text="t.accounts.excluded"></span>
                                        <p x-show="accountState(account)" x-cloak class="mt-0.5 text-[11.5px] text-warning-600 dark:text-warning-400" x-text="accountState(account)"></p>
                                    </td>
                                    <td class="px-4 py-2.5 tabular-nums" x-text="number(account.contacts)"></td>
                                    <td class="px-4 py-2.5 tabular-nums">
                                        <span x-text="number(account.batches_today) + ' / ' + number(account.cap)"></span>
                                        <p x-show="account.cap_lowered" x-cloak class="text-[11px] text-gray-400" x-text="t.accounts.cap_lowered"></p>
                                    </td>
                                    <td class="px-4 py-2.5 tabular-nums" x-text="number(account.usernames_today) + ' / ' + number(settings?.use_usernames ? settings.usernames_per_day : 0)"></td>
                                    <td class="px-4 py-2.5 tabular-nums">
                                        <span x-text="number(account.invited)"></span>
                                        <p x-show="account.sent > 0" x-cloak class="text-[11px] text-gray-400" x-text="t.groups.sent.replace(':n', number(account.sent))"></p>
                                    </td>
                                    <td class="px-4 py-2.5 tabular-nums" x-text="number(account.not_added)"></td>
                                    <td class="px-4 py-2.5 tabular-nums" x-text="number(account.failed)"></td>
                                    <td class="px-4 py-2.5 text-right">
                                        <button
                                            type="button"
                                            class="text-[12px] font-medium text-gray-400"
                                            x-on:click="toggleExcluded(account)"
                                            x-show="!account.is_primary"
                                            x-text="isExcluded(account) ? t.accounts.include : t.accounts.exclude"
                                        ></button>
                                        <button
                                            type="button"
                                            x-show="canEnable(account)"
                                            x-cloak
                                            class="ml-3 text-[12px] font-medium text-gray-950 underline-offset-2 hover:underline dark:text-white"
                                            x-on:click="enable(account)"
                                            x-text="t.accounts.enable"
                                            :title="account.held_reason || account.rest_reason || ''"
                                        ></button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </x-driver-check.surface>

            <x-driver-check.surface class="overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                    <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.results.title') }}</h2>
                    <div class="flex flex-wrap items-center gap-2">
                        <select class="rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-[13px] dark:border-gray-700 dark:bg-transparent dark:text-white" x-model="groupId" x-on:change="loadResults(1)" x-show="groups.length > 1" x-cloak>
                            <option value="">{{ __('driver_check.invites.results.all_groups') }}</option>
                            <template x-for="group in groups" :key="group.id">
                                <option :value="group.id" x-text="group.title || group.link"></option>
                            </template>
                        </select>
                        <select class="rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-[13px] dark:border-gray-700 dark:bg-transparent dark:text-white" x-model="status" x-on:change="loadResults(1)">
                            <option value="">{{ __('driver_check.invites.results.all') }}</option>
                            @foreach (['invited', 'not_added', 'failed', 'already', 'sent', 'pending'] as $option)
                                <option value="{{ $option }}">{{ __("driver_check.invites.results.filters.{$option}") }}</option>
                            @endforeach
                        </select>
                        <x-driver-check.button size="sm" x-show="retryable() > 0" x-cloak x-on:click="retryFailed()">
                            {{ __('driver_check.invites.results.retry') }}
                        </x-driver-check.button>
                    </div>
                </div>

                <p x-show="results.length === 0" x-cloak class="px-4 py-8 text-center text-[13px] text-gray-500">{{ __('driver_check.invites.results.empty') }}</p>

                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    <template x-for="row in results" :key="row.id">
                        <li class="flex items-start justify-between gap-3 px-4 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-[13px] font-medium text-gray-950 dark:text-white" x-text="row.user?.name || row.user?.username || row.user?.telegram_user_id || '—'"></p>
                                <p class="truncate text-[12px] text-gray-500">
                                    <span x-show="row.user?.username" x-text="'@' + row.user.username"></span>
                                    <span x-show="row.account" x-text="(row.user?.username ? ' · ' : '') + (row.account?.phone || '')"></span>
                                    <span x-show="row.group" x-text="' · ' + (row.group?.title || '')"></span>
                                    <span x-text="' · ' + (row.via === 'username' ? t.results.via_username : t.results.via_contact)"></span>
                                </p>
                                <p
                                    x-show="row.error"
                                    x-cloak
                                    class="mt-0.5 text-[12px]"
                                    :class="row.status === 'failed' ? 'text-error-600 dark:text-error-400' : (row.status === 'not_added' ? 'text-warning-600 dark:text-warning-400' : 'text-gray-500')"
                                    :title="row.error"
                                >
                                    <span x-text="errorLabel(row.error)"></span>
                                    <span x-show="row.user?.can_add_to_group === false" x-text="' · ' + t.results.refused"></span>
                                </p>
                            </div>
                            <span
                                class="shrink-0 rounded-md px-2 py-0.5 text-[11px] font-semibold"
                                :class="statusClass(row.status)"
                                x-text="statusLabel(row.status)"
                            ></span>
                        </li>
                    </template>
                </ul>

                <div x-show="meta.last_page > 1" x-cloak class="flex items-center justify-between border-t border-gray-100 px-4 py-3 text-[13px] dark:border-gray-800">
                    <button type="button" class="text-gray-600 disabled:opacity-40 dark:text-gray-300" :disabled="meta.current_page <= 1" x-on:click="loadResults(meta.current_page - 1)">{{ __('driver_check.invites.results.prev') }}</button>
                    <span class="tabular-nums text-gray-500" x-text="meta.current_page + ' / ' + meta.last_page"></span>
                    <button type="button" class="text-gray-600 disabled:opacity-40 dark:text-gray-300" :disabled="meta.current_page >= meta.last_page" x-on:click="loadResults(meta.current_page + 1)">{{ __('driver_check.invites.results.next') }}</button>
                </div>
            </x-driver-check.surface>
        </div>
    </x-driver-check.shell>
</div>

@endsection
