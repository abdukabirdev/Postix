@extends('layouts.driver-check')

@section('title', __('driver_check.tree.pages.spam_' . $part))

@section('content')

<div
    x-data="dcGroupInvitesSpam({
        endpoints: {
            show: @js(route('api.telegram.group-invites.spam')),
            settings: @js(route('api.telegram.group-invites.spam.settings')),
            store: @js(route('api.telegram.group-invites.spam.store')),
            destroy: @js(route('api.telegram.group-invites.spam.destroy', ['spamSender' => '__ID__'])),
            groups: @js(route('api.telegram.group-invites.bot.groups')),
        },
        translations: @js(__('driver_check.invites')),
    })"
>
    <x-driver-check.shell>

        <x-driver-check.page-header
            :title="__('driver_check.tree.pages.spam_' . $part)"
            :description="$part === 'list' ? __('driver_check.invites.spam.description') : __('driver_check.pages.spam_messages')"
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

        <x-driver-check.error-alert :title="__('driver_check.invites.load_failed')" retry="load()" />

        <div x-show="!loaded && loading" class="dc-skeleton h-40 rounded-2xl bg-gray-100 dark:bg-white/[0.06]"></div>

        <template x-if="loaded">
            <div class="flex flex-col gap-5">

                @if ($part === 'list')
                {{-- Who deletes: the bot, watching or not --}}
                <div
                    class="flex flex-col gap-2 rounded-2xl border px-4 py-3 text-[13px] sm:flex-row sm:items-center sm:justify-between"
                    :class="botState().bad
                        ? 'border-error-200 bg-error-50/60 text-error-700 dark:border-error-500/30 dark:bg-error-500/10 dark:text-error-400'
                        : 'border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-300'"
                >
                    <p class="flex items-start gap-2">
                        <span class="dc-dot mt-1.5 shrink-0" :class="botState().tone"></span>
                        <span>
                            <span x-text="botState().text"></span>
                            <span x-show="bot.connected && !data.reports.enabled && data.settings.notify" x-cloak class="block text-[12px] text-warning-700 dark:text-warning-400">{{ __('driver_check.invites.spam.reports_off') }}</span>
                        </span>
                    </p>
                    <x-driver-check.button size="sm" variant="ghost" :href="route('driver-check.group-invites.bot')" icon="bot">
                        {{ __('driver_check.invites.spam.open_bot') }}
                    </x-driver-check.button>
                </div>

                @endif

                {{-- In numbers --}}
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <x-driver-check.stat-card :label="__('driver_check.invites.spam.stats.list')" icon="users">
                        <span x-text="number(data.senders.length)"></span>
                    </x-driver-check.stat-card>
                    <x-driver-check.stat-card :label="__('driver_check.invites.spam.stats.today')" icon="trash">
                        <span x-text="number(data.today.deleted)"></span>
                    </x-driver-check.stat-card>
                    <x-driver-check.stat-card :label="__('driver_check.invites.spam.stats.pending')" icon="clock">
                        <span x-text="number(data.pending)"></span>
                    </x-driver-check.stat-card>
                    <x-driver-check.stat-card :label="__('driver_check.invites.spam.stats.total')" icon="shield">
                        <span x-text="number(data.total_deleted)"></span>
                    </x-driver-check.stat-card>
                </div>

                @if ($part === 'list')
                <div class="grid items-start gap-5 lg:grid-cols-[minmax(0,360px)_minmax(0,1fr)]">

                    <div class="flex flex-col gap-5">

                        {{-- Put on the list --}}
                        <x-driver-check.surface class="overflow-hidden">
                            <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.spam.add_title') }}</h2>
                            </div>

                            <form class="flex flex-col gap-3 p-4" x-on:submit.prevent="add()">
                                <x-driver-check.field :label="__('driver_check.invites.spam.entries')" :hint="__('driver_check.invites.spam.entries_hint')" for="dc-spam-entries">
                                    <textarea
                                        id="dc-spam-entries"
                                        x-model="entries"
                                        rows="4"
                                        spellcheck="false"
                                        autocomplete="off"
                                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-[13px] text-gray-900 outline-none transition placeholder:text-gray-400 hover:border-gray-400 focus:border-brand-500 focus:ring-[3px] focus:ring-brand-500/15 dark:border-gray-700 dark:bg-gray-950 dark:text-white dark:placeholder:text-gray-500"
                                        placeholder="{{ __('driver_check.invites.spam.entries_placeholder') }}"
                                    ></textarea>
                                </x-driver-check.field>

                                <x-driver-check.field :label="__('driver_check.invites.spam.note')" for="dc-spam-note">
                                    <x-driver-check.input id="dc-spam-note" maxlength="255" x-model="note" :placeholder="__('driver_check.invites.spam.note_placeholder')" />
                                </x-driver-check.field>

                                <x-driver-check.button type="submit" variant="primary" icon="plus" ::disabled="busy || entries.trim() === ''">
                                    {{ __('driver_check.invites.spam.add') }}
                                </x-driver-check.button>
                            </form>
                        </x-driver-check.surface>

                        {{-- How long a message stays, whether the owner is told --}}
                        <x-driver-check.surface class="overflow-hidden">
                            <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.spam.settings_title') }}</h2>
                            </div>

                            <form class="flex flex-col gap-4 p-4" x-on:submit.prevent="saveSettings()">
                                <x-driver-check.field :label="__('driver_check.invites.spam.delay')" :hint="__('driver_check.invites.spam.delay_hint')" for="dc-spam-delay">
                                    <x-driver-check.input id="dc-spam-delay" type="number" min="0" ::max="data.max_delay" step="1" inputmode="numeric" class="sm:w-32" x-model.number="delay" />
                                </x-driver-check.field>

                                <div>
                                    <label class="flex cursor-pointer items-center gap-2 text-[12.5px] font-medium text-gray-700 dark:text-gray-200">
                                        <span class="relative inline-flex h-6 w-10 shrink-0 items-center">
                                            <input type="checkbox" class="peer sr-only" x-model="notifyOn" :disabled="busy">
                                            <span class="absolute inset-0 rounded-full bg-gray-300 transition peer-checked:bg-brand-500 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/50 dark:bg-gray-700"></span>
                                            <span class="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-4"></span>
                                        </span>
                                        <span>{{ __('driver_check.invites.spam.notify') }}</span>
                                    </label>
                                    <p class="mt-1.5 text-[11.5px] leading-snug text-gray-400" x-text="s.notify_hint.replace(':chat', data.reports.chat_id || '—')"></p>
                                </div>

                                <x-driver-check.button type="submit" ::disabled="busy" class="self-start">
                                    {{ __('driver_check.invites.spam.save') }}
                                </x-driver-check.button>
                            </form>
                        </x-driver-check.surface>

                        {{-- Where the bot may delete --}}
                        <x-driver-check.surface class="overflow-hidden">
                            <div class="flex items-start justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                                <div class="min-w-0">
                                    <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.spam.groups_title') }}</h2>
                                    <p class="mt-0.5 text-[12px] leading-snug text-gray-400">{{ __('driver_check.invites.spam.groups_hint') }}</p>
                                </div>
                                <span x-show="groupsLoaded && groups.length > 0" x-cloak class="shrink-0 text-[12px] tabular-nums text-gray-500" x-text="s.groups_ready.replace(':n', groupsReady()).replace(':all', groups.length)"></span>
                            </div>

                            <div x-show="groupsLoading && !groupsLoaded" class="p-4">
                                <div class="dc-skeleton h-12 rounded-xl bg-gray-100 dark:bg-white/[0.06]"></div>
                            </div>

                            <p x-show="groupsLoaded && groups.length === 0" x-cloak class="px-4 py-6 text-center text-[13px] text-gray-500">{{ __('driver_check.invites.spam.no_groups') }}</p>

                            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                                <template x-for="group in groups" :key="group.id">
                                    <li class="flex flex-col gap-1 px-4 py-2.5">
                                        <p class="truncate text-[13px] font-medium text-gray-950 dark:text-white" x-text="group.title"></p>
                                        <span class="inline-flex w-fit items-center rounded-md px-2 py-0.5 text-[11.5px] font-medium" :class="groupDelete(group).tone" x-text="groupDelete(group).text" :title="group.error || ''"></span>
                                    </li>
                                </template>
                            </ul>
                        </x-driver-check.surface>
                    </div>

                    {{-- The list --}}
                    <x-driver-check.surface class="overflow-hidden">
                        <div class="flex items-start justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                            <div class="min-w-0">
                                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">
                                    {{ __('driver_check.invites.spam.list_title') }}
                                    <span x-show="data.senders.length > 0" x-cloak class="ml-1 font-normal tabular-nums text-gray-400" x-text="number(data.senders.length)"></span>
                                </h2>
                                <p class="mt-0.5 text-[12px] text-gray-400">{{ __('driver_check.invites.spam.list_hint') }}</p>
                            </div>
                        </div>

                        <div x-show="data.senders.length === 0" x-cloak class="flex flex-col items-center gap-2 px-4 py-14 text-center">
                            <x-driver-check.icon name="shield" class="h-6 w-6 text-gray-300 dark:text-gray-600" />
                            <p class="text-[13px] text-gray-500">{{ __('driver_check.invites.spam.list_empty') }}</p>
                        </div>

                        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                            <template x-for="sender in data.senders" :key="sender.id">
                                <li class="flex items-start gap-3 px-4 py-3">
                                    <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gray-100 text-[13px] font-semibold text-gray-600 dark:bg-white/[0.06] dark:text-gray-300" x-text="(who(sender) || '?').replace(/^[@#]|^ID /, '').charAt(0).toUpperCase()"></span>

                                    <div class="min-w-0 flex-1">
                                        <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[13.5px] text-gray-950 dark:text-white">
                                            <template x-if="sender.username">
                                                <a class="font-medium hover:underline" :href="'https://t.me/' + sender.username" target="_blank" rel="noopener" x-text="who(sender)"></a>
                                            </template>
                                            <span x-show="!sender.username" class="font-medium" x-text="who(sender)"></span>
                                            <span x-show="!sender.telegram_user_id" x-cloak class="rounded-md bg-warning-50 px-1.5 py-0.5 text-[10.5px] font-semibold text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">{{ __('driver_check.invites.spam.id_unknown') }}</span>
                                        </p>
                                        <p x-show="whoLine(sender)" class="truncate text-[11.5px] tabular-nums text-gray-400" x-text="whoLine(sender)"></p>
                                        <p x-show="sender.note" x-cloak class="mt-0.5 text-[12px] text-gray-600 dark:text-gray-300" x-text="sender.note"></p>
                                        <p class="mt-1 text-[11.5px] text-gray-500" x-text="senderStats(sender)"></p>
                                    </div>

                                    <x-driver-check.row-action icon="trash" :label="__('driver_check.invites.spam.remove')" x-on:click="remove(sender)" ::disabled="busy" class="hover:!text-error-600 dark:hover:!text-error-400" />
                                </li>
                            </template>
                        </ul>
                    </x-driver-check.surface>
                </div>

                @endif

                @if ($part === 'messages')
                {{-- What the bot took --}}
                <x-driver-check.surface class="overflow-hidden">
                    <div class="flex flex-col gap-3 border-b border-gray-100 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
                        <div class="min-w-0">
                            <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.spam.messages_title') }}</h2>
                            <p class="mt-0.5 text-[12px] text-gray-400" x-text="s.messages_hint.replace(':n', data.list_size)"></p>
                        </div>
                        <x-driver-check.chips items="statuses()" selected="status" action="setStatus" />
                    </div>

                    <p x-show="data.messages.length === 0" x-cloak class="px-4 py-12 text-center text-[13px] text-gray-500">{{ __('driver_check.invites.spam.messages_empty') }}</p>

                    <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                        <template x-for="row in data.messages" :key="row.id">
                            <li class="grid grid-cols-[52px_12px_minmax(0,1fr)] items-start gap-x-3 px-4 py-2.5 sm:grid-cols-[52px_12px_minmax(0,1fr)_minmax(0,220px)]">
                                <span class="mt-0.5 text-[12px] tabular-nums text-gray-400" x-text="time(row.at)"></span>
                                <span class="dc-dot mt-1.5" :class="statusDot(row)"></span>
                                <div class="min-w-0">
                                    <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[13px] text-gray-950 dark:text-white">
                                        <span class="font-medium" x-text="who(row)"></span>
                                        <span x-show="whoLine(row)" class="text-[11.5px] tabular-nums text-gray-400" x-text="whoLine(row)"></span>
                                    </p>
                                    <p x-show="kindLabel(row) || row.text" class="mt-0.5 line-clamp-3 break-words text-[12.5px] text-gray-600 dark:text-gray-300">
                                        <span x-show="kindLabel(row)" x-cloak class="mr-1 rounded bg-gray-100 px-1 py-px text-[11px] font-medium text-gray-500 dark:bg-white/[0.07] dark:text-gray-400" x-text="kindLabel(row)"></span>
                                        <span x-text="row.text || ''"></span>
                                    </p>
                                    <p class="mt-0.5 text-[11.5px]" :class="row.status === 'failed' ? 'text-error-600 dark:text-error-400' : 'text-gray-400'" x-text="statusText(row)"></p>
                                </div>
                                <p class="col-start-3 truncate text-[12px] text-gray-500 sm:col-start-auto sm:text-right" x-text="row.group"></p>
                            </li>
                        </template>
                    </ul>
                </x-driver-check.surface>
                @endif
            </div>
        </template>
    </x-driver-check.shell>
</div>

@endsection
