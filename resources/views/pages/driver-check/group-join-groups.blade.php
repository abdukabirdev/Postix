@extends('layouts.driver-check')

@php
    /* joined | queue | other (the rejected and the gone ones, a page of their own) */
    $joined = $view === 'joined';
    $other = $view === 'other';
    $title = match ($view) {
        'joined' => __('driver_check.menu.group_join_joined'),
        'other' => __('driver_check.join.groups.other_title'),
        default => __('driver_check.menu.group_join_queue'),
    };
    $description = match ($view) {
        'joined' => __('driver_check.join.groups.joined_text'),
        'other' => __('driver_check.join.groups.other_text'),
        default => __('driver_check.join.groups.queue_text'),
    };
@endphp

@section('title', $title)

@section('content')

<div
    x-data="dcGroupJoinGroups({
        view: @js($other ? 'queue' : $view),
        other: @js($other),
        endpoints: {
            groups: @js(route('api.telegram.group-join.groups')),
            people_page: @js(route('driver-check.group-join.people')),
        },
        translations: @js(__('driver_check.join')),
    })"
>
    <x-driver-check.shell>

        <x-driver-check.page-header
            :title="$title"
            :description="$description"
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

                <x-driver-check.button href="{{ route('driver-check.group-join') }}" variant="primary" icon="pulse">
                    {{ __('driver_check.menu.group_join') }}
                </x-driver-check.button>
            </x-slot:actions>
        </x-driver-check.page-header>

        <x-driver-check.surface class="overflow-hidden">
            <div class="flex flex-col gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
                        {{ $title }}
                        <span x-show="meta" class="ml-1 font-normal tabular-nums text-gray-400" x-text="number(meta?.total ?? 0)"></span>
                    </h2>

                </div>

                {{-- Filters --}}
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    <x-driver-check.input x-model="filters.search" x-on:input.debounce.400ms="load()" :placeholder="__('driver_check.join.groups.search')" />

                    @if ($joined)
                        <x-driver-check.select x-model="filters.type" x-on:change="load()">
                            <option value="">{{ __('driver_check.join.groups.type_all') }}</option>
                            <option value="database">{{ __('driver_check.join.types.database') }}</option>
                            <option value="contacts">{{ __('driver_check.join.types.contacts') }}</option>
                        </x-driver-check.select>
                    @else
                        <x-driver-check.select x-model="filters.status" x-on:change="load()">
                            <option value="">{{ __('driver_check.join.groups.status_all') }}</option>
                            <template x-if="view === 'queue'">
                                <optgroup>
                                    <option value="approved">{{ __('driver_check.join.statuses.approved') }}</option>
                                    <option value="new">{{ __('driver_check.join.statuses.new') }}</option>
                                </optgroup>
                            </template>
                            <template x-if="view === 'other'">
                                <optgroup>
                                    <option value="rejected">{{ __('driver_check.join.statuses.rejected') }}</option>
                                    <option value="dead">{{ __('driver_check.join.statuses.dead') }}</option>
                                </optgroup>
                            </template>
                        </x-driver-check.select>
                    @endif

                    <x-driver-check.select x-model="filters.source" x-on:change="load()">
                        <option value="">{{ __('driver_check.join.groups.source_all') }}</option>
                        @foreach (['search', 'link', 'forward'] as $source)
                            <option value="{{ $source }}">{{ __('driver_check.join.sources.' . $source) }}</option>
                        @endforeach
                    </x-driver-check.select>

                    <x-driver-check.select x-model="filters.sort" x-on:change="load()">
                        <option value="">{{ __('driver_check.join.groups.sort.default') }}</option>
                        @foreach (['score', 'members', 'people', 'last_message', 'found'] as $sortKey)
                            <option value="{{ $sortKey }}">{{ __('driver_check.join.groups.sort.' . $sortKey) }}</option>
                        @endforeach
                    </x-driver-check.select>
                </div>
            </div>

            <div x-show="loading && groups.length === 0" class="space-y-2 p-4">
                <template x-for="i in 5" :key="'sk-' + i">
                    <div class="dc-skeleton h-16 rounded-xl bg-gray-100 dark:bg-white/[0.06]"></div>
                </template>
            </div>

            <p x-show="!loading && groups.length === 0" x-cloak class="px-4 py-12 text-center text-[13px] text-gray-500 dark:text-gray-400">
                {{ $joined ? __('driver_check.join.groups.empty_joined') : ($other ? __('driver_check.join.groups.empty_other') : __('driver_check.join.groups.empty_queue')) }}
            </p>

            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                <template x-for="group in groups" :key="group.id">
                    <li class="flex flex-col gap-2 px-4 py-3 hover:bg-gray-25 lg:flex-row lg:items-center lg:gap-4 dark:hover:bg-white/[0.025]">
                        {{-- Name, where it came from --}}
                        <div class="min-w-0 lg:w-[30%]">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <x-driver-check.badge ::class="statusTone(group)"><span x-text="statusText(group)"></span></x-driver-check.badge>
                                <span
                                    class="rounded-md px-1.5 py-0.5 text-[10.5px] font-semibold"
                                    :class="group.type === 'contacts' ? 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400' : 'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300'"
                                    x-text="t.types[group.type]"
                                ></span>
                                <span x-show="group.decided_by === 'manual'" x-cloak class="text-[10.5px] font-semibold uppercase tracking-wide text-gray-400">{{ __('driver_check.join.groups.manual') }}</span>
                            </div>
                            <p class="mt-1 truncate text-sm font-medium text-gray-900 dark:text-white" x-text="groupName(group)"></p>
                            <p class="truncate text-[12px] text-gray-500 dark:text-gray-400">
                                <a :href="groupUrl(group)" target="_blank" rel="noopener" class="hover:underline" x-text="'t.me/' + group.link"></a>
                            </p>
                            <p class="truncate text-[11.5px] text-gray-400" x-text="sourceText(group)"></p>
                            <p x-show="reasonText(group)" x-cloak class="mt-0.5 text-[12px] text-warning-700 dark:text-warning-400" x-text="reasonText(group)"></p>
                            <p x-show="group.last_error" x-cloak class="dc-break mt-0.5 text-[12px] text-error-600 dark:text-error-400" x-text="group.last_error"></p>
                        </div>

                        {{-- What the check read --}}
                        <dl class="grid flex-1 grid-cols-3 gap-x-4 gap-y-1 text-[12px] sm:grid-cols-6">
                            <div>
                                <dt class="text-gray-400">{{ __('driver_check.join.groups.members') }}</dt>
                                <dd class="font-medium tabular-nums text-gray-900 dark:text-white" x-text="number(group.members_count)"></dd>
                            </div>
                            <div>
                                <dt class="text-gray-400">{{ __('driver_check.join.groups.per_day') }}</dt>
                                <dd class="font-medium tabular-nums text-gray-900 dark:text-white" x-text="group.checked_at ? group.per_day : dash"></dd>
                            </div>
                            <div>
                                <dt class="text-gray-400">{{ __('driver_check.join.groups.senders') }}</dt>
                                <dd class="font-medium tabular-nums text-gray-900 dark:text-white" x-text="group.checked_at ? group.senders_week : dash"></dd>
                            </div>
                            <div>
                                <dt class="text-gray-400">{{ __('driver_check.join.groups.topic') }}</dt>
                                <dd class="font-medium tabular-nums text-gray-900 dark:text-white" x-text="group.checked_at ? group.topic_hits : dash"></dd>
                            </div>
                            <div>
                                <dt class="text-gray-400">{{ __('driver_check.join.groups.last_message') }}</dt>
                                <dd class="font-medium text-gray-900 dark:text-white" x-text="group.last_message_at ? relative(group.last_message_at) : dash" :title="group.last_message_at ? date(group.last_message_at) : ''"></dd>
                            </div>
                            <div>
                                <dt class="text-gray-400">{{ __('driver_check.join.groups.people') }}</dt>
                                <dd class="font-medium tabular-nums">
                                    <a
                                        x-show="group.last_synced_at"
                                        :href="peopleUrl(group)"
                                        class="text-brand-600 hover:underline dark:text-brand-400"
                                        :title="group.last_synced_at ? date(group.last_synced_at) : ''"
                                        x-text="number(group.members_read)"
                                    ></a>
                                    <span x-show="!group.last_synced_at" class="text-gray-900 dark:text-white" x-text="dash"></span>
                                </dd>
                            </div>
                        </dl>

                        {{-- Accounts in it, actions --}}
                        <div class="flex shrink-0 flex-wrap items-center gap-1.5 lg:max-w-[300px] lg:justify-end">
                            <span x-show="accountsIn(group).length" x-cloak class="mr-1 text-[11.5px] tabular-nums text-gray-500 dark:text-gray-400" :title="t.groups.accounts_in" x-text="accountsIn(group).join(', ')"></span>

                            @if ($joined)
                                <x-driver-check.button size="sm" x-show="group.type === 'database'" x-on:click="act(group, 'contacts')" ::disabled="busy === group.id" :title="__('driver_check.join.groups.to_contacts_hint')">
                                    {{ __('driver_check.join.groups.to_contacts') }}
                                </x-driver-check.button>
                                <x-driver-check.button size="sm" variant="ghost" x-show="group.type === 'contacts'" x-cloak x-on:click="act(group, 'database')" ::disabled="busy === group.id">
                                    {{ __('driver_check.join.groups.to_database') }}
                                </x-driver-check.button>
                            @else
                                <x-driver-check.button size="sm" x-show="group.join_status !== 'approved'" x-on:click="act(group, 'approve')" ::disabled="busy === group.id">
                                    {{ __('driver_check.join.groups.approve') }}
                                </x-driver-check.button>
                                <x-driver-check.button size="sm" variant="ghost" x-show="group.join_status !== 'rejected' && group.join_status !== 'dead'" x-on:click="act(group, 'reject')" ::disabled="busy === group.id">
                                    {{ __('driver_check.join.groups.reject') }}
                                </x-driver-check.button>
                                <x-driver-check.button size="sm" variant="ghost" x-show="group.decided_by === 'manual'" x-cloak x-on:click="act(group, 'auto')" ::disabled="busy === group.id" :title="__('driver_check.join.groups.auto_hint')">
                                    {{ __('driver_check.join.groups.auto') }}
                                </x-driver-check.button>
                            @endif
                        </div>
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
