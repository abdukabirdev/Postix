@extends('layouts.driver-check')

@section('title', __('driver_check.menu.group_invites_settings'))

@section('content')

<div
    x-data="dcGroupInvitesSettings({
        endpoints: {
            show: @js(route('api.telegram.group-invites.settings')),
            update: @js(route('api.telegram.group-invites.settings.update')),
        },
        translations: @js(__('driver_check.invites')),
    })"
>
    <x-driver-check.shell>

        <x-driver-check.page-header
            :title="__('driver_check.menu.group_invites_settings')"
            :description="__('driver_check.invites.settings.description')"
        />

        @include('pages.driver-check.partials.invites-nav')

        <x-driver-check.error-alert :title="__('driver_check.invites.load_failed')" retry="load()" />

        <div x-show="loading && !form" class="dc-skeleton h-96 max-w-4xl rounded-2xl bg-gray-100 dark:bg-white/[0.06]"></div>

        <template x-if="form">
            <form class="flex max-w-4xl flex-col gap-5" x-on:submit.prevent="save()">

                {{-- When --}}
                <x-driver-check.surface class="overflow-hidden">
                    <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                        <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.settings.sections.schedule') }}</h2>
                    </div>
                    <div class="grid gap-4 p-4 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-start gap-3">
                            <span class="relative mt-0.5 inline-flex h-6 w-10 shrink-0 items-center">
                                <input type="checkbox" x-model="form.enabled" class="peer sr-only">
                                <span class="absolute inset-0 rounded-full bg-gray-300 transition peer-checked:bg-brand-500 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/50 dark:bg-gray-700"></span>
                                <span class="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-4"></span>
                            </span>
                            <span>
                                <span class="block text-[13px] font-semibold text-gray-900 dark:text-white">{{ __('driver_check.invites.settings.enabled') }}</span>
                                <span class="block text-[11.5px] leading-snug text-gray-400">{{ __('driver_check.invites.settings.enabled_hint') }}</span>
                            </span>
                        </label>

                        <x-driver-check.field :label="__('driver_check.invites.settings.schedule_at')" :hint="__('driver_check.invites.settings.schedule_at_hint')">
                            <x-driver-check.input type="time" x-model="form.schedule_at" ::disabled="!form.enabled" required />
                        </x-driver-check.field>
                    </div>
                </x-driver-check.surface>

                {{-- Who --}}
                <x-driver-check.surface class="overflow-hidden">
                    <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                        <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.settings.sections.audience') }}</h2>
                        <p class="mt-0.5 text-[12px] text-gray-400">{{ __('driver_check.invites.settings.audience_lead') }}</p>
                    </div>
                    <div class="grid gap-3 p-4 sm:grid-cols-2">
                        <template x-for="option in audiences()" :key="option.value">
                            <label
                                class="flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 transition"
                                :class="form.audience === option.value
                                    ? 'border-gray-900 bg-white dark:border-white dark:bg-white/[0.04]'
                                    : 'border-gray-200 hover:border-gray-400 dark:border-gray-800 dark:hover:border-gray-600'"
                            >
                                <input type="radio" name="audience" class="mt-0.5 h-4 w-4 border-gray-300 text-gray-900 focus:ring-brand-500" :value="option.value" x-model="form.audience">
                                <span>
                                    <span class="block text-[13px] font-semibold text-gray-900 dark:text-white" x-text="option.label"></span>
                                    <span class="mt-0.5 block text-[11.5px] leading-snug text-gray-500" x-text="option.hint"></span>
                                </span>
                            </label>
                        </template>
                    </div>
                </x-driver-check.surface>

                {{-- How much --}}
                <x-driver-check.surface class="overflow-hidden">
                    <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                        <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.settings.sections.pace') }}</h2>
                        <p class="mt-0.5 text-[12px] text-gray-400" x-text="t.settings.per_account.replace(':n', number(perAccount()))"></p>
                    </div>
                    <div class="grid gap-4 p-4 sm:grid-cols-2">
                        <x-driver-check.field :label="__('driver_check.invites.settings.batch_size')" :hint="__('driver_check.invites.settings.batch_size_hint')">
                            <x-driver-check.input type="number" inputmode="numeric" x-model="form.batch_size" ::min="limits.batch_size?.[0]" ::max="limits.batch_size?.[1]" required />
                        </x-driver-check.field>

                        <x-driver-check.field :label="__('driver_check.invites.settings.batches_per_day')" :hint="__('driver_check.invites.settings.batches_per_day_hint')">
                            <x-driver-check.input type="number" inputmode="numeric" x-model="form.batches_per_day" ::min="limits.batches_per_day?.[0]" ::max="limits.batches_per_day?.[1]" required />
                        </x-driver-check.field>

                        <x-driver-check.field :label="__('driver_check.invites.settings.delay_min')" :hint="__('driver_check.invites.settings.delay_hint')">
                            <x-driver-check.input type="number" inputmode="numeric" x-model="form.delay_min" ::min="limits.delay_min?.[0]" ::max="limits.delay_min?.[1]" required />
                        </x-driver-check.field>

                        <x-driver-check.field :label="__('driver_check.invites.settings.delay_max')">
                            <x-driver-check.input type="number" inputmode="numeric" x-model="form.delay_max" ::min="form.delay_min" ::max="limits.delay_max?.[1]" required />
                        </x-driver-check.field>
                    </div>
                </x-driver-check.surface>

                {{-- @username --}}
                <x-driver-check.surface class="overflow-hidden">
                    <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                        <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.settings.sections.usernames') }}</h2>
                    </div>
                    <div class="grid gap-4 p-4 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-start gap-3">
                            <span class="relative mt-0.5 inline-flex h-6 w-10 shrink-0 items-center">
                                <input type="checkbox" x-model="form.use_usernames" class="peer sr-only">
                                <span class="absolute inset-0 rounded-full bg-gray-300 transition peer-checked:bg-brand-500 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/50 dark:bg-gray-700"></span>
                                <span class="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-4"></span>
                            </span>
                            <span>
                                <span class="block text-[13px] font-semibold text-gray-900 dark:text-white">{{ __('driver_check.invites.settings.use_usernames') }}</span>
                                <span class="block text-[11.5px] leading-snug text-gray-400">{{ __('driver_check.invites.settings.use_usernames_hint') }}</span>
                            </span>
                        </label>

                        <x-driver-check.field :label="__('driver_check.invites.settings.usernames_per_day')" :hint="__('driver_check.invites.settings.usernames_per_day_hint')">
                            <x-driver-check.input type="number" inputmode="numeric" x-model="form.usernames_per_day" ::disabled="!form.use_usernames" ::min="limits.usernames_per_day?.[0]" ::max="limits.usernames_per_day?.[1]" required />
                        </x-driver-check.field>
                    </div>
                </x-driver-check.surface>

                <div class="sticky bottom-3 z-10 flex flex-wrap items-center justify-end gap-2 rounded-2xl border border-gray-200 bg-white/95 px-4 py-3 shadow-sm backdrop-blur dark:border-gray-800 dark:bg-gray-950/95">
                    <span class="mr-auto text-[12px] text-gray-500" x-text="dirty ? t.settings.dirty : (saved ? t.settings.source_saved : t.settings.source_default)"></span>
                    <x-driver-check.button x-on:click="useDefaults()" ::disabled="busy">
                        {{ __('driver_check.invites.settings.defaults') }}
                    </x-driver-check.button>
                    <x-driver-check.button type="submit" variant="primary" ::disabled="busy || !dirty">
                        {{ __('driver_check.invites.settings.save') }}
                    </x-driver-check.button>
                </div>
            </form>
        </template>
    </x-driver-check.shell>
</div>

@endsection
