@extends('layouts.driver-check')

@section('title', __('driver_check.menu.group_invites_groups'))

@section('content')

<div
    x-data="dcGroupInvitesGroups({
        endpoints: {
            index: @js(route('api.telegram.group-invites.groups')),
        },
        links: {
            people: @js(route('driver-check.group-invites.people')),
        },
        translations: @js(__('driver_check.invites')),
    })"
>
    <x-driver-check.shell>

        <x-driver-check.page-header
            :title="__('driver_check.menu.group_invites_groups')"
            :description="__('driver_check.invites.groups.description')"
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

        @include('pages.driver-check.partials.invites-nav')

        <x-driver-check.error-alert :title="__('driver_check.invites.load_failed')" retry="load()" />

        {{-- Add a group --}}
        <x-driver-check.surface class="p-4">
            <form class="flex flex-col gap-2 sm:flex-row" x-on:submit.prevent="add()">
                <x-driver-check.input class="min-w-0 flex-1" x-model="link" placeholder="{{ __('driver_check.invites.groups.placeholder') }}" />
                <x-driver-check.button type="submit" variant="primary" icon="plus" ::disabled="busy || link.trim() === ''">
                    {{ __('driver_check.invites.groups.add') }}
                </x-driver-check.button>
            </form>
            <p class="mt-2 text-[12px] leading-snug text-gray-400">{{ __('driver_check.invites.groups.add_hint') }}</p>
        </x-driver-check.surface>

        <div x-show="!loaded && loading" class="space-y-3">
            <template x-for="i in 2" :key="'sk-' + i">
                <div class="dc-skeleton h-40 rounded-2xl bg-gray-100 dark:bg-white/[0.06]"></div>
            </template>
        </div>

        <x-driver-check.empty-state
            icon="send"
            :title="__('driver_check.invites.groups.empty_title')"
            :description="__('driver_check.invites.groups.empty')"
            show="loaded && live().length === 0"
        />

        <p x-show="loaded && live().length > 1" x-cloak class="-mt-2 text-[12.5px] text-gray-500">{{ __('driver_check.invites.groups.one_at_a_time') }}</p>

        {{-- One card per group, the current one first --}}
        <template x-for="group in live()" :key="group.id">
            <x-driver-check.surface class="overflow-hidden" x-bind:class="isCurrent(group) && '!border-gray-900 dark:!border-white'">
                <div class="flex flex-col gap-3 border-b border-gray-100 px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
                    <div class="min-w-0">
                        <p class="mb-1 inline-flex items-center gap-1.5 rounded-md px-1.5 py-0.5 text-[11px] font-semibold"
                           :class="isCurrent(group) ? 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' : 'bg-gray-100 text-gray-500 dark:bg-white/[0.06] dark:text-gray-400'">
                            <span class="dc-dot" :class="isCurrent(group) ? 'text-success-500' : 'text-gray-400'"></span>
                            <span x-text="isCurrent(group) ? t.groups.current : t.groups.waiting"></span>
                        </p>
                        <p class="truncate text-[15px] font-semibold text-gray-950 dark:text-white" x-text="group.title || group.link"></p>
                        <p class="truncate text-[12px] text-gray-500" x-text="group.link"></p>
                        <p x-show="group.last_error" x-cloak class="mt-1 text-[12px] text-error-600 dark:text-error-400" x-text="errorLabel(group.last_error, true)" :title="group.last_error"></p>
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <x-driver-check.button size="sm" variant="primary" icon="play" x-show="!isCurrent(group)" x-on:click="makeCurrent(group)" ::disabled="busy">
                            {{ __('driver_check.invites.groups.make_current') }}
                        </x-driver-check.button>
                        <x-driver-check.button size="sm" icon="stop" x-show="isCurrent(group)" x-cloak x-on:click="switchOff(group)" ::disabled="busy">
                            {{ __('driver_check.invites.groups.turn_off') }}
                        </x-driver-check.button>
                        <x-driver-check.button size="sm" href="#" x-bind:href="peopleLink(group)" icon="users">
                            {{ __('driver_check.invites.groups.people') }}
                        </x-driver-check.button>
                        <x-driver-check.button size="sm" x-show="group.not_added + group.failed > 0" x-cloak x-on:click="retry(group)" ::disabled="busy" icon="refresh">
                            {{ __('driver_check.invites.groups.retry') }}
                        </x-driver-check.button>
                        <x-driver-check.row-action
                            icon="trash"
                            :label="__('driver_check.invites.groups.archive')"
                            class="h-8 w-8 hover:!text-error-600"
                            x-on:click="archive(group)"
                            ::disabled="busy"
                        />
                    </div>
                </div>

                {{-- The numbers: each opens its people --}}
                <div class="grid grid-cols-3 divide-x divide-y divide-gray-100 sm:grid-cols-6 sm:divide-y-0 dark:divide-gray-800">
                    <template x-for="cell in cells(group)" :key="cell.key">
                        <a :href="peopleLink(group, cell.status)" class="block px-4 py-3 transition hover:bg-gray-25 dark:hover:bg-white/[0.02]">
                            <span class="block text-[11px] font-medium uppercase tracking-wide text-gray-400" x-text="t.groups.cells[cell.key]"></span>
                            <span class="mt-1 block text-[20px] font-semibold leading-none tabular-nums text-gray-950 dark:text-white" x-text="number(cell.value)"></span>
                            <span x-show="cell.key === 'left' && group.added > 0" x-cloak class="mt-1 block text-[11px] tabular-nums text-gray-400" x-text="t.groups.left_share.replace(':n', percent(group.left, group.added)).replace(':still', number(stillIn(group)))"></span>
                        </a>
                    </template>
                </div>

                <div class="flex flex-col gap-1 border-t border-gray-100 bg-gray-25 px-4 py-2.5 text-[12px] text-gray-500 sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-5 dark:border-gray-800 dark:bg-white/[0.015]">
                    <span x-show="group.queue" x-cloak class="font-medium text-gray-700 dark:text-gray-200" x-text="queueLine(group)"></span>
                    <span x-show="group.members_before !== null || group.members_after !== null" x-cloak :title="t.groups.members_hint">
                        <span x-text="membersLine(group)"></span>
                        <span
                            x-show="membersGrew(group) !== null"
                            class="ml-1 font-semibold"
                            :class="membersGrew(group) > 0 ? 'text-success-600 dark:text-success-400' : 'text-gray-400'"
                            x-text="'(' + (membersGrew(group) > 0 ? '+' : '') + number(membersGrew(group)) + ')'"
                        ></span>
                    </span>
                    <span x-show="group.last_added_at" x-cloak x-text="t.groups.last_added.replace(':time', time(group.last_added_at))"></span>
                    <span x-text="group.left_checked_at ? t.groups.left_checked.replace(':time', time(group.left_checked_at)) : t.groups.left_never"></span>
                </div>
            </x-driver-check.surface>
        </template>

        {{-- The archive: folded away, history intact --}}
        <section x-show="archived().length > 0" x-cloak>
            <button type="button" class="flex items-center gap-2 text-[13px] font-semibold text-gray-600 hover:text-gray-950 dark:text-gray-300 dark:hover:text-white" x-on:click="showArchive = !showArchive">
                <x-driver-check.icon name="chevron-right" class="h-4 w-4 transition" ::class="showArchive && 'rotate-90'" />
                <span x-text="t.groups.archive_title.replace(':n', archived().length)"></span>
            </button>

            <x-driver-check.surface x-show="showArchive" x-cloak class="mt-3 overflow-hidden">
                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    <template x-for="group in archived()" :key="group.id">
                        <li class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <p class="truncate text-[13.5px] font-semibold text-gray-700 dark:text-gray-200" x-text="group.title || group.link"></p>
                                <p class="text-[12px] tabular-nums text-gray-400" x-text="t.groups.archived_line
                                    .replace(':time', day(group.archived_at))
                                    .replace(':added', number(group.added))
                                    .replace(':left', number(group.left))
                                    .replace(':failed', number(group.failed))"></p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <x-driver-check.button size="sm" href="#" x-bind:href="peopleLink(group)" icon="users">
                                    {{ __('driver_check.invites.groups.people') }}
                                </x-driver-check.button>
                                <x-driver-check.button size="sm" x-on:click="restore(group)" ::disabled="busy" icon="arrow-up">
                                    {{ __('driver_check.invites.groups.restore') }}
                                </x-driver-check.button>
                            </div>
                        </li>
                    </template>
                </ul>
            </x-driver-check.surface>
        </section>
    </x-driver-check.shell>
</div>

@endsection
