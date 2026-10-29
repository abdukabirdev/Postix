@extends('layouts.driver-check')

@section('title', __('driver_check.menu.groups'))

@section('content')

<div
    x-data="dcContactSync({
        endpoints: {
            show: @js(route('api.telegram.contact-sync.show')),
            groups: @js(url('/api/telegram/contact-sync/groups')),
            accounts: @js(url('/api/telegram/contact-sync/accounts')),
        },
        translations: @js(__('telegram.contact_sync')),
        ui: @js(__('telegram.ui')),
    })"
>
    <x-driver-check.shell>

        {{-- Header: refresh, then the way to the history (where the Run button is) --}}
        <x-driver-check.page-header
            :title="__('driver_check.menu.groups')"
            :description="__('telegram.contact_sync.description')"
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

                <x-driver-check.button href="{{ route('driver-check.contact-report') }}" variant="primary" icon="chart">
                    {{ __('driver_check.sync.history') }}
                </x-driver-check.button>
            </x-slot:actions>
        </x-driver-check.page-header>

        <x-driver-check.error-alert :title="__('telegram.contact_sync.errors.title')" />

        <div x-show="!status && loading" class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <template x-for="i in 4" :key="'sk-' + i">
                <div class="dc-skeleton h-24 rounded-2xl bg-gray-100 dark:bg-white/[0.06]"></div>
            </template>
        </div>

        <template x-if="status">
            <div class="flex flex-col gap-5">

                {{-- Totals --}}
                <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    @foreach (['users', 'added', 'failed'] as $key)
                        <x-driver-check.stat-card :label="__('telegram.contact_sync.totals.' . $key)">
                            <span x-text="number(status.totals.{{ $key }})"></span>
                        </x-driver-check.stat-card>
                    @endforeach

                    {{-- Who is still to save, and how many accounts would save them in one day --}}
                    <x-driver-check.stat-card :label="__('telegram.contact_sync.totals.unassigned')">
                        <span x-text="number(status.totals.unassigned)"></span>
                        <x-slot:hint>
                            <span x-text="accountsNeeded()"></span>
                        </x-slot:hint>
                    </x-driver-check.stat-card>
                </section>

                {{-- Groups --}}
                <x-driver-check.surface class="overflow-hidden">
                    <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('telegram.contact_sync.groups.title') }}</h2>
                        {{-- Shows while a run is in progress --}}
                        <span x-show="status.running" x-cloak class="dc-dot animate-pulse text-blue-light-500"></span>
                    </div>

                    <p x-show="status.groups.length === 0" x-cloak class="px-4 py-8 text-center text-[13px] text-gray-500 dark:text-gray-400">
                        {{ __('telegram.contact_sync.groups.empty') }}
                    </p>

                    <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                        <template x-for="group in status.groups" :key="group.id">
                            <li class="dc-row flex flex-col gap-2 px-4 py-3 hover:bg-gray-25 sm:flex-row sm:items-center sm:gap-3 dark:hover:bg-white/[0.025]">
                                <div class="flex min-w-0 flex-1 items-start gap-3">
                                    <span class="dc-dot mt-[7px] shrink-0" :class="group.is_active ? 'text-success-500' : 'text-gray-400'"></span>

                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium text-gray-900 dark:text-white" x-text="groupName(group)"></p>
                                        <p class="text-[12px] text-gray-500 dark:text-gray-400">
                                            <span class="tabular-nums" x-text="number(group.users_count) + ' ' + t.groups.people"></span>
                                            ·
                                            <span class="tabular-nums" :class="group.waiting_count > 0 && 'text-warning-700 dark:text-warning-400'" x-text="t.groups.waiting.replace(':n', number(group.waiting_count ?? 0))"></span>
                                            ·
                                            <span x-text="group.last_synced_at ? relative(group.last_synced_at) : ui.never"></span>
                                        </p>
                                        <p x-show="group.last_error" x-cloak class="dc-break mt-0.5 text-[12px] text-error-600 dark:text-error-400" x-text="group.last_error"></p>
                                    </div>
                                </div>

                                <div class="flex shrink-0 items-center gap-1.5 pl-[19px] sm:pl-0">
                                    {{-- Who is in it, into the database only: nobody is saved into contacts --}}
                                    <x-driver-check.button
                                        size="sm"
                                        x-on:click="fetchMembers(group)"
                                        ::disabled="!!busy || group.fetching"
                                        :title="__('telegram.contact_sync.groups.fetch_hint')"
                                    >
                                        <x-driver-check.icon name="download" class="h-3.5 w-3.5 shrink-0" x-show="!group.fetching" />
                                        <x-driver-check.icon name="refresh" class="h-3.5 w-3.5 shrink-0 animate-spin" x-show="group.fetching" x-cloak />
                                        <span x-text="group.fetching ? t.groups.fetching : t.groups.fetch"></span>
                                    </x-driver-check.button>
                                    <x-driver-check.button size="sm" x-on:click="toggleGroup(group)" ::disabled="!!busy">
                                        <span x-text="group.is_active ? t.groups.pause : t.groups.resume"></span>
                                    </x-driver-check.button>
                                    <x-driver-check.row-action
                                        icon="trash"
                                        :label="__('telegram.contact_sync.groups.delete')"
                                        class="h-9 w-9 sm:h-8 sm:w-8 hover:!text-error-600"
                                        x-on:click="removeGroup(group)"
                                        ::disabled="!!busy"
                                    />
                                </div>
                            </li>
                        </template>
                    </ul>

                    {{-- Add a group: the footer row of the panel --}}
                    <form class="flex gap-2 border-t border-gray-200 bg-gray-25 px-4 py-3 dark:border-gray-800 dark:bg-white/[0.015]" x-on:submit.prevent="addGroup()">
                        <x-driver-check.input x-model="newLink" placeholder="https://t.me/group" />
                        <x-driver-check.button type="submit" variant="primary" ::disabled="!!busy || newLink.trim() === ''">
                            {{ __('telegram.contact_sync.groups.add') }}
                        </x-driver-check.button>
                    </form>
                </x-driver-check.surface>
            </div>
        </template>
    </x-driver-check.shell>
</div>

@endsection
