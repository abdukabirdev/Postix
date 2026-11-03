@extends('layouts.driver-check')

@section('title', __('driver_check.tree.pages.join_' . $part))

@section('content')

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
    <x-driver-check.shell>

        <x-driver-check.page-header
            :title="$part === 'status' ? __('driver_check.join.status_title') : __('driver_check.tree.pages.join_' . $part)"
            :description="$part === 'status' ? __('driver_check.join.description') : __('driver_check.pages.join_' . $part)"
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

                <x-driver-check.button href="{{ route('driver-check.group-join.settings') }}" variant="primary" icon="sliders-h">
                    {{ __('driver_check.menu.group_join_settings') }}
                </x-driver-check.button>
            </x-slot:actions>
        </x-driver-check.page-header>

        <x-driver-check.error-alert :title="__('driver_check.join.errors.load_failed')" retry="load()" />

        <div x-show="!status && loading" class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <template x-for="i in 4" :key="'sk-' + i">
                <div class="dc-skeleton h-24 rounded-2xl bg-gray-100 dark:bg-white/[0.06]"></div>
            </template>
        </div>

        <template x-if="status">
            <div class="flex flex-col gap-5">

                @if ($part === 'status')
                {{-- The watchdog --}}
                <x-driver-check.surface class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-start gap-3">
                        <span
                            class="dc-dot mt-[7px] shrink-0"
                            :class="!status.enabled ? 'text-gray-400' : (status.watchdog.running ? 'animate-pulse text-success-500' : 'text-warning-500')"
                        ></span>
                        <div class="text-[13px] text-gray-700 dark:text-gray-300">
                            <p x-show="!status.enabled">{{ __('driver_check.join.worker.off') }}</p>
                            <p x-show="status.enabled && status.watchdog.running" x-cloak x-text="t.worker.running.replace(':pid', status.watchdog.pid ?? '—').replace(':n', status.accounts.filter((a) => a.running).length).replace(':all', status.accounts.length)"></p>
                            <p x-show="status.enabled && !status.watchdog.running" x-cloak>{{ __('driver_check.join.worker.starting') }}</p>
                            <p class="mt-0.5 font-mono text-[11.5px] text-gray-400" x-text="status.watchdog.command"></p>
                        </div>
                    </div>
                    <a x-show="!status.enabled || status.accounts.length === 0" href="{{ route('driver-check.group-join.settings') }}" class="text-[13px] font-medium text-brand-600 hover:underline dark:text-brand-400">
                        {{ __('driver_check.join.worker.turn_on') }}
                    </a>
                </x-driver-check.surface>

                {{-- Totals: each one leads to its list --}}
                <section class="grid grid-cols-2 gap-3 lg:grid-cols-5">
                    <a href="{{ route('driver-check.group-join.joined') }}">
                        <x-driver-check.stat-card :label="__('driver_check.join.totals.joined')">
                            <span x-text="number(status.totals.joined)"></span>
                        </x-driver-check.stat-card>
                    </a>
                    <a href="{{ route('driver-check.group-join.queue') }}">
                        <x-driver-check.stat-card :label="__('driver_check.join.totals.approved')">
                            <span x-text="number(status.totals.approved)"></span>
                        </x-driver-check.stat-card>
                    </a>
                    <a href="{{ route('driver-check.group-join.queue') }}">
                        <x-driver-check.stat-card :label="__('driver_check.join.totals.new')">
                            <span x-text="number(status.totals.new)"></span>
                        </x-driver-check.stat-card>
                    </a>
                    <x-driver-check.stat-card :label="__('driver_check.join.totals.joins_today')">
                        <span x-text="number(status.totals.joins_today)"></span>
                    </x-driver-check.stat-card>
                    <a href="{{ route('driver-check.group-join.people') }}">
                        <x-driver-check.stat-card :label="__('driver_check.join.totals.people')">
                            <span x-text="number(status.totals.people)"></span>
                        </x-driver-check.stat-card>
                    </a>
                </section>

                @endif

                <div class="grid gap-5">

                    @if ($part === 'status')
                    {{-- Workers --}}
                    <x-driver-check.surface class="flex flex-col overflow-hidden">
                        <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('driver_check.join.accounts.title') }}</h2>
                            <p class="mt-0.5 text-[11.5px] text-gray-400" x-text="t.accounts.hint.replace(':day', status.limits.joins_per_day).replace(':max', status.limits.max_groups_per_account)"></p>
                        </div>

                        <p x-show="status.accounts.length === 0" x-cloak class="px-4 py-8 text-center text-[13px] text-gray-500 dark:text-gray-400">
                            {{ __('driver_check.join.accounts.empty') }}
                        </p>

                        <ul class="max-h-[520px] flex-1 divide-y divide-gray-100 overflow-y-auto dark:divide-gray-800">
                            <template x-for="account in status.accounts" :key="account.id">
                                <li class="flex items-center gap-3 px-4 py-2.5">
                                    <span class="dc-dot shrink-0" :class="accountDot(account)"></span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-[13px] font-medium tabular-nums text-gray-900 dark:text-white">
                                            <span x-text="phone(account.phone) || ('#' + account.id)"></span>
                                            <span x-show="account.pid" x-cloak class="ml-1 font-mono text-[11px] font-normal text-gray-400" x-text="'pid ' + account.pid"></span>
                                        </p>
                                        <p class="dc-break text-[11.5px] text-gray-500 dark:text-gray-400" x-text="accountLine(account)"></p>
                                    </div>
                                    <div class="shrink-0 text-right text-[11.5px] tabular-nums text-gray-500 dark:text-gray-400">
                                        <p><span class="font-semibold text-gray-900 dark:text-white" x-text="account.today"></span> / <span x-text="status.limits.joins_per_day"></span> {{ __('driver_check.join.accounts.today') }}</p>
                                        <p><span x-text="account.held"></span> {{ __('driver_check.join.accounts.groups') }}</p>
                                    </div>
                                    <x-driver-check.button
                                        x-show="account.disabled || account.flood_until || account.full"
                                        x-cloak
                                        size="sm"
                                        x-on:click="enableAccount(account)"
                                        ::disabled="!!busy"
                                    >
                                        {{ __('driver_check.join.accounts.enable') }}
                                    </x-driver-check.button>
                                </li>
                            </template>
                        </ul>
                    </x-driver-check.surface>

                    @endif

                    @if ($part === 'keywords')
                    {{-- Keywords --}}
                    <x-driver-check.surface class="flex flex-col overflow-hidden">
                        <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                            <div>
                                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('driver_check.join.keywords.title') }}</h2>
                                <p class="mt-0.5 text-[11.5px] text-gray-400" x-text="t.keywords.hint.replace(':due', number(status.totals.searches_due)).replace(':all', number(status.totals.searches))"></p>
                            </div>
                            <x-driver-check.button size="sm" icon="search" x-on:click="searchNow()" ::disabled="!!busy || status.keywords.length === 0">
                                {{ __('driver_check.join.keywords.search_now') }}
                            </x-driver-check.button>
                        </div>

                        <label x-show="status.totals.learned > 0" x-cloak class="flex cursor-pointer items-center gap-2 border-b border-gray-100 px-4 py-2 text-[12px] text-gray-600 dark:border-gray-800 dark:text-gray-300">
                            <input type="checkbox" x-model="showLearned" class="h-4 w-4 rounded border-gray-300 text-gray-900 focus:ring-brand-500">
                            <span x-text="t.keywords.show_learned.replace(':n', number(status.totals.learned))"></span>
                        </label>

                        <ul class="max-h-[420px] flex-1 divide-y divide-gray-100 overflow-y-auto dark:divide-gray-800">
                            <template x-for="keyword in keywords" :key="keyword.id">
                                <li class="flex items-center gap-3 px-4 py-2.5">
                                    <span class="dc-dot shrink-0" :class="keyword.is_active ? 'text-success-500' : 'text-gray-400'"></span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-[13px] font-medium text-gray-900 dark:text-white">
                                            <span x-text="keyword.keyword"></span>
                                            <span x-show="keyword.source === 'learned'" x-cloak class="ml-1.5 rounded bg-brand-50 px-1.5 py-0.5 text-[10.5px] font-semibold text-brand-700 dark:bg-brand-500/10 dark:text-brand-400">{{ __('driver_check.join.keywords.learned') }}</span>
                                        </p>
                                        <p class="text-[11.5px] tabular-nums text-gray-500 dark:text-gray-400" x-text="keywordLine(keyword)"></p>
                                    </div>
                                    <button
                                        type="button"
                                        class="rounded-full border px-2 py-0.5 text-[11px] font-semibold transition"
                                        :class="keyword.expand
                                            ? 'border-gray-900 bg-gray-900 text-white dark:border-white dark:bg-white dark:text-gray-900'
                                            : 'border-gray-200 text-gray-500 hover:border-gray-300 dark:border-gray-700 dark:text-gray-400'"
                                        :title="t.keywords.expand_hint"
                                        x-on:click="toggleExpand(keyword)"
                                        :disabled="!!busy"
                                    >{{ __('driver_check.join.keywords.expand') }}</button>
                                    <x-driver-check.button size="sm" variant="ghost" x-on:click="toggleKeyword(keyword)" ::disabled="!!busy">
                                        <span x-text="keyword.is_active ? t.keywords.pause : t.keywords.resume"></span>
                                    </x-driver-check.button>
                                    <x-driver-check.row-action
                                        icon="trash"
                                        :label="__('driver_check.join.keywords.delete')"
                                        class="h-8 w-8 hover:!text-error-600"
                                        x-on:click="removeKeyword(keyword)"
                                        ::disabled="!!busy"
                                    />
                                </li>
                            </template>
                        </ul>

                        <form class="flex flex-col gap-2 border-t border-gray-200 bg-gray-25 px-4 py-3 dark:border-gray-800 dark:bg-white/[0.015]" x-on:submit.prevent="addKeywords()">
                            <textarea
                                x-model="keywordForm.keyword"
                                rows="2"
                                class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 outline-none focus:border-brand-500 focus:ring-[3px] focus:ring-brand-500/15 dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                placeholder="{{ __('driver_check.join.keywords.placeholder') }}"
                            ></textarea>
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <label class="flex shrink-0 cursor-pointer items-center gap-2 text-[12.5px] text-gray-600 dark:text-gray-300">
                                    <input type="checkbox" x-model="keywordForm.expand" class="h-4 w-4 rounded border-gray-300 text-gray-900 focus:ring-brand-500">
                                    {{ __('driver_check.join.keywords.expand_label') }}
                                </label>
                                <x-driver-check.button type="submit" variant="primary" ::disabled="!!busy || keywordForm.keyword.trim() === ''">
                                    {{ __('driver_check.join.keywords.add') }}
                                </x-driver-check.button>
                            </div>
                        </form>
                    </x-driver-check.surface>
                    @endif
                </div>

                @if ($part === 'log')
                {{-- Log --}}
                <x-driver-check.surface class="overflow-hidden">
                    <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('driver_check.join.log.title') }}</h2>
                    </div>

                    <p x-show="logs.length === 0" x-cloak class="px-4 py-8 text-center text-[13px] text-gray-500 dark:text-gray-400">
                        {{ __('driver_check.join.log.empty') }}
                    </p>

                    <ul class="max-h-[560px] divide-y divide-gray-100 overflow-y-auto font-mono text-[12px] dark:divide-gray-800">
                        <template x-for="log in logs" :key="log.id">
                            <li class="flex flex-col gap-0.5 px-4 py-2 sm:flex-row sm:gap-3">
                                <span class="shrink-0 tabular-nums text-gray-400" x-text="clock(log.created_at)"></span>
                                <span class="w-24 shrink-0 font-semibold" :class="eventTone(log)" x-text="eventText(log)"></span>
                                <span class="shrink-0 tabular-nums text-gray-500 dark:text-gray-400" x-show="log.account" x-text="phone(log.account?.phone)"></span>
                                <span class="min-w-0 dc-break text-gray-700 dark:text-gray-300">
                                    <span x-show="log.group" class="font-semibold" x-text="(log.group?.title || log.group?.link) + ': '"></span>
                                    <span x-text="log.message"></span>
                                </span>
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
