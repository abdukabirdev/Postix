@extends('layouts.driver-check')

@section('title', __('driver_check.tree.pages.bot_members'))

@section('content')

<div
    x-data="dcGroupInvitesMembers({
        endpoints: {
            show: @js(route('api.telegram.group-invites.bot.members')),
        },
        translations: @js(__('driver_check.invites')),
    })"
>
    <x-driver-check.shell>

        <x-driver-check.page-header
            :title="__('driver_check.tree.pages.bot_members')"
            :description="__('driver_check.invites.members.description', ['days' => \App\Application\Telegram\Services\MemberEvents::KEEP_DAYS])"
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

        <x-driver-check.error-alert :title="__('driver_check.invites.load_failed')" retry="load()" />

        {{-- The bot writes the events: off or silent, nothing comes --}}
        <div
            x-show="loaded && botWarning()"
            x-cloak
            class="rounded-2xl border border-warning-200 bg-warning-50/60 px-4 py-3 text-[13px] text-warning-700 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-400"
            x-text="botWarning()"
        ></div>

        {{-- The period, the kind of event, whose, which group --}}
        <div class="flex flex-col gap-2 lg:flex-row lg:flex-wrap lg:items-center lg:justify-between">
            <div class="flex flex-wrap items-center gap-2">
                <x-driver-check.chips items="periods()" selected="days" action="setDays" />
                <x-driver-check.chips items="filters()" selected="event" action="setEvent" />
                <x-driver-check.chips items="whoItems()" selected="who" action="setWho" />
            </div>

            <div class="flex items-center gap-3">
                <span x-show="sinceLine()" x-cloak class="hidden text-[11.5px] text-gray-400 sm:inline" x-text="sinceLine()"></span>
                <div class="w-full sm:w-64">
                    <x-driver-check.select x-model="group" x-on:change="load()" aria-label="{{ __('driver_check.invites.members.all_groups') }}">
                        <option value="">{{ __('driver_check.invites.members.all_groups') }}</option>
                        <template x-for="g in (data?.groups ?? [])" :key="g.id">
                            <option :value="String(g.id)" x-text="g.title + (g.is_active ? ' · ' + m.current : '')"></option>
                        </template>
                    </x-driver-check.select>
                </div>
            </div>
        </div>

        <div x-show="!loaded && loading" class="dc-skeleton h-40 rounded-2xl bg-gray-100 dark:bg-white/[0.06]"></div>

        <template x-if="loaded">
            <div class="flex flex-col gap-5">

                {{-- The period in numbers --}}
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
                    <template x-for="tile in tiles()" :key="tile.key">
                        <x-driver-check.surface class="px-4 py-3">
                            <p class="text-[11px] uppercase tracking-wide text-gray-400" x-text="tile.label"></p>
                            <p class="mt-1 text-[22px] font-semibold tabular-nums leading-none" :class="tile.tone" x-text="number(tile.value)"></p>
                            <p x-show="tile.hint" x-cloak class="mt-1.5 text-[11.5px] text-gray-500" x-text="tile.hint"></p>
                        </x-driver-check.surface>
                    </template>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    {{-- Who takes people out --}}
                    <x-driver-check.surface class="overflow-hidden">
                        <div class="border-b border-gray-100 p-4 dark:border-gray-800">
                            <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.members.removers_title') }}</h2>
                            <p class="mt-0.5 text-[12px] text-gray-400">{{ __('driver_check.invites.members.removers_hint') }}</p>
                        </div>
                        <p x-show="data.removers.length === 0" x-cloak class="px-4 py-8 text-center text-[13px] text-gray-500">{{ __('driver_check.invites.members.removers_empty') }}</p>
                        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                            <template x-for="(row, i) in data.removers" :key="i">
                                <li class="flex items-center justify-between gap-3 px-4 py-2.5">
                                    <div class="min-w-0">
                                        <p class="truncate text-[13px] font-medium text-gray-950 dark:text-white" x-text="actorName(row)"></p>
                                        <p class="truncate text-[11.5px] tabular-nums text-gray-400" x-text="actorLine(row)"></p>
                                    </div>
                                    <p class="shrink-0 text-right tabular-nums">
                                        <span class="text-[15px] font-semibold text-error-600 dark:text-error-400" x-text="number(row.n)"></span>
                                        <span x-show="row.ours > 0" x-cloak class="block text-[11px] text-gray-400" x-text="m.removers_ours.replace(':n', number(row.ours))"></span>
                                    </p>
                                </li>
                            </template>
                        </ul>
                    </x-driver-check.surface>

                    {{-- Whose added people are gone --}}
                    <x-driver-check.surface class="overflow-hidden">
                        <div class="border-b border-gray-100 p-4 dark:border-gray-800">
                            <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.members.inviters_title') }}</h2>
                            <p class="mt-0.5 text-[12px] text-gray-400">{{ __('driver_check.invites.members.inviters_hint') }}</p>
                        </div>
                        <p x-show="data.by_inviter.length === 0" x-cloak class="px-4 py-8 text-center text-[13px] text-gray-500">{{ __('driver_check.invites.members.inviters_empty') }}</p>
                        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                            <template x-for="row in data.by_inviter" :key="row.account_id">
                                <li class="flex items-center justify-between gap-3 px-4 py-2.5">
                                    <p class="min-w-0 truncate text-[13px] font-medium tabular-nums text-gray-950 dark:text-white" x-text="'#' + row.account_id + (row.phone ? ' · ' + phone(row.phone) : '')"></p>
                                    <p class="shrink-0 text-[13px] tabular-nums">
                                        <span class="text-warning-600 dark:text-warning-400" x-text="number(row.left)"></span>
                                        <span class="text-gray-300 dark:text-gray-600"> / </span>
                                        <span class="text-error-600 dark:text-error-400" x-text="number(row.removed)"></span>
                                    </p>
                                </li>
                            </template>
                        </ul>
                    </x-driver-check.surface>
                </div>

                {{-- The events, newest first --}}
                <x-driver-check.surface class="overflow-hidden">
                    <div class="flex flex-col gap-1 border-b border-gray-100 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
                        <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.members.events_title') }}</h2>
                        <p class="text-[12px] text-gray-400" x-text="m.events_hint.replace(':n', number(data.list_size))"></p>
                    </div>

                    <p x-show="data.events.length === 0" x-cloak class="px-4 py-12 text-center text-[13px] text-gray-500">{{ __('driver_check.invites.members.empty') }}</p>

                    <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                        <template x-for="row in data.events" :key="row.id">
                            <li class="grid grid-cols-[52px_12px_minmax(0,1fr)] items-start gap-x-3 px-4 py-2.5 sm:grid-cols-[52px_12px_minmax(0,1fr)_minmax(0,220px)]">
                                <span class="mt-0.5 text-[12px] tabular-nums text-gray-400" x-text="time(row.at)"></span>
                                <span class="dc-dot mt-1.5" :class="eventDot(row)"></span>
                                <div class="min-w-0">
                                    <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[13px] text-gray-950 dark:text-white">
                                        <span class="font-medium" x-text="who(row)"></span>
                                        <span x-show="whoLine(row)" class="text-[11.5px] tabular-nums text-gray-400" x-text="whoLine(row)"></span>
                                        <template x-for="tag in tags(row)" :key="tag.key">
                                            <span class="rounded-md px-1.5 py-0.5 text-[10.5px] font-semibold" :class="tag.tone" x-text="tag.label"></span>
                                        </template>
                                    </p>
                                    <p class="mt-0.5 text-[12.5px]" :class="eventTone(row)">
                                        <span x-text="eventLabel(row)"></span>
                                        <span x-show="byLine(row)" x-cloak class="text-gray-500" x-text="' · ' + byLine(row)"></span>
                                    </p>
                                </div>
                                <p class="col-start-3 truncate text-[12px] text-gray-500 sm:col-start-auto sm:text-right" x-text="row.group"></p>
                            </li>
                        </template>
                    </ul>
                </x-driver-check.surface>
            </div>
        </template>
    </x-driver-check.shell>
</div>

@endsection
