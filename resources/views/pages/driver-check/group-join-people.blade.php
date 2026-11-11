@extends('layouts.driver-check')

@section('title', __('driver_check.menu.group_join_people'))

@section('content')

<div
    x-data="dcGroupJoinPeople({
        endpoint: @js(route('api.telegram.group-join.people')),
        translations: @js(__('driver_check.join')),
    })"
>
    <x-driver-check.shell>

        <x-driver-check.page-header
            :title="__('driver_check.menu.group_join_people')"
            :description="__('driver_check.join.people.text')"
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

                <x-driver-check.button href="{{ route('driver-check.group-join.joined') }}" variant="primary" icon="check-circle">
                    {{ __('driver_check.menu.group_join_joined') }}
                </x-driver-check.button>
            </x-slot:actions>
        </x-driver-check.page-header>

        <x-driver-check.surface class="overflow-hidden">
            <div class="flex flex-col gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
                        <span x-text="t.people.found.replace(':n', total === null ? '…' : number(total)).replace(':all', number(all))"></span>
                    </h2>

                    {{-- Opened from a group: its people only --}}
                    <span x-show="group" x-cloak class="inline-flex items-center gap-2 rounded-full bg-gray-100 py-1 pl-3 pr-1 text-[12px] font-medium text-gray-700 dark:bg-white/[0.06] dark:text-gray-200">
                        <span x-text="group?.title || group?.link"></span>
                        <button type="button" class="rounded-full p-0.5 hover:bg-gray-200 dark:hover:bg-white/10" x-on:click="clearGroup()" :aria-label="t.people.all_groups">
                            <x-driver-check.icon name="close" class="h-3.5 w-3.5" />
                        </button>
                    </span>
                </div>

                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <x-driver-check.input x-model="filters.search" x-on:input.debounce.500ms="load()" :placeholder="__('driver_check.join.people.search')" class="sm:max-w-md" />
                    <label class="flex shrink-0 cursor-pointer items-center gap-2 text-[12.5px] text-gray-600 dark:text-gray-300">
                        <input type="checkbox" x-model="filters.phone" x-on:change="load()" class="h-4 w-4 rounded border-gray-300 text-gray-900 focus:ring-brand-500">
                        {{ __('driver_check.join.people.with_phone') }}
                    </label>
                </div>
            </div>

            <div x-show="loading && rows.length === 0" class="space-y-2 p-4">
                <template x-for="i in 6" :key="'sk-' + i">
                    <div class="dc-skeleton h-12 rounded-xl bg-gray-100 dark:bg-white/[0.06]"></div>
                </template>
            </div>

            <p x-show="!loading && rows.length === 0" x-cloak class="px-4 py-12 text-center text-[13px] text-gray-500 dark:text-gray-400">
                {{ __('driver_check.join.people.empty') }}
            </p>

            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                <template x-for="row in rows" :key="row.id">
                    <li class="flex flex-col gap-1.5 px-4 py-2.5 sm:flex-row sm:items-center sm:gap-4">
                        <div class="min-w-0 sm:w-[32%]">
                            <p class="truncate text-[13px] font-medium text-gray-900 dark:text-white" x-text="row.name || dash"></p>
                            <p class="truncate text-[12px] text-gray-500 dark:text-gray-400">
                                <a :href="telegramUrl(row)" target="_blank" rel="noopener" class="hover:underline" x-text="row.username ? '@' + row.username : ('ID ' + row.telegram_user_id)"></a>
                                <span x-show="row.phone" x-cloak class="tabular-nums" x-text="' · ' + phone(row.phone)"></span>
                            </p>
                        </div>

                        <div class="flex min-w-0 flex-1 flex-wrap items-center gap-1.5">
                            <template x-for="g in row.groups" :key="g.id">
                                <span
                                    class="max-w-[220px] truncate rounded-md px-1.5 py-0.5 text-[11px] font-medium"
                                    :class="g.type === 'contacts' ? 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400' : 'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300'"
                                    x-text="g.title"
                                ></span>
                            </template>
                            <span x-show="moreGroups(row) > 0" x-cloak class="text-[11px] text-gray-400" x-text="t.people.more.replace(':n', moreGroups(row))"></span>
                        </div>

                        <span class="shrink-0 text-[11.5px] text-gray-400" :title="date(row.found_at)" x-text="relative(row.found_at)"></span>
                    </li>
                </template>
            </ul>

            <div x-show="hasMore" x-cloak class="border-t border-gray-100 px-4 py-3 text-center dark:border-gray-800">
                <x-driver-check.button size="sm" x-on:click="load(true)" ::disabled="loading">
                    {{ __('driver_check.list.more') }}
                </x-driver-check.button>
            </div>
        </x-driver-check.surface>
    </x-driver-check.shell>
</div>

@endsection
