@extends('layouts.driver-check')

@section('title', __('driver_check.tree.pages.sender_' . $part))

@section('content')

@php
    $toggleTrack = 'absolute inset-0 rounded-full bg-gray-300 transition peer-checked:bg-brand-500 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/50 dark:bg-gray-700';
    $toggleKnob = 'absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-4';
@endphp

<div
    x-data="dcSenderBot({
        part: @js($part),
        endpoints: {
            show: @js(route('api.telegram.sender-bot')),
            update: @js(route('api.telegram.sender-bot.update')),
            connect: @js(route('api.telegram.sender-bot.connect')),
            disconnect: @js(route('api.telegram.sender-bot.disconnect')),
            notify: @js(route('api.telegram.sender-bot.notify')),
            notifyTest: @js(route('api.telegram.sender-bot.notify.test')),
            limits: @js(route('api.telegram.sender-bot.limits')),
            users: @js(route('api.telegram.sender-bot.users')),
            decide: @js(route('api.telegram.sender-bot.users.decide', '__ID__')),
            renotify: @js(route('api.telegram.sender-bot.users.renotify', '__ID__')),
            groups: @js(route('api.telegram.sender-bot.groups')),
            groupsStore: @js(route('api.telegram.sender-bot.groups.store')),
            groupsCheck: @js(route('api.telegram.sender-bot.groups.check')),
            groupsUpdate: @js(route('api.telegram.sender-bot.groups.update', '__ID__')),
            groupsDestroy: @js(route('api.telegram.sender-bot.groups.destroy', '__ID__')),
            posts: @js(route('api.telegram.sender-bot.posts')),
            postStop: @js(route('api.telegram.sender-bot.posts.stop', '__ID__')),
        },
        translations: @js(__('driver_check.sender_bot')),
    })"
