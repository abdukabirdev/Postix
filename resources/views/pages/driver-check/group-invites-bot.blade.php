@extends('layouts.driver-check')

@section('title', __('driver_check.tree.pages.bot_' . $part))

@section('content')

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
    <x-driver-check.shell>

        <x-driver-check.page-header
            :title="__('driver_check.tree.pages.bot_' . $part)"
            :description="$part === 'connection' ? __('driver_check.invites.bot.description') : __('driver_check.pages.bot_' . $part)"
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

                @if ($part !== 'connection')
                {{-- The pages below need the bot itself first --}}
                <p x-show="!bot.connected" x-cloak class="rounded-xl border border-warning-200 bg-warning-25 px-4 py-3 text-[13px] text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/[0.07] dark:text-warning-300">
                    {{ __('driver_check.pages.bot_needed') }}
                    <a href="{{ route('driver-check.group-invites.bot') }}" class="font-semibold underline-offset-2 hover:underline">{{ __('driver_check.tree.pages.bot_connection') }} →</a>
                </p>
                @endif

                @if ($part === 'connection')
                {{-- The bot: who it is, whether it is asking Telegram, the switch --}}
                <x-driver-check.surface class="overflow-hidden">
                    <div class="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300">
                                <x-driver-check.icon name="bot" class="h-5 w-5" />
                            </span>
                            <div class="min-w-0">
                                <template x-if="bot.connected">
                                    <a
                                        class="block truncate text-[15px] font-semibold text-gray-950 hover:underline dark:text-white"
                                        :href="'https://t.me/' + bot.username"
                                        target="_blank"
                                        rel="noopener"
                                        x-text="'@' + bot.username + (bot.name ? ' · ' + bot.name : '')"
                                    ></a>
                                </template>
                                <p x-show="!bot.connected" class="text-[15px] font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.bot.not_connected') }}</p>
                                <p class="mt-0.5 flex items-center gap-1.5 text-[12.5px] text-gray-500">
                                    <span class="dc-dot shrink-0" :class="state().tone"></span>
                                    <span x-text="state().text"></span>
                                </p>
                                <p x-show="bot.connected && bot.token" x-cloak class="mt-0.5 text-[11.5px] tabular-nums text-gray-400" x-text="b.token_line.replace(':token', bot.token)"></p>
                            </div>
                        </div>

                        <div x-show="bot.connected" x-cloak class="flex flex-wrap items-center gap-2 sm:justify-end">
                            <label class="flex cursor-pointer items-center gap-2 text-[12.5px] font-medium text-gray-700 dark:text-gray-200">
                                <span>{{ __('driver_check.invites.bot.enabled') }}</span>
                                <span class="relative inline-flex h-6 w-10 items-center">
                                    <input type="checkbox" class="peer sr-only" :checked="bot.enabled" :disabled="busy" x-on:change="toggle()">
                                    <span class="absolute inset-0 rounded-full bg-gray-300 transition peer-checked:bg-brand-500 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/50 dark:bg-gray-700"></span>
                                    <span class="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-4"></span>
                                </span>
                            </label>
                            <x-driver-check.button size="sm" x-on:click="replacing = !replacing" ::disabled="busy">
                                {{ __('driver_check.invites.bot.replace') }}
                            </x-driver-check.button>
                            <x-driver-check.button size="sm" variant="ghost" x-on:click="disconnect()" ::disabled="busy">
                                {{ __('driver_check.invites.bot.disconnect') }}
                            </x-driver-check.button>
                        </div>
                    </div>

                    <p
                        x-show="bot.connected && bot.error && !bot.alive"
                        x-cloak
                        class="border-t border-gray-100 bg-error-50/60 px-4 py-2.5 text-[12.5px] text-error-700 dark:border-gray-800 dark:bg-error-500/10 dark:text-error-400"
                        x-text="b.last_error.replace(':time', time(bot.error_at)).replace(':error', bot.error)"
                    ></p>

                    {{-- The token: the first time, or another bot --}}
                    <form
                        x-show="!bot.connected || replacing"
                        x-cloak
                        class="grid gap-4 border-t border-gray-100 p-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] dark:border-gray-800"
                        x-on:submit.prevent="connect()"
                    >
                        <ol class="flex list-decimal flex-col gap-1.5 pl-5 text-[12.5px] leading-snug text-gray-600 dark:text-gray-300">
                            @foreach (__('driver_check.invites.bot.steps') as $step)
                                <li>{{ $step }}</li>
                            @endforeach
                        </ol>

                        <div class="flex flex-col gap-2">
                            <x-driver-check.field :label="__('driver_check.invites.bot.token')" :hint="__('driver_check.invites.bot.token_hint')">
                                <x-driver-check.input type="password" autocomplete="off" spellcheck="false" x-model="token" placeholder="123456789:AA…" />
                            </x-driver-check.field>
                            <div class="flex gap-2">
                                <x-driver-check.button type="submit" variant="primary" ::disabled="busy || token.trim() === ''">
                                    {{ __('driver_check.invites.bot.connect') }}
                                </x-driver-check.button>
                                <x-driver-check.button x-show="replacing" x-cloak x-on:click="replacing = false; token = ''" ::disabled="busy">
                                    {{ __('driver_check.invites.bot.cancel') }}
                                </x-driver-check.button>
                            </div>
                        </div>
                    </form>
                </x-driver-check.surface>

                @endif

                @if ($part === 'reports')
                {{-- Reports: the bot tells about everything it does --}}
                <x-driver-check.surface x-show="bot.connected" x-cloak class="overflow-hidden">
                    <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                        <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.bot.reports.title') }}</h2>
                        <p class="mt-0.5 text-[12px] leading-snug text-gray-400">{{ __('driver_check.invites.bot.reports.hint') }}</p>
                    </div>

                    <form class="flex flex-col gap-3 px-4 pt-4 sm:flex-row sm:items-end" x-on:submit.prevent="saveReports()">
                        <div class="sm:w-64">
                            <x-driver-check.field :label="__('driver_check.invites.bot.reports.chat_id')">
                                <x-driver-check.input inputmode="numeric" autocomplete="off" spellcheck="false" x-model="reportChat" placeholder="8028326577" />
                            </x-driver-check.field>
                        </div>

                        <label class="flex h-9 cursor-pointer items-center gap-2 text-[12.5px] font-medium text-gray-700 dark:text-gray-200">
                            <span class="relative inline-flex h-6 w-10 items-center">
                                <input type="checkbox" class="peer sr-only" x-model="reportOn" :disabled="busy">
                                <span class="absolute inset-0 rounded-full bg-gray-300 transition peer-checked:bg-brand-500 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/50 dark:bg-gray-700"></span>
                                <span class="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-4"></span>
                            </span>
                            <span>{{ __('driver_check.invites.bot.reports.enabled') }}</span>
                        </label>

                        <div class="flex gap-2">
                            <x-driver-check.button type="submit" variant="primary" ::disabled="busy">
                                {{ __('driver_check.invites.bot.reports.save') }}
                            </x-driver-check.button>
                            <x-driver-check.button x-on:click="testReport()" ::disabled="busy || !reports.enabled">
                                {{ __('driver_check.invites.bot.reports.test') }}
                            </x-driver-check.button>
                        </div>
                    </form>
                    <p class="px-4 pb-4 pt-1.5 text-[11.5px] text-gray-400">{{ __('driver_check.invites.bot.reports.chat_hint') }}</p>

                    <p
                        class="flex items-start gap-1.5 border-t border-gray-100 px-4 py-2.5 text-[12.5px] dark:border-gray-800"
                        :class="reportState().bad ? 'bg-error-50/60 text-error-700 dark:bg-error-500/10 dark:text-error-400' : 'text-gray-500'"
                    >
                        <span class="dc-dot mt-1.5 shrink-0" :class="reportState().tone"></span>
                        <span x-text="reportState().text"></span>
                    </p>
                </x-driver-check.surface>

                @endif

                @if ($part === 'requests')
                {{-- Today in numbers --}}
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <x-driver-check.stat-card :label="__('driver_check.invites.bot.today_people')" icon="users">
                        <span x-text="number(data.today.people)"></span>
                    </x-driver-check.stat-card>
                    <x-driver-check.stat-card :label="__('driver_check.invites.bot.today_new')" icon="plus">
                        <span x-text="number(data.today.new)"></span>
                    </x-driver-check.stat-card>
                    <x-driver-check.stat-card :label="__('driver_check.invites.bot.today_ours')" icon="phone">
                        <span x-text="number(data.today.ours)"></span>
                    </x-driver-check.stat-card>
                    <x-driver-check.stat-card :label="__('driver_check.invites.bot.today_failed')" icon="alert">
                        <span x-text="number(data.today.failed)"></span>
                    </x-driver-check.stat-card>
                </div>
                <p class="-mt-3 text-[12px] text-gray-500" x-text="b.total_line.replace(':people', number(data.total.people)).replace(':new', number(data.total.new))"></p>

                @endif

                @if ($part === 'connection')
                {{-- Our groups: where the bot may let people in --}}
                <x-driver-check.surface class="overflow-hidden">
                    <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                        <div class="min-w-0">
                            <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.bot.groups_title') }}</h2>
                            <p class="mt-0.5 text-[12px] text-gray-400">{{ __('driver_check.invites.bot.groups_hint') }}</p>
                        </div>
                        <span x-show="groupsLoaded && groups.length > 0" x-cloak class="shrink-0 text-[12px] tabular-nums text-gray-500" x-text="b.groups_ready.replace(':n', groupsReady()).replace(':all', groups.length)"></span>
                    </div>

                    <div x-show="groupsLoading && !groupsLoaded" class="p-4">
                        <div class="dc-skeleton h-16 rounded-xl bg-gray-100 dark:bg-white/[0.06]"></div>
                    </div>

                    <p x-show="groupsLoaded && groups.length === 0" x-cloak class="px-4 py-8 text-center text-[13px] text-gray-500">{{ __('driver_check.invites.bot.no_groups') }}</p>

                    <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                        <template x-for="group in groups" :key="group.id">
                            <li class="flex flex-col gap-1 px-4 py-2.5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                                <div class="min-w-0">
                                    <p class="truncate text-[13.5px] font-medium text-gray-950 dark:text-white">
                                        <span x-text="group.title"></span>
                                        <span x-show="group.is_active" x-cloak class="ml-1.5 rounded-md bg-gray-900 px-1.5 py-0.5 text-[10.5px] font-semibold text-white dark:bg-white dark:text-gray-900">{{ __('driver_check.invites.overview.is_current') }}</span>
                                    </p>
                                    <p class="truncate text-[11.5px] text-gray-400" x-text="group.link"></p>
                                </div>
                                <span class="inline-flex w-fit shrink-0 items-center rounded-md px-2 py-0.5 text-[11.5px] font-medium" :class="groupTone(group)" x-text="groupLabel(group)" :title="group.error || ''"></span>
                            </li>
                        </template>
                    </ul>

                    <div x-show="data.chats.length > 0" x-cloak class="border-t border-gray-100 px-4 py-3 dark:border-gray-800">
                        <p class="text-[11.5px] font-semibold uppercase tracking-wide text-gray-400">{{ __('driver_check.invites.bot.chats_title') }}</p>
                        <p class="mt-1 text-[12.5px] text-gray-600 dark:text-gray-300">
                            <template x-for="(chat, i) in data.chats" :key="chat.id">
                                <span>
                                    <span x-text="(i > 0 ? ', ' : '') + (chat.title || chat.id)"></span>
                                    <span x-show="!chat.admin || !chat.can_invite" class="text-warning-600 dark:text-warning-400" x-text="' (' + (chat.admin ? b.group_states.no_right : b.group_states.not_admin) + ')'"></span>
                                </span>
                            </template>
                        </p>
                    </div>
                </x-driver-check.surface>

                @endif

                @if ($part === 'requests')
                {{-- The requests --}}
                <x-driver-check.surface class="overflow-hidden">
                    <div class="flex flex-col gap-3 border-b border-gray-100 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
                        <div class="min-w-0">
                            <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.bot.requests_title') }}</h2>
                            <p class="mt-0.5 text-[12px] text-gray-400" x-text="b.requests_hint.replace(':n', data.list_size)"></p>
                        </div>
                        <x-driver-check.chips items="kinds()" selected="kind" action="setKind" />
                    </div>

                    <p x-show="data.requests.length === 0" x-cloak class="px-4 py-12 text-center text-[13px] text-gray-500">{{ __('driver_check.invites.bot.no_requests') }}</p>

                    <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                        <template x-for="row in data.requests" :key="row.id">
                            <li class="grid grid-cols-[52px_12px_minmax(0,1fr)] items-start gap-x-3 px-4 py-2.5 sm:grid-cols-[52px_12px_minmax(0,1fr)_minmax(0,260px)]">
                                <span class="mt-0.5 text-[12px] tabular-nums text-gray-400" x-text="time(row.at)"></span>
                                <span class="dc-dot mt-1.5" :class="row.status === 'approved' ? 'bg-success-500' : 'bg-error-500'"></span>
                                <div class="min-w-0">
                                    <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[13px] text-gray-950 dark:text-white">
                                        <template x-if="!row.is_ours && row.username">
                                            <a class="font-medium hover:underline" :href="'https://t.me/' + row.username" target="_blank" rel="noopener" x-text="who(row)"></a>
                                        </template>
                                        <span x-show="row.is_ours || !row.username" class="font-medium" x-text="who(row)"></span>
                                        <span x-show="row.is_ours" x-cloak class="rounded-md bg-gray-100 px-1.5 py-0.5 text-[10.5px] font-semibold text-gray-600 dark:bg-white/[0.07] dark:text-gray-300">{{ __('driver_check.invites.bot.badge_ours') }}</span>
                                        <span x-show="row.is_new" x-cloak class="rounded-md bg-brand-50 px-1.5 py-0.5 text-[10.5px] font-semibold text-brand-700 dark:bg-brand-500/10 dark:text-brand-400">{{ __('driver_check.invites.bot.badge_new') }}</span>
                                    </p>
                                    <p x-show="!row.is_ours" class="truncate text-[11.5px] tabular-nums text-gray-400" x-text="whoLine(row)"></p>
                                    <p x-show="row.status !== 'approved'" x-cloak class="text-[12px] text-error-600 dark:text-error-400" x-text="requestError(row)"></p>
                                </div>
                                <p class="col-start-3 truncate text-[12px] text-gray-500 sm:col-start-auto sm:text-right" :class="!row.ours_group && 'text-gray-400'" x-text="row.group + (row.ours_group ? '' : ' · ' + b.not_our_group)"></p>
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
