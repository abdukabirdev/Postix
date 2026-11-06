@extends('layouts.driver-check')

@section('title', __('driver_check.tree.pages.spam_admins'))

@php
    /* Who may what: [role, links stay, may forward spam to the bot, gets the reports and the copies]. */
    $roles = [
        ['owner', true, true, true],
        ['bot_admins', true, true, false],
        ['group_admins', true, false, false],
        ['others', false, false, false],
        ['spammers', false, false, false],
    ];
@endphp

@section('content')

<div
    x-data="dcGroupInvitesSpamAdmins({
        endpoints: {
            show: @js(route('api.telegram.group-invites.spam.admins')),
            groups: @js(route('api.telegram.group-invites.spam.admins.groups')),
            store: @js(route('api.telegram.group-invites.spam.admins.store')),
            invite: @js(route('api.telegram.group-invites.spam.admins.invite')),
            destroy: @js(route('api.telegram.group-invites.spam.admins.destroy', ['spamAdmin' => '__ID__'])),
        },
        translations: @js(__('driver_check.invites')),
    })"
>
    <x-driver-check.shell>

        <x-driver-check.page-header
            :title="__('driver_check.tree.pages.spam_admins')"
            :description="__('driver_check.invites.spam.admins.description')"
        >
            <x-slot:actions>
                <x-driver-check.button
                    size="icon"
                    x-on:click="refresh()"
                    ::disabled="loading || groupsLoading"
                    :title="__('telegram.ui.refresh')"
                    :aria-label="__('telegram.ui.refresh')"
                >
                    <x-driver-check.icon name="refresh" class="h-4 w-4" ::class="(loading || groupsLoading) && 'animate-spin'" />
                </x-driver-check.button>
            </x-slot:actions>
        </x-driver-check.page-header>

        {{-- Who may what: the whole rule on one card --}}
        <x-driver-check.surface class="overflow-hidden">
            <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.spam.admins.roles.title') }}</h2>
                <p class="mt-0.5 text-[12px] leading-snug text-gray-400">{{ __('driver_check.invites.spam.admins.roles.rule') }}</p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[560px] text-left text-[12.5px]">
                    <thead>
                        <tr class="border-b border-gray-100 text-[11.5px] font-medium text-gray-500 dark:border-gray-800">
                            <th class="px-4 py-2 font-medium">{{ __('driver_check.invites.spam.admins.roles.who') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('driver_check.invites.spam.admins.roles.links') }}</th>
                            <th class="px-3 py-2 text-center font-medium">{{ __('driver_check.invites.spam.admins.roles.forward') }}</th>
                            <th class="px-3 py-2 text-center font-medium">{{ __('driver_check.invites.spam.admins.roles.reports') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($roles as [$role, $keeps, $forwards, $reports])
                            <tr>
                                <td class="px-4 py-2.5 align-top">
                                    <p class="font-medium text-gray-950 dark:text-white">{{ __("driver_check.invites.spam.admins.roles.rows.{$role}.name") }}</p>
                                    <p class="text-[11.5px] text-gray-400">{{ __("driver_check.invites.spam.admins.roles.rows.{$role}.hint") }}</p>
                                </td>
                                <td class="px-3 py-2.5 align-top">
                                    <span @class([
                                        'inline-flex rounded-md px-2 py-0.5 text-[11.5px] font-medium',
                                        'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' => $keeps,
                                        'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-400' => ! $keeps,
                                    ])>{{ __("driver_check.invites.spam.admins.roles.rows.{$role}.links") }}</span>
                                </td>
                                <td class="px-3 py-2.5 text-center align-top">
                                    @if ($forwards)
                                        <x-driver-check.icon name="check" class="mx-auto h-4 w-4 text-success-500" />
                                    @else
                                        <span class="text-gray-300 dark:text-gray-600">—</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 text-center align-top">
                                    @if ($reports)
                                        <x-driver-check.icon name="check" class="mx-auto h-4 w-4 text-success-500" />
                                    @else
                                        <span class="text-gray-300 dark:text-gray-600">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-driver-check.surface>

        <x-driver-check.error-alert :title="__('driver_check.invites.load_failed')" retry="load()" />

        <div x-show="!loaded && loading" class="dc-skeleton h-40 rounded-2xl bg-gray-100 dark:bg-white/[0.06]"></div>

        <template x-if="loaded">
            <div class="grid items-start gap-5 lg:grid-cols-[minmax(0,380px)_minmax(0,1fr)]">

                <div class="flex flex-col gap-5">

                    {{-- Put on by ID, @username or a link to the profile --}}
                    <x-driver-check.surface class="overflow-hidden">
                        <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                            <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.spam.admins.add_title') }}</h2>
                        </div>

                        <form class="flex flex-col gap-3 p-4" x-on:submit.prevent="add()">
                            <x-driver-check.field :label="__('driver_check.invites.spam.admins.entries')" :hint="__('driver_check.invites.spam.admins.entries_hint')" for="dc-admin-entries">
                                <textarea
                                    id="dc-admin-entries"
                                    x-model="entries"
                                    rows="3"
                                    spellcheck="false"
                                    autocomplete="off"
                                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-[13px] text-gray-900 outline-none transition placeholder:text-gray-400 hover:border-gray-400 focus:border-brand-500 focus:ring-[3px] focus:ring-brand-500/15 dark:border-gray-700 dark:bg-gray-950 dark:text-white dark:placeholder:text-gray-500"
                                    placeholder="{{ __('driver_check.invites.spam.admins.entries_placeholder') }}"
                                ></textarea>
                            </x-driver-check.field>

                            <x-driver-check.field :label="__('driver_check.invites.spam.note')" for="dc-admin-note">
                                <x-driver-check.input id="dc-admin-note" maxlength="255" x-model="note" :placeholder="__('driver_check.invites.spam.admins.note_placeholder')" />
                            </x-driver-check.field>

                            <x-driver-check.button type="submit" variant="primary" icon="plus" ::disabled="busy || entries.trim() === ''">
                                {{ __('driver_check.invites.spam.admins.add') }}
                            </x-driver-check.button>
                        </form>
                    </x-driver-check.surface>

                    {{-- Or an invite link: they open the bot, press Start, and are one --}}
                    <x-driver-check.surface class="overflow-hidden">
                        <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                            <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.spam.admins.invite_title') }}</h2>
                            <p class="mt-0.5 text-[12px] leading-snug text-gray-400" x-text="s.admins.invite_hint.replace(':days', data.invite_days)"></p>
                        </div>

                        <div class="flex flex-col gap-3 p-4">
                            <p x-show="!data.bot.connected" x-cloak class="rounded-lg bg-warning-50 px-3 py-2 text-[12.5px] text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">{{ __('driver_check.invites.spam.admins.no_bot') }}</p>

                            <form class="flex flex-col gap-3" x-on:submit.prevent="makeInvite()">
                                <x-driver-check.field :label="__('driver_check.invites.spam.admins.invite_for')" for="dc-admin-invite-note">
                                    <x-driver-check.input id="dc-admin-invite-note" maxlength="255" x-model="inviteNote" :placeholder="__('driver_check.invites.spam.admins.invite_for_placeholder')" />
                                </x-driver-check.field>

                                <x-driver-check.button type="submit" icon="link" ::disabled="busy || !data.bot.connected">
                                    {{ __('driver_check.invites.spam.admins.invite_make') }}
                                </x-driver-check.button>
                            </form>

                            {{-- The link just made, to copy and send --}}
                            <div x-show="made" x-cloak class="flex flex-col gap-2 rounded-xl border border-success-200 bg-success-50/60 p-3 dark:border-success-500/30 dark:bg-success-500/10">
                                <p class="text-[12px] font-medium text-success-700 dark:text-success-400">{{ __('driver_check.invites.spam.admins.invite_ready') }}</p>
                                <div class="flex items-center gap-2">
                                    <input
                                        type="text"
                                        readonly
                                        x-ref="madeLink"
                                        :value="made"
                                        x-on:focus="$event.target.select()"
                                        class="min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-[12.5px] text-gray-900 outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    >
                                    <x-driver-check.button size="sm" variant="primary" icon="copy" x-on:click="copy(made, $refs.madeLink)">
                                        <span x-text="copied === made ? s.admins.copied : s.admins.copy"></span>
                                    </x-driver-check.button>
                                </div>
                            </div>
                        </div>
                    </x-driver-check.surface>
                </div>

                <div class="flex flex-col gap-5">

                    {{-- The admins of the bot: you, the list, the links nobody opened yet --}}
                    <x-driver-check.surface class="overflow-hidden">
                        <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                            <h2 class="text-sm font-semibold text-gray-950 dark:text-white">
                                {{ __('driver_check.invites.spam.admins.list_title') }}
                                <span class="ml-1 font-normal tabular-nums text-gray-400" x-text="number(data.admins.length + (data.owner ? 1 : 0))"></span>
                            </h2>
                            <p class="mt-0.5 text-[12px] text-gray-400">{{ __('driver_check.invites.spam.admins.list_hint') }}</p>
                        </div>

                        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                            {{-- The owner: whoever the reports go to, always --}}
                            <li x-show="data.owner" class="flex items-start gap-3 px-4 py-3">
                                <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                                    <x-driver-check.icon name="user" class="h-4 w-4" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-[13.5px] font-medium text-gray-950 dark:text-white">{{ __('driver_check.invites.spam.admins.owner') }}</p>
                                    <p class="text-[11.5px] tabular-nums text-gray-400" x-text="'ID ' + data.owner"></p>
                                    <p class="mt-0.5 text-[12px] text-gray-500">{{ __('driver_check.invites.spam.admins.owner_hint') }}</p>
                                </div>
                            </li>

                            <template x-for="admin in data.admins" :key="admin.id">
                                <li class="flex items-start gap-3 px-4 py-3">
                                    <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gray-100 text-[13px] font-semibold text-gray-600 dark:bg-white/[0.06] dark:text-gray-300" x-text="(who(admin) || '?').replace(/^[@#]|^ID /, '').charAt(0).toUpperCase()"></span>

                                    <div class="min-w-0 flex-1">
                                        <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[13.5px] text-gray-950 dark:text-white">
                                            <template x-if="admin.username">
                                                <a class="font-medium hover:underline" :href="'https://t.me/' + admin.username" target="_blank" rel="noopener" x-text="who(admin)"></a>
                                            </template>
                                            <span x-show="!admin.username" class="font-medium" x-text="who(admin)"></span>
                                            <span x-show="!admin.telegram_user_id" x-cloak class="rounded-md bg-warning-50 px-1.5 py-0.5 text-[10.5px] font-semibold text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">{{ __('driver_check.invites.spam.id_unknown') }}</span>
                                        </p>
                                        <p x-show="whoLine(admin)" class="truncate text-[11.5px] tabular-nums text-gray-400" x-text="whoLine(admin)"></p>
                                        <p x-show="admin.note" x-cloak class="mt-0.5 text-[12px] text-gray-600 dark:text-gray-300" x-text="admin.note"></p>
                                        <p class="mt-1 text-[11.5px] text-gray-500" x-text="(admin.by_invite ? s.admins.by_invite : s.admins.by_hand).replace(':time', time(admin.added_at))"></p>
                                    </div>

                                    <x-driver-check.row-action icon="trash" :label="__('driver_check.invites.spam.admins.remove')" x-on:click="remove(admin)" ::disabled="busy" class="hover:!text-error-600 dark:hover:!text-error-400" />
                                </li>
                            </template>

                            <li x-show="!data.owner && data.admins.length === 0" x-cloak class="px-4 py-12 text-center text-[13px] text-gray-500">{{ __('driver_check.invites.spam.admins.empty') }}</li>
                        </ul>

                        {{-- The invite links not opened yet --}}
                        <div x-show="data.invites.length > 0" x-cloak>
                            <div class="border-y border-gray-100 bg-gray-50/60 px-4 py-2 dark:border-gray-800 dark:bg-white/[0.02]">
                                <h3 class="text-[12px] font-semibold uppercase tracking-wide text-gray-500">{{ __('driver_check.invites.spam.admins.invites_title') }}</h3>
                            </div>

                            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                                <template x-for="invite in data.invites" :key="invite.id">
                                    <li class="flex items-start gap-3 px-4 py-3">
                                        <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-white/[0.06] dark:text-gray-400">
                                            <x-driver-check.icon name="link" class="h-4 w-4" />
                                        </span>

                                        <div class="min-w-0 flex-1">
                                            <p class="text-[13.5px] font-medium text-gray-950 dark:text-white" x-text="invite.note || s.admins.invite_unnamed"></p>
                                            <p class="truncate font-mono text-[11.5px] text-gray-400" x-text="invite.link || '—'"></p>
                                            <p class="mt-1 text-[11.5px]" :class="invite.expired ? 'text-error-600 dark:text-error-400' : 'text-gray-500'" x-text="invite.expired ? s.admins.invite_expired : s.admins.invite_until.replace(':time', time(invite.expires_at))"></p>
                                        </div>

                                        <div class="flex shrink-0 items-center gap-1">
                                            <x-driver-check.row-action icon="copy" :label="__('driver_check.invites.spam.admins.copy')" x-show="invite.link && !invite.expired" x-on:click="copy(invite.link)" />
                                            <x-driver-check.row-action icon="trash" :label="__('driver_check.invites.spam.admins.invite_remove')" x-on:click="remove(invite, true)" ::disabled="busy" class="hover:!text-error-600 dark:hover:!text-error-400" />
                                        </div>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </x-driver-check.surface>

                    {{-- The admins of each group, as Telegram has them --}}
                    <x-driver-check.surface class="overflow-hidden">
                        <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                            <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.spam.admins.groups_title') }}</h2>
                            <p class="mt-0.5 text-[12px] leading-snug text-gray-400">{{ __('driver_check.invites.spam.admins.groups_hint') }}</p>
                        </div>

                        <div x-show="groupsLoading && !groupsLoaded" class="p-4">
                            <div class="dc-skeleton h-16 rounded-xl bg-gray-100 dark:bg-white/[0.06]"></div>
                        </div>

                        <p x-show="groupsLoaded && groups.length === 0" x-cloak class="px-4 py-8 text-center text-[13px] text-gray-500">{{ __('driver_check.invites.spam.admins.groups_none') }}</p>

                        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                            <template x-for="group in groups" :key="group.id">
                                <li class="flex flex-col gap-2 px-4 py-3">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <p class="min-w-0 truncate text-[13.5px] font-medium text-gray-950 dark:text-white" x-text="group.title"></p>
                                        <span class="inline-flex shrink-0 items-center rounded-md px-2 py-0.5 text-[11.5px] font-medium" :class="botBadge(group).tone" x-text="botBadge(group).text" :title="group.error || ''"></span>
                                    </div>

                                    <p x-show="group.bot === 'ok' && group.admins.length === 0" x-cloak class="text-[12px] text-gray-500">{{ __('driver_check.invites.spam.admins.group_no_admins') }}</p>

                                    <div x-show="group.admins.length > 0" class="flex flex-wrap gap-1.5">
                                        <template x-for="admin in group.admins" :key="admin.id">
                                            <span class="inline-flex max-w-full items-center gap-1 rounded-lg bg-gray-100 px-2 py-1 text-[12px] text-gray-700 dark:bg-white/[0.06] dark:text-gray-200" :title="'ID ' + admin.id">
                                                <span class="truncate" x-text="groupAdminName(admin)"></span>
                                                <span x-show="groupAdminTag(admin)" class="shrink-0 text-[10.5px] font-semibold text-gray-400" x-text="groupAdminTag(admin)"></span>
                                            </span>
                                        </template>
                                    </div>
                                </li>
                            </template>
                        </ul>
                    </x-driver-check.surface>
                </div>
            </div>
        </template>
    </x-driver-check.shell>
</div>

@endsection