>
    <x-driver-check.shell>

        <x-driver-check.page-header
            :title="__('driver_check.tree.pages.sender_' . $part)"
            :description="__('driver_check.sender_bot.about.' . $part)"
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

        <x-driver-check.error-alert :title="__('driver_check.sender_bot.load_failed')" retry="load()" />

        <div x-show="!loaded && loading" class="dc-skeleton h-40 rounded-2xl bg-gray-100 dark:bg-white/[0.06]"></div>

        <template x-if="loaded">
            <div class="flex flex-col gap-5">

                @if ($part !== 'settings')
                {{-- Every page but the settings needs the bot first --}}
                <p x-show="!bot.connected" x-cloak class="rounded-xl border border-warning-200 bg-warning-25 px-4 py-3 text-[13px] text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/[0.07] dark:text-warning-300">
                    {{ __('driver_check.sender_bot.needs_bot') }}
                    <a href="{{ route('driver-check.sender-bot.settings') }}" class="font-semibold underline-offset-2 hover:underline">{{ __('driver_check.tree.pages.sender_settings') }} →</a>
                </p>

                <p x-show="bot.connected && state().key !== 'on'" x-cloak class="flex items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 px-4 py-2.5 text-[12.5px] text-gray-600 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300">
                    <span class="dc-dot shrink-0" :class="state().tone"></span>
                    <span x-text="state().text"></span>
                </p>
                @endif

                @if ($part === 'requests' || $part === 'posts')
                {{-- The readings: each is the filter of the list below --}}
                <div class="dc-stats">
                    <template x-for="tile in tiles()" :key="tile.value">
                        <button type="button" class="dc-stat" :aria-pressed="filter === tile.value" x-on:click="setFilter(tile.value)">
                            <p class="dc-stat__label" x-text="tile.label"></p>
                            <p class="dc-stat__value" :class="tile.warn && 'text-warning-600 dark:text-warning-400'" x-text="number(tile.total ?? 0)"></p>
                        </button>
                    </template>
                </div>
                @endif

                @if ($part === 'requests')
                {{-- ===================== Requests ===================== --}}
                <x-driver-check.block :title="__('driver_check.sender_bot.users.title')" count="number(rows.length)">
                    <x-slot:actions>
                        <a x-show="botLink" x-cloak :href="botLink" target="_blank" rel="noopener" class="text-[12.5px] font-medium text-brand-600 hover:underline dark:text-brand-400" x-text="'@' + bot.username"></a>
                    </x-slot:actions>

                    <div x-show="!rowsLoaded" class="dc-block__wait">
                        <div class="dc-skeleton h-3 w-3/4 rounded bg-gray-100 dark:bg-white/[0.06]"></div>
                        <div class="dc-skeleton h-3 w-1/2 rounded bg-gray-100 dark:bg-white/[0.06]"></div>
                    </div>

                    <p x-show="rowsLoaded && rows.length === 0" x-cloak class="dc-none" x-text="filter === 'pending' ? t.users.empty_pending : t.users.empty"></p>

                    <div x-show="rowsLoaded && rows.length > 0" x-cloak>
                        <x-driver-check.table min="900px">
                            <thead>
                                <tr>
                                    <th>{{ __('driver_check.sender_bot.users.cols.person') }}</th>
                                    <th>{{ __('driver_check.sender_bot.users.cols.status') }}</th>
                                    <th>{{ __('driver_check.sender_bot.users.cols.language') }}</th>
                                    <th>{{ __('driver_check.sender_bot.users.cols.requested') }}</th>
                                    <th>{{ __('driver_check.sender_bot.users.cols.notice') }}</th>
                                    <th class="dc-num">{{ __('driver_check.sender_bot.users.cols.posts') }}</th>
                                    <th class="dc-acts"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="user in rows" :key="user.id">
                                    <tr :data-off="user.status === 'rejected' || user.status === 'blocked' || null">
                                        <td>
                                            <span class="dc-main block max-w-[240px] truncate" x-text="userName(user)"></span>
                                            <span class="dc-sub2 truncate" x-text="userSub(user)"></span>
                                        </td>
                                        <td><span class="dc-tag" :data-tone="userTone(user)" x-text="t.users.status[user.status]"></span></td>
                                        <td class="text-gray-600 dark:text-gray-300" x-text="language(user.language)"></td>
                                        <td class="tabular-nums text-gray-600 dark:text-gray-300">
                                            <span x-text="user.requested_at ? time(user.requested_at) : '—'"></span>
                                            <span x-show="user.decided_at" class="dc-sub2" x-text="t.users.decided.replace(':time', time(user.decided_at))"></span>
                                        </td>
                                        <td>
                                            <template x-if="noticeLine(user)">
                                                <span class="flex items-center gap-1.5">
                                                    <span class="dc-tag" :data-tone="noticeLine(user).tone" :title="noticeLine(user).title || ''" x-text="noticeLine(user).text"></span>
                                                    <button x-show="user.notify_error && user.status === 'pending'" type="button" class="text-[12px] font-medium text-brand-600 hover:underline dark:text-brand-400" x-on:click="renotify(user)" :disabled="busy">{{ __('driver_check.sender_bot.users.renotify') }}</button>
                                                </span>
                                            </template>
                                            <span x-show="!noticeLine(user)" class="text-gray-400">—</span>
                                        </td>
                                        <td class="dc-num">
                                            <span x-text="number(user.posts)"></span>
                                            <span x-show="user.active_posts > 0" class="dc-sub2" x-text="t.users.active_posts.replace(':count', user.active_posts)"></span>
                                        </td>
                                        <td class="dc-acts">
                                            <div class="flex justify-end gap-1.5">
                                                <template x-if="user.status === 'pending' || user.status === 'rejected' || user.status === 'new'">
                                                    <x-driver-check.button size="sm" variant="primary" icon="check" x-on:click="decide(user, 'approve')" ::disabled="busy">{{ __('driver_check.sender_bot.users.approve') }}</x-driver-check.button>
                                                </template>
                                                <template x-if="user.status === 'pending' || user.status === 'new'">
                                                    <x-driver-check.button size="sm" icon="x-circle" x-on:click="decide(user, 'reject')" ::disabled="busy">{{ __('driver_check.sender_bot.users.reject') }}</x-driver-check.button>
                                                </template>
                                                <template x-if="user.status === 'approved'">
                                                    <x-driver-check.button size="sm" variant="ghost" icon="lock" x-on:click="decide(user, 'block')" ::disabled="busy">{{ __('driver_check.sender_bot.users.block') }}</x-driver-check.button>
                                                </template>
                                                <template x-if="user.status === 'blocked'">
                                                    <x-driver-check.button size="sm" icon="check" x-on:click="decide(user, 'unblock')" ::disabled="busy">{{ __('driver_check.sender_bot.users.unblock') }}</x-driver-check.button>
                                                </template>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </x-driver-check.table>
                    </div>
                </x-driver-check.block>
                @endif

                @if ($part === 'posts')
                {{-- ===================== Posts ===================== --}}
                <x-driver-check.block :title="__('driver_check.sender_bot.posts.title')" count="number(rows.length)">
                    <div x-show="!rowsLoaded" class="dc-block__wait">
                        <div class="dc-skeleton h-3 w-3/4 rounded bg-gray-100 dark:bg-white/[0.06]"></div>
                        <div class="dc-skeleton h-3 w-1/2 rounded bg-gray-100 dark:bg-white/[0.06]"></div>
                    </div>

                    <p x-show="rowsLoaded && rows.length === 0" x-cloak class="dc-none">{{ __('driver_check.sender_bot.posts.empty') }}</p>

                    <div x-show="rowsLoaded && rows.length > 0" x-cloak>
                        <x-driver-check.table min="980px">
                            <thead>
                                <tr>
                                    <th class="dc-num">#</th>
                                    <th>{{ __('driver_check.sender_bot.posts.cols.text') }}</th>
                                    <th>{{ __('driver_check.sender_bot.posts.cols.who') }}</th>
                                    <th>{{ __('driver_check.sender_bot.posts.cols.progress') }}</th>
                                    <th>{{ __('driver_check.sender_bot.posts.cols.interval') }}</th>
                                    <th>{{ __('driver_check.sender_bot.posts.cols.next') }}</th>
                                    <th class="dc-num">{{ __('driver_check.sender_bot.posts.cols.copies') }}</th>
                                    <th>{{ __('driver_check.sender_bot.posts.cols.status') }}</th>
                                    <th class="dc-acts"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="post in rows" :key="post.id">
                                    <tr :data-off="post.status !== 'active' || null">
                                        <td class="dc-num text-gray-500" x-text="post.id"></td>
                                        <td>
                                            <span class="dc-main block max-w-[300px] truncate" :title="post.text || ''" x-text="preview(post)"></span>
                                            <span class="dc-sub2" x-text="time(post.created_at)"></span>
                                        </td>
                                        <td>
                                            <span class="block max-w-[170px] truncate" x-text="post.user ? post.user.name : '—'"></span>
                                            <span x-show="post.user && post.user.username" class="dc-sub2" x-text="post.user ? '@' + post.user.username : ''"></span>
                                        </td>
                                        <td class="min-w-[140px]">
                                            <div class="flex items-center gap-2">
                                                <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-white/[0.07]">
                                                    <div class="h-full rounded-full" :class="post.status === 'failed' ? 'bg-error-500' : 'bg-brand-500'" :style="`width: ${progress(post)}%`"></div>
                                                </div>
                                                <span class="text-[12px] tabular-nums text-gray-600 dark:text-gray-300" x-text="post.sent_times + ' / ' + post.times"></span>
                                            </div>
                                        </td>
                                        <td class="whitespace-nowrap tabular-nums text-gray-600 dark:text-gray-300" x-text="post.times > 1 ? interval(post.interval_minutes) : '—'"></td>
                                        <td class="tabular-nums text-gray-600 dark:text-gray-300" x-text="post.status === 'active' && post.next_at ? time(post.next_at) : '—'"></td>
                                        <td class="dc-num">
                                            <span x-text="number(post.copies)"></span>
                                            <span x-show="post.failed > 0" class="dc-sub2 text-error-600 dark:text-error-400" :title="post.last_error || ''" x-text="t.posts.failed_copies.replace(':count', post.failed)"></span>
                                        </td>
                                        <td><span class="dc-tag" :data-tone="postTone(post)" :title="post.last_error || ''" x-text="postStatus(post)"></span></td>
                                        <td class="dc-acts">
                                            <x-driver-check.button x-show="post.status === 'active'" size="sm" variant="danger" icon="stop" x-on:click="stopPost(post)" ::disabled="busy">{{ __('driver_check.sender_bot.posts.stop') }}</x-driver-check.button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </x-driver-check.table>
                    </div>
                </x-driver-check.block>
                @endif

                @if ($part === 'groups')
                {{-- ===================== Groups ===================== --}}
                <x-driver-check.surface class="overflow-hidden">
                    <form class="grid gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]" x-on:submit.prevent="addGroup()">
                        <div class="flex flex-col gap-2 text-[12.5px] leading-snug text-gray-600 dark:text-gray-300">
                            <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.sender_bot.groups.add_title') }}</p>
                            <ol class="flex list-decimal flex-col gap-1 pl-5">
                                @foreach (__('driver_check.sender_bot.groups.add_steps') as $step)
                                    <li>{{ $step }}</li>
                                @endforeach
                            </ol>
                        </div>
                        <div class="flex flex-col gap-2">
                            <x-driver-check.field :label="__('driver_check.sender_bot.groups.input')" :hint="__('driver_check.sender_bot.groups.input_hint')">
                                <x-driver-check.input autocomplete="off" spellcheck="false" x-model="groupInput" placeholder="@group_name / https://t.me/group / -1001234567890" />
                            </x-driver-check.field>
                            <div>
                                <x-driver-check.button type="submit" variant="primary" icon="plus" ::disabled="busy || !bot.connected || groupInput.trim() === ''">
                                    {{ __('driver_check.sender_bot.groups.add') }}
                                </x-driver-check.button>
                            </div>
                        </div>
                    </form>
                </x-driver-check.surface>

                <x-driver-check.block :title="__('driver_check.sender_bot.groups.title')" count="number(rows.length)">
                    <x-slot:actions>
                        <span class="hidden text-[12px] text-gray-500 sm:inline" x-text="t.groups.on_line.replace(':on', counts.groups_on ?? 0).replace(':all', counts.groups ?? 0)"></span>
                        <x-driver-check.button size="sm" icon="sync" x-on:click="checkGroups()" ::disabled="busy || !bot.connected">{{ __('driver_check.sender_bot.groups.check') }}</x-driver-check.button>
                    </x-slot:actions>

                    <div x-show="!rowsLoaded" class="dc-block__wait">
                        <div class="dc-skeleton h-3 w-3/4 rounded bg-gray-100 dark:bg-white/[0.06]"></div>
                    </div>

                    <p x-show="rowsLoaded && rows.length === 0" x-cloak class="dc-none">{{ __('driver_check.sender_bot.groups.empty') }}</p>

                    <div x-show="rowsLoaded && rows.length > 0" x-cloak>
                        <x-driver-check.table min="860px">
                            <thead>
                                <tr>
                                    <th>{{ __('driver_check.sender_bot.groups.cols.group') }}</th>
                                    <th>{{ __('driver_check.sender_bot.groups.cols.bot') }}</th>
                                    <th>{{ __('driver_check.sender_bot.groups.cols.sending') }}</th>
                                    <th>{{ __('driver_check.sender_bot.groups.cols.last') }}</th>
                                    <th class="dc-num">{{ __('driver_check.sender_bot.groups.cols.copies') }}</th>
                                    <th class="dc-acts"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="group in rows" :key="group.id">
                                    <tr :data-off="!group.is_active || null">
                                        <td>
                                            <template x-if="groupLink(group)">
                                                <a :href="groupLink(group)" target="_blank" rel="noopener" class="dc-main block max-w-[280px] truncate hover:underline" x-text="group.title"></a>
                                            </template>
                                            <span x-show="!groupLink(group)" class="dc-main block max-w-[280px] truncate" x-text="group.title"></span>
                                            <span class="dc-sub2 tabular-nums" x-text="group.chat_id"></span>
                                        </td>
                                        <td>
                                            <span class="dc-tag" :data-tone="groupTone(group)" x-text="groupState(group)"></span>
                                            <span x-show="group.last_error" class="dc-sub2 max-w-[220px] truncate text-error-600 dark:text-error-400" :title="group.last_error" x-text="group.last_error"></span>
                                        </td>
                                        <td>
                                            <label class="flex w-fit cursor-pointer items-center gap-2 text-[12.5px] text-gray-700 dark:text-gray-200">
                                                <span class="relative inline-flex h-6 w-10 items-center">
                                                    <input type="checkbox" class="peer sr-only" :checked="group.is_active" :disabled="busy" x-on:change="toggleGroup(group)">
                                                    <span class="{{ $toggleTrack }}"></span>
                                                    <span class="{{ $toggleKnob }}"></span>
                                                </span>
                                                <span x-text="group.is_active ? t.groups.on : t.groups.off"></span>
                                            </label>
                                        </td>
                                        <td class="tabular-nums text-gray-600 dark:text-gray-300" x-text="group.last_sent_at ? time(group.last_sent_at) : '—'"></td>
                                        <td class="dc-num">
                                            <span x-text="number(group.copies)"></span>
                                            <span x-show="group.failed > 0" class="dc-sub2 text-error-600 dark:text-error-400" x-text="t.posts.failed_copies.replace(':count', group.failed)"></span>
                                        </td>
                                        <td class="dc-acts">
                                            <x-driver-check.button size="icon" variant="ghost" x-on:click="removeGroup(group)" ::disabled="busy" :title="__('driver_check.sender_bot.groups.remove')" :aria-label="__('driver_check.sender_bot.groups.remove')">
                                                <x-driver-check.icon name="trash" class="h-4 w-4" />
                                            </x-driver-check.button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </x-driver-check.table>
                    </div>
                </x-driver-check.block>
                @endif

                @if ($part === 'settings')
                {{-- ===================== Settings ===================== --}}

                {{-- The bot: who it is, whether it answers, the switch, the link to share --}}
                <x-driver-check.surface class="overflow-hidden">
                    <div class="flex flex-col gap-5 p-5 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex min-w-0 items-center gap-4">
                            <span class="relative flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                                <x-driver-check.icon name="message" class="h-7 w-7" />
                                <span class="absolute -bottom-0.5 -right-0.5 h-3.5 w-3.5 rounded-full ring-2 ring-white dark:ring-gray-900" :class="state().tone"></span>
                            </span>
                            <div class="min-w-0">
                                <template x-if="bot.connected">
                                    <a class="block truncate text-lg font-semibold text-gray-950 hover:underline dark:text-white" :href="botLink" target="_blank" rel="noopener" x-text="'@' + bot.username"></a>
                                </template>
                                <p x-show="!bot.connected" class="text-lg font-semibold text-gray-950 dark:text-white">{{ __('driver_check.sender_bot.bot.not_connected') }}</p>
                                <p x-show="bot.connected && bot.name" x-cloak class="truncate text-[13px] text-gray-500" x-text="bot.name"></p>
                                <p class="mt-1 flex items-center gap-1.5 text-[12.5px] text-gray-500">
                                    <span class="dc-dot shrink-0" :class="state().tone"></span>
                                    <span x-text="state().text"></span>
                                </p>
                            </div>
                        </div>

                        <div x-show="bot.connected" x-cloak class="flex flex-wrap items-center gap-2 sm:justify-end">
                            <label class="mr-1 flex cursor-pointer items-center gap-2 text-[13px] font-medium text-gray-700 dark:text-gray-200">
                                <span x-text="bot.enabled ? t.bot.is_on : t.bot.is_off"></span>
                                <span class="relative inline-flex h-6 w-10 items-center">
                                    <input type="checkbox" class="peer sr-only" :checked="bot.enabled" :disabled="busy" x-on:change="toggle()">
                                    <span class="{{ $toggleTrack }}"></span>
                                    <span class="{{ $toggleKnob }}"></span>
                                </span>
                            </label>
                            <x-driver-check.button size="sm" x-on:click="replacing = !replacing" ::disabled="busy">{{ __('driver_check.sender_bot.bot.replace') }}</x-driver-check.button>
                            <x-driver-check.button size="sm" variant="ghost" x-on:click="disconnect()" ::disabled="busy">{{ __('driver_check.sender_bot.bot.disconnect') }}</x-driver-check.button>
                        </div>
                    </div>

                    {{-- The link people open the bot by --}}
                    <div x-show="bot.connected" x-cloak class="flex flex-col gap-2 border-t border-gray-100 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
                        <div class="min-w-0">
                            <p class="text-[11.5px] font-medium uppercase tracking-wide text-gray-400">{{ __('driver_check.sender_bot.bot.share') }}</p>
                            <p class="truncate text-[14px] font-medium tabular-nums text-gray-900 dark:text-gray-100" x-text="botLink"></p>
                        </div>
                        <div class="flex gap-2">
                            <x-driver-check.button size="sm" icon="copy" x-on:click="copyLink()">{{ __('driver_check.sender_bot.bot.copy') }}</x-driver-check.button>
                            <x-driver-check.button size="sm" icon="external" x-on:click="window.open(botLink, '_blank', 'noopener')">{{ __('driver_check.sender_bot.bot.open') }}</x-driver-check.button>
                        </div>
                    </div>

                    <p x-show="bot.connected && bot.token" x-cloak class="border-t border-gray-100 px-5 py-2 text-[11.5px] tabular-nums text-gray-400 dark:border-gray-800" x-text="t.bot.token_line.replace(':token', bot.token)"></p>

                    <p
                        x-show="bot.connected && bot.error && !bot.alive"
                        x-cloak
                        class="border-t border-gray-100 bg-error-50/60 px-5 py-2.5 text-[12.5px] text-error-700 dark:border-gray-800 dark:bg-error-500/10 dark:text-error-400"
                        x-text="t.bot.last_error.replace(':time', time(bot.error_at)).replace(':error', bot.error)"
                    ></p>

                    {{-- The token: the first time, or another bot --}}
                    <form
                        x-show="!bot.connected || replacing"
                        x-cloak
                        class="grid gap-4 border-t border-gray-100 p-5 lg:grid-cols-2 dark:border-gray-800"
                        x-on:submit.prevent="connect()"
                    >
                        <ol class="flex list-decimal flex-col gap-1.5 pl-5 text-[12.5px] leading-snug text-gray-600 dark:text-gray-300">
                            @foreach (__('driver_check.sender_bot.bot.steps') as $step)
                                <li>{{ $step }}</li>
                            @endforeach
                        </ol>
                        <div class="flex flex-col gap-2">
                            <x-driver-check.field :label="__('driver_check.sender_bot.bot.token')" :hint="__('driver_check.sender_bot.bot.token_hint')">
                                <x-driver-check.input type="password" autocomplete="off" spellcheck="false" x-model="token" placeholder="123456789:AA…" />
                            </x-driver-check.field>
                            <div class="flex gap-2">
                                <x-driver-check.button type="submit" variant="primary" ::disabled="busy || token.trim() === ''">{{ __('driver_check.sender_bot.bot.connect') }}</x-driver-check.button>
                                <x-driver-check.button x-show="replacing" x-cloak x-on:click="replacing = false; token = ''" ::disabled="busy">{{ __('driver_check.sender_bot.bot.cancel') }}</x-driver-check.button>
                            </div>
                        </div>
                    </form>
                </x-driver-check.surface>

                {{-- The bot in numbers, each a link to its page --}}
                <div x-show="bot.connected" x-cloak class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <a href="{{ route('driver-check.sender-bot') }}" class="rounded-2xl border border-gray-200 bg-white px-4 py-3.5 transition hover:border-gray-300 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-gray-700">
                        <p class="text-[12px] text-gray-500">{{ __('driver_check.sender_bot.numbers.pending') }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums" :class="counts.pending > 0 ? 'text-warning-600 dark:text-warning-400' : 'text-gray-950 dark:text-white'" x-text="number(counts.pending)"></p>
                    </a>
                    <a href="{{ route('driver-check.sender-bot') }}" class="rounded-2xl border border-gray-200 bg-white px-4 py-3.5 transition hover:border-gray-300 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-gray-700">
                        <p class="text-[12px] text-gray-500">{{ __('driver_check.sender_bot.numbers.approved') }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-950 dark:text-white" x-text="number(counts.approved)"></p>
                    </a>
                    <a href="{{ route('driver-check.sender-bot.posts') }}" class="rounded-2xl border border-gray-200 bg-white px-4 py-3.5 transition hover:border-gray-300 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-gray-700">
                        <p class="text-[12px] text-gray-500">{{ __('driver_check.sender_bot.numbers.posts_active') }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-950 dark:text-white" x-text="number(counts.posts_active)"></p>
                    </a>
                    <a href="{{ route('driver-check.sender-bot.groups') }}" class="rounded-2xl border border-gray-200 bg-white px-4 py-3.5 transition hover:border-gray-300 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-gray-700">
                        <p class="text-[12px] text-gray-500">{{ __('driver_check.sender_bot.numbers.groups') }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums" :class="counts.groups_on === 0 ? 'text-error-600 dark:text-error-400' : 'text-gray-950 dark:text-white'" x-text="number(counts.groups_on) + ' / ' + number(counts.groups)"></p>
                    </a>
                </div>

                <div class="grid gap-5 xl:grid-cols-2">
                    {{-- Who hears of a new request, and how --}}
                    <x-driver-check.surface class="flex flex-col overflow-hidden">
                        <div class="flex items-start gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300">
                                <x-driver-check.icon name="mail" class="h-[18px] w-[18px]" />
                            </span>
                            <div>
                                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.sender_bot.notify.title') }}</h2>
                                <p class="mt-0.5 text-[12px] leading-snug text-gray-500">{{ __('driver_check.sender_bot.notify.hint') }}</p>
                            </div>
                        </div>

                        <form class="flex flex-1 flex-col gap-4 p-5" x-on:submit.prevent="saveNotify()">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-driver-check.field :label="__('driver_check.sender_bot.notify.chat_id')" :hint="__('driver_check.sender_bot.notify.chat_hint')">
                                    <x-driver-check.input inputmode="numeric" autocomplete="off" spellcheck="false" x-model="form.chat_id" placeholder="8028326577" />
                                </x-driver-check.field>

                                <x-driver-check.field :label="__('driver_check.sender_bot.notify.listener')" :hint="__('driver_check.sender_bot.notify.listener_hint')">
                                    <select x-model="form.listener_key" class="h-9 w-full rounded-lg border border-gray-300 bg-white px-3 text-[13px] text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                        <option value="">{{ __('driver_check.sender_bot.notify.listener_auto') }}</option>
                                        <template x-for="l in data.listeners" :key="l.key">
                                            <option :value="l.key" :selected="form.listener_key === l.key" x-text="l.name + (l.enabled ? '' : ' · ' + t.notify.listener_off)"></option>
                                        </template>
                                    </select>
                                </x-driver-check.field>
                            </div>

                            <label class="flex cursor-pointer items-start gap-3 rounded-xl bg-gray-50 px-3.5 py-3 dark:bg-white/[0.03]">
                                <span class="relative mt-0.5 inline-flex h-6 w-10 shrink-0 items-center">
                                    <input type="checkbox" class="peer sr-only" x-model="form.via_bot" :disabled="busy">
                                    <span class="{{ $toggleTrack }}"></span>
                                    <span class="{{ $toggleKnob }}"></span>
                                </span>
                                <span class="text-[12.5px] leading-snug">
                                    <span class="block font-medium text-gray-800 dark:text-gray-100">{{ __('driver_check.sender_bot.notify.via_bot') }}</span>
                                    <span class="block text-gray-500">{{ __('driver_check.sender_bot.notify.via_bot_hint') }}</span>
                                </span>
                            </label>

                            <dl class="flex flex-col gap-2 text-[12.5px]">
                                <div class="flex items-start gap-2">
                                    <dt class="w-32 shrink-0 text-gray-500">{{ __('driver_check.sender_bot.notify.who_now') }}</dt>
                                    <dd class="min-w-0 font-medium text-gray-800 dark:text-gray-100" x-text="data.notify.listener ? data.notify.listener.name : t.notify.nobody"></dd>
                                </div>
                                <div class="flex items-start gap-2">
                                    <dt class="w-32 shrink-0 text-gray-500">{{ __('driver_check.sender_bot.notify.last') }}</dt>
                                    <dd class="flex min-w-0 items-start gap-1.5">
                                        <span class="dc-tag" :data-tone="lastNoticeLine().tone" x-text="lastNoticeLine().text"></span>
                                    </dd>
                                </div>
                                <div x-show="testLine()" x-cloak class="flex items-start gap-2">
                                    <dt class="w-32 shrink-0 text-gray-500">{{ __('driver_check.sender_bot.notify.test') }}</dt>
                                    <dd class="min-w-0"><span class="dc-tag dc-break" :data-tone="testLine()?.tone" x-text="testLine()?.text"></span></dd>
                                </div>
                            </dl>

                            <div class="mt-auto flex flex-wrap gap-2 pt-1">
                                <x-driver-check.button type="submit" variant="primary" ::disabled="busy">{{ __('driver_check.sender_bot.save') }}</x-driver-check.button>
                                <x-driver-check.button icon="send" x-on:click="testNotify()" ::disabled="busy || !data.notify.chat_id">{{ __('driver_check.sender_bot.notify.test_button') }}</x-driver-check.button>
                            </div>
                        </form>
                    </x-driver-check.surface>

                    {{-- The limits of one post --}}
                    <x-driver-check.surface class="flex flex-col overflow-hidden">
                        <div class="flex items-start gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300">
                                <x-driver-check.icon name="sliders" class="h-[18px] w-[18px]" />
                            </span>
                            <div>
                                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.sender_bot.limits.title') }}</h2>
                                <p class="mt-0.5 text-[12px] leading-snug text-gray-500">{{ __('driver_check.sender_bot.limits.hint') }}</p>
                            </div>
                        </div>

                        <form class="flex flex-1 flex-col gap-4 p-5" x-on:submit.prevent="saveLimits()">
                            <div class="grid gap-4 sm:grid-cols-3">
                                <x-driver-check.field :label="__('driver_check.sender_bot.limits.max_times')">
                                    <x-driver-check.input type="number" min="1" ::max="data.max.times" x-model.number="form.max_times" />
                                </x-driver-check.field>
                                <x-driver-check.field :label="__('driver_check.sender_bot.limits.min_interval')">
                                    <x-driver-check.input type="number" min="1" ::max="data.max.interval" x-model.number="form.min_interval" />
                                </x-driver-check.field>
                                <x-driver-check.field :label="__('driver_check.sender_bot.limits.max_interval')">
                                    <x-driver-check.input type="number" min="1" ::max="data.max.interval" x-model.number="form.max_interval" />
                                </x-driver-check.field>
                            </div>

                            <p class="rounded-xl bg-gray-50 px-3.5 py-3 text-[12.5px] leading-relaxed text-gray-600 dark:bg-white/[0.03] dark:text-gray-300" x-text="t.limits.preview
                                .replace(':times', form.max_times || 1)
                                .replace(':min', interval(Number(form.min_interval) || 1))
                                .replace(':max', interval(Number(form.max_interval) || 1))"></p>

                            <div class="mt-auto pt-1">
                                <x-driver-check.button type="submit" variant="primary" ::disabled="busy">{{ __('driver_check.sender_bot.save') }}</x-driver-check.button>
                            </div>
                        </form>
                    </x-driver-check.surface>
                </div>

                {{-- How it goes, from Start to the groups --}}
                <x-driver-check.surface class="overflow-hidden">
                    <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                        <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.sender_bot.flow.title') }}</h2>
                    </div>
                    <ol class="grid gap-px bg-gray-100 sm:grid-cols-2 xl:grid-cols-3 dark:bg-gray-800">
                        @foreach (__('driver_check.sender_bot.flow.steps') as $i => $step)
                            <li class="flex gap-3 bg-white px-5 py-4 dark:bg-gray-900">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-[12px] font-semibold text-brand-700 dark:bg-brand-500/10 dark:text-brand-400">{{ $i + 1 }}</span>
                                <span class="text-[12.5px] leading-snug text-gray-600 dark:text-gray-300">
                                    <span class="block font-medium text-gray-900 dark:text-gray-100">{{ $step['title'] }}</span>
                                    {{ $step['text'] }}
                                </span>
                            </li>
                        @endforeach
                    </ol>
                </x-driver-check.surface>
                @endif

            </div>
        </template>
    </x-driver-check.shell>
</div>

@endsection
