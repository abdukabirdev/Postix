@extends('layouts.driver-check')

@section('title', $part === 'alerts' ? __('driver_check.tree.pages.alerts') : __('telegram.system.title'))

@section('content')

<div
    x-data="dcSystem({
        endpoints: { index: @js(route('api.telegram.system')) },
        translations: @js(__('telegram.system')),
        ui: @js(__('telegram.ui')),
        part: @js($part),
    })"
>
    <x-driver-check.shell>

        <x-driver-check.page-header
            :title="$part === 'alerts' ? __('driver_check.tree.pages.alerts') : __('telegram.system.title')"
            :description="$part === 'alerts' ? __('telegram.system.alerts.description') : __('telegram.system.description')"
        >
            <x-slot:actions>
                <x-driver-check.button x-on:click="load()" ::disabled="loading">
                    <x-driver-check.icon name="refresh" class="h-4 w-4" ::class="loading && 'animate-spin'" />
                    <span x-text="loading ? ui.loading : ui.refresh"></span>
                </x-driver-check.button>
            </x-slot:actions>
        </x-driver-check.page-header>

        <x-driver-check.error-alert :title="__('telegram.system.errors.title')" />

        @if ($part === 'map')
        {{-- The count --}}
        <div
            class="flex items-start gap-2.5 rounded-2xl border border-gray-200 bg-gray-25 px-3.5 py-2.5 text-[13px] leading-relaxed text-gray-600 dark:border-gray-800 dark:bg-white/[0.02] dark:text-gray-300"
        >
            <x-driver-check.icon name="bot" class="mt-0.5 h-4 w-4 shrink-0 text-gray-400" />
            <p>
                <span class="font-semibold text-gray-900 dark:text-white" x-text="translations.count.replace(':total', logics.length).replace(':movable', movableCount())"></span>
                <span x-text="translations.count_hint"></span>
            </p>
        </div>

        {{-- Independent logics --}}
        <div class="flex flex-col gap-3">
            <x-driver-check.skeleton-cards />

            <template x-for="logic in logics" :key="logic.key">
                <x-driver-check.surface class="p-4">
                    <article class="flex flex-col gap-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="dc-break text-sm font-semibold text-gray-900 dark:text-white" x-text="logicName(logic)"></p>
                                <p class="mt-0.5 text-[12.5px] leading-relaxed text-gray-600 dark:text-gray-300" x-text="logicWhat(logic)"></p>
                                <p
                                    x-show="logicNote(logic)"
                                    class="mt-1 text-[11.5px] leading-relaxed text-gray-500 dark:text-gray-400"
                                    x-text="logicNote(logic)"
                                ></p>
                            </div>

                            <span
                                class="shrink-0 rounded-md px-2 py-0.5 text-[11.5px] font-medium"
                                :class="logic.movable
                                    ? 'bg-brand-25 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300'
                                    : 'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300'"
                                x-text="logic.movable ? translations.movable : translations.fixed"
                            ></span>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-[11.5px] text-gray-500 dark:text-gray-400" x-text="translations.done_by"></span>

                            <template x-for="item in logic.accounts" :key="item.name">
                                <span class="inline-flex items-center gap-1.5 rounded-lg bg-gray-100 px-2.5 py-1 text-[12.5px] font-medium text-gray-800 dark:bg-white/[0.06] dark:text-gray-100">
                                    <span class="h-1.5 w-1.5 rounded-full" :class="dotClass(item.running)"></span>
                                    <span x-text="item.name"></span>
                                    <span x-show="item.count !== undefined" class="font-normal text-gray-500" x-text="'· ' + item.count"></span>
                                </span>
                            </template>

                            <span
                                x-show="logic.accounts.length === 0"
                                class="text-[12.5px] text-gray-500 dark:text-gray-400"
                                x-text="translations.own_accounts"
                            ></span>

                            <a
                                :href="logic.link"
                                class="ml-auto text-[13px] font-medium text-brand-600 hover:underline dark:text-brand-400"
                                x-text="logic.movable ? translations.change : translations.open"
                            ></a>
                        </div>
                    </article>
                </x-driver-check.surface>
            </template>
        </div>

        {{-- How a penalty and a file travel --}}
        <x-driver-check.surface class="p-4">
            <div class="flex flex-col gap-4">
                @foreach (['penalty', 'file'] as $flow)
                    <div>
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ __("telegram.system.flows.{$flow}.title") }}</p>
                        <ol class="mt-1.5 list-decimal space-y-1 pl-5 text-[12.5px] leading-relaxed text-gray-600 dark:text-gray-300">
                            @foreach (__("telegram.system.flows.{$flow}.steps") as $step)
                                <li>{{ $step }}</li>
                            @endforeach
                        </ol>
                    </div>
                @endforeach
            </div>
        </x-driver-check.surface>

        {{-- Whether each account's process is alive: Monitoring > Processes --}}
        <p class="text-[12.5px] text-gray-600 dark:text-gray-300">
            {{ __('driver_check.pages.system_processes') }}
            <a href="{{ route('driver-check.watchdog') }}" class="font-medium text-brand-600 hover:underline dark:text-brand-400">{{ __('driver_check.tree.pages.processes') }} →</a>
        </p>
        @endif

        @if ($part === 'alerts')
        {{-- Problems told to the developer --}}
        <p x-show="!alerts.configured" x-cloak class="rounded-lg bg-warning-50 px-3 py-2 text-[12.5px] text-warning-700 dark:bg-warning-500/10 dark:text-warning-400" x-text="translations.alerts.not_configured"></p>

        <x-driver-check.block>
            <div class="dc-toolbar">
                <div class="dc-toolbar__grow">
                    <x-driver-check.search model="filters.search" :placeholder="__('telegram.system.alerts.search')" debounce="400ms" />
                </div>

                <x-driver-check.select x-model="filters.kind" x-on:change="load()" class="!w-56" aria-label="{{ __('telegram.system.alerts.kind') }}">
                    <option value="">{{ __('telegram.system.alerts.all_kinds') }}</option>
                    <template x-for="kind in alerts.kinds" :key="kind">
                        <option :value="kind" x-text="kind" :selected="kind === filters.kind"></option>
                    </template>
                </x-driver-check.select>

                <div class="dc-toolbar__end">
                    <x-driver-check.select x-model.number="filters.limit" x-on:change="load()" class="!w-auto" aria-label="{{ __('telegram.system.alerts.limit') }}" title="{{ __('telegram.system.alerts.limit') }}">
                        @foreach ([20, 50, 100, 200] as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </x-driver-check.select>
                </div>
            </div>

            <div x-show="loading && alerts.recent.length === 0" class="dc-block__wait" aria-hidden="true">
                @foreach ([72, 54, 86, 40] as $width)
                    <div class="dc-skeleton h-3 rounded bg-gray-100 dark:bg-white/[0.06]" style="width: {{ $width }}%"></div>
                @endforeach
            </div>

            <p x-show="!loading && alerts.recent.length === 0" x-cloak class="dc-none" x-text="translations.alerts.empty"></p>

            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                <template x-for="alert in alerts.recent" :key="alert.id">
                    <li class="px-4 py-2.5 hover:bg-gray-25 dark:hover:bg-white/[0.02]">
                        <button type="button" class="block w-full text-left" x-on:click="toggle(alert)">
                            <span class="flex items-start justify-between gap-3">
                                <span class="dc-break text-[13px] font-medium text-gray-900 dark:text-white" x-text="alert.title"></span>
                                <span class="shrink-0 text-[11.5px] text-gray-500 dark:text-gray-400" :title="date(alert.last_seen_at)" x-text="relative(alert.last_seen_at)"></span>
                            </span>
                            <span x-show="alert.details" class="dc-break mt-0.5 block whitespace-pre-line text-[12px] text-gray-600 dark:text-gray-300" :class="!opened[alert.id] && 'line-clamp-2'" x-text="alert.details"></span>
                        </button>
                        <p class="mt-1 flex flex-wrap items-center gap-1.5 text-[11.5px] text-gray-500 dark:text-gray-400">
                            <span class="dc-tag" x-text="alert.kind"></span>
                            <span x-show="alert.listener_key" x-text="alert.listener_key"></span>
                            <span x-show="alert.occurrences > 1" class="dc-tag" data-tone="warn" x-text="'×' + alert.occurrences"></span>
                            <span class="dc-tag" :data-tone="alert.notified ? 'ok' : 'warn'" x-text="alert.notified ? translations.alerts.sent : translations.alerts.not_sent"></span>
                            <span x-show="alert.notify_error" class="dc-break text-error-600 dark:text-error-400" x-text="alert.notify_error"></span>
                        </p>
                    </li>
                </template>
            </ul>
        </x-driver-check.block>
        @endif

    </x-driver-check.shell>
</div>

@endsection
