@extends('layouts.driver-check')

@section('title', __('driver_check.menu.contact_settings'))

@section('content')

<div
    x-data="dcContactSettings({
        endpoint: @js(route('api.telegram.contact-sync.settings')),
        translations: @js(__('driver_check.sync.settings')),
    })"
>
    <x-driver-check.shell>

        <x-driver-check.page-header
            :title="__('driver_check.menu.contact_settings')"
            :description="__('driver_check.sync.settings.text')"
        >
            <x-slot:actions>
                <x-driver-check.button href="{{ route('driver-check.contact-report') }}" variant="primary" icon="chart">
                    {{ __('driver_check.sync.history') }}
                </x-driver-check.button>
            </x-slot:actions>
        </x-driver-check.page-header>

        <x-driver-check.error-alert :title="__('driver_check.sync.settings.load_failed')" retry="load()" />

        <div x-show="loading && !form" class="dc-skeleton h-72 rounded-2xl bg-gray-100 dark:bg-white/[0.06]"></div>

        <template x-if="form">
            <form class="max-w-5xl" x-on:submit.prevent="save()">
                <x-driver-check.surface class="overflow-hidden">
                    <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                        <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.sync.settings.title') }}</h2>

                        <span
                            class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-600 dark:bg-white/[0.07] dark:text-gray-300"
                            x-text="savedInPanel ? s.source_saved : s.source_default"
                        ></span>
                    </div>

                    <div class="divide-y divide-gray-100 dark:divide-gray-800">

                        {{-- When --}}
                        <section class="grid gap-x-5 gap-y-4 p-4 sm:grid-cols-2 xl:grid-cols-4">
                            <div class="sm:col-span-2">
                                <label class="flex cursor-pointer items-start gap-3">
                                    <span class="relative mt-0.5 inline-flex h-6 w-10 shrink-0 items-center">
                                        <input type="checkbox" x-model="form.enabled" class="peer sr-only">
                                        <span class="absolute inset-0 rounded-full bg-gray-300 transition peer-checked:bg-brand-500 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/50 dark:bg-gray-700"></span>
                                        <span class="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-4"></span>
                                    </span>
                                    <span>
                                        <span class="block text-[13px] font-semibold text-gray-900 dark:text-white">{{ __('driver_check.sync.settings.enabled') }}</span>
                                        <span class="block text-[11.5px] leading-snug text-gray-400">{{ __('driver_check.sync.settings.enabled_hint') }}</span>
                                    </span>
                                </label>
                            </div>

                            <x-driver-check.field :label="__('driver_check.sync.settings.schedule_at')" :hint="__('driver_check.sync.settings.schedule_at_hint')">
                                <x-driver-check.input type="time" x-model="form.schedule_at" required />
                            </x-driver-check.field>
                        </section>

                        {{-- How many --}}
                        <section class="grid gap-x-5 gap-y-4 p-4 sm:grid-cols-2">
                            <x-driver-check.field :label="__('driver_check.sync.settings.daily_limit')" :hint="__('driver_check.sync.settings.daily_limit_hint')">
                                <x-driver-check.input type="number" inputmode="numeric" x-model="form.daily_limit" ::min="limits.daily_limit?.[0]" ::max="limits.daily_limit?.[1]" required />
                            </x-driver-check.field>

                            <label class="flex cursor-pointer items-start gap-3 sm:pt-6">
                                <span class="relative mt-0.5 inline-flex h-6 w-10 shrink-0 items-center">
                                    <input type="checkbox" x-model="form.skip_usernames" class="peer sr-only">
                                    <span class="absolute inset-0 rounded-full bg-gray-300 transition peer-checked:bg-brand-500 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/50 dark:bg-gray-700"></span>
                                    <span class="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-4"></span>
                                </span>
                                <span>
                                    <span class="block text-[13px] font-semibold text-gray-900 dark:text-white">{{ __('driver_check.sync.settings.skip_usernames') }}</span>
                                    <span class="block text-[11.5px] leading-snug text-gray-400">{{ __('driver_check.sync.settings.skip_usernames_hint') }}</span>
                                </span>
                            </label>
                        </section>

                        {{-- How fast --}}
                        <section class="grid gap-x-5 gap-y-4 p-4 sm:grid-cols-2">
                            <x-driver-check.field :label="__('driver_check.sync.settings.delay_min')" :hint="__('driver_check.sync.settings.delay_hint')">
                                <x-driver-check.input type="number" inputmode="numeric" x-model="form.delay_min" ::min="limits.delay_min?.[0]" ::max="limits.delay_min?.[1]" required />
                            </x-driver-check.field>

                            <x-driver-check.field :label="__('driver_check.sync.settings.delay_max')">
                                <x-driver-check.input type="number" inputmode="numeric" x-model="form.delay_max" ::min="form.delay_min" ::max="limits.delay_min?.[1]" required />
                            </x-driver-check.field>
                        </section>

                        {{-- Who takes part --}}
                        <section class="p-4">
                            <div class="mb-2 flex items-baseline justify-between gap-3">
                                <div>
                                    <h3 class="text-[13px] font-semibold text-gray-900 dark:text-white">{{ __('driver_check.sync.settings.excluded') }}</h3>
                                    <p class="mt-0.5 text-[11.5px] leading-snug text-gray-400">{{ __('driver_check.sync.settings.excluded_hint') }}</p>
                                </div>
                                <span class="shrink-0 text-[12px] tabular-nums text-gray-500" x-text="s.taking_part.replace(':n', takingPart())"></span>
                            </div>

                            <p x-show="accounts.length === 0" x-cloak class="rounded-lg border border-dashed border-gray-300 p-4 text-center text-[13px] text-gray-500 dark:border-gray-700">{{ __('driver_check.sync.settings.excluded_none') }}</p>

                            <ul class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                                <template x-for="account in accounts" :key="account.id">
                                    <li>
                                        <label
                                            class="flex items-center gap-3 rounded-lg border px-3 py-2.5 transition"
                                            :class="[
                                                isExcluded(account) ? 'border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-white/[0.02]' : 'border-gray-200 bg-white dark:border-gray-800 dark:bg-transparent',
                                                account.is_primary ? 'cursor-not-allowed' : 'cursor-pointer hover:border-gray-300 dark:hover:border-gray-600',
                                            ]"
                                        >
                                            <input
                                                type="checkbox"
                                                class="h-4 w-4 shrink-0 rounded border-gray-300 text-gray-900 focus:ring-brand-500"
                                                :checked="isExcluded(account)"
                                                :disabled="account.is_primary"
                                                x-on:change="toggleExcluded(account)"
                                            >
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate text-[13px] font-medium tabular-nums text-gray-900 dark:text-white" x-text="account.phone || ('#' + account.id)"></span>
                                                <span class="block truncate text-[11.5px] text-gray-400" x-text="account.is_primary ? s.excluded_primary : (account.username ? '@' + account.username : '')"></span>
                                            </span>
                                            <span x-show="isExcluded(account)" x-cloak class="shrink-0 rounded bg-gray-200 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ __('driver_check.sync.settings.excluded_tag') }}</span>
                                        </label>
                                    </li>
                                </template>
                            </ul>
                        </section>

                        {{-- Under which name --}}
                        <section class="p-4">
                            <x-driver-check.field :label="__('driver_check.sync.settings.nick_base')">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                                    <x-driver-check.input x-model="form.nick_base" maxlength="60" :placeholder="__('driver_check.sync.settings.nick_placeholder')" class="sm:max-w-sm" />

                                    <p x-show="nickPreview()" x-cloak class="text-[12.5px] text-gray-500 dark:text-gray-400">
                                        {{ __('driver_check.sync.settings.nick_preview') }}:
                                        <span class="font-mono font-medium text-gray-900 dark:text-white" x-text="nickPreview()"></span>
                                    </p>
                                </div>

                                <x-slot:error>
                                    <p class="mt-1.5 text-[11.5px] leading-snug text-gray-400 dark:text-gray-500">{{ __('driver_check.sync.settings.nick_hint') }}</p>
                                </x-slot:error>
                            </x-driver-check.field>
                        </section>
                    </div>

                    <div class="flex flex-col-reverse gap-2 border-t border-gray-200 bg-gray-25 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800 dark:bg-white/[0.015]">
                        <span x-show="dirty" x-cloak class="inline-flex items-center gap-1.5 text-[12px] font-medium text-warning-700 dark:text-warning-400">
                            <span class="h-1.5 w-1.5 rounded-full bg-warning-500"></span>
                            {{ __('driver_check.sync.settings.dirty') }}
                        </span>
                        <span x-show="!dirty" class="hidden sm:block"></span>

                        <div class="flex items-center gap-2 sm:justify-end">
                            <x-driver-check.button variant="ghost" x-on:click="reset()" ::disabled="busy || !savedInPanel">
                                {{ __('driver_check.sync.settings.reset') }}
                            </x-driver-check.button>
                            <x-driver-check.button type="submit" variant="primary" ::disabled="busy || !dirty">
                                {{ __('driver_check.sync.settings.save') }}
                            </x-driver-check.button>
                        </div>
                    </div>
                </x-driver-check.surface>
            </form>
        </template>
    </x-driver-check.shell>
</div>

@endsection
