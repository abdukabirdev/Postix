@extends('layouts.driver-check')

@section('title', __('driver_check.tree.pages.invites_drivers'))

{{--
    Drivers into a group: the drivers found in Telegram by the number go
    into one of our groups, added by the account that found them.
--}}

@section('content')

<div
    x-data="dcGroupInvitesDrivers({
        endpoints: {
            show: @js(route('api.telegram.group-invites.drivers')),
            rows: @js(route('api.telegram.group-invites.drivers.rows')),
            settings: @js(route('api.telegram.group-invites.drivers.settings')),
            all: @js(route('api.telegram.group-invites.drivers.all')),
            run: @js(route('api.telegram.group-invites.drivers.run')),
            retry: @js(route('api.telegram.group-invites.drivers.retry')),
            stop: @js(route('api.telegram.group-invites.drivers.stop')),
        },
        translations: @js(__('driver_check.invites')),
        drivers: @js(__('driver_check.drivers_group')),
    })"
>
    <x-driver-check.shell>

        <x-driver-check.page-header
            :title="__('driver_check.tree.pages.invites_drivers')"
            :description="__('driver_check.drivers_group.description')"
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

                <x-driver-check.button variant="primary" icon="users" x-on:click="addAll()" ::disabled="busy || !data?.group || (data?.counts?.not_queued ?? 0) === 0">
                    <span x-text="d.add_all + ((data?.counts?.not_queued ?? 0) > 0 ? ' · ' + number(data.counts.not_queued) : '')"></span>
                </x-driver-check.button>

                <x-driver-check.button variant="danger" icon="stop" x-show="running" x-cloak x-on:click="stop()" ::disabled="busy || now?.stop_asked">
                    <span x-text="now?.stop_asked ? d.stopping : d.stop"></span>
                </x-driver-check.button>
            </x-slot:actions>
        </x-driver-check.page-header>

        <x-driver-check.error-alert :title="__('driver_check.drivers_group.load_failed')" retry="load()" />

        <div x-show="!data && loading" class="grid gap-3 xl:grid-cols-2">
            <div class="dc-skeleton h-52 rounded-2xl bg-gray-100 dark:bg-white/[0.06]"></div>
            <div class="dc-skeleton h-52 rounded-2xl bg-gray-100 dark:bg-white/[0.06]"></div>
        </div>

        <template x-if="data">
            <div class="flex flex-col gap-5">

                <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">

                    {{-- What runs now, or how the last run ended --}}
                    <x-driver-check.surface class="flex h-full flex-col overflow-hidden">
                        <div class="flex-1 p-4">
                            <div class="flex items-center gap-2">
                                <span class="dc-dot" :class="running ? 'animate-pulse text-success-500' : 'text-gray-300 dark:text-gray-600'"></span>
                                <p class="dc-eyebrow" x-text="running ? d.now.title_running : d.now.title_idle"></p>
                            </div>

                            <template x-if="running">
                                <div class="mt-2">
                                    <p class="text-lg font-semibold tracking-[-0.01em] text-gray-950 dark:text-white" x-text="doingLabel()"></p>
                                    <p class="mt-0.5 text-[13px] text-gray-500" x-text="whereLabel()"></p>
                                    <p x-show="now?.stop_asked" x-cloak class="mt-2 text-[12.5px] font-medium text-warning-600 dark:text-warning-400" x-text="d.stopping"></p>
                                    <p class="mt-2 text-[13px] tabular-nums text-gray-700 dark:text-gray-300">
                                        <span class="text-gray-400" x-text="d.now.so_far + ': '"></span>
                                        <span x-text="summary(now?.totals)"></span>
                                    </p>
                                </div>
                            </template>

                            <template x-if="!running">
                                <div class="mt-2">
                                    <p class="text-[15px] font-semibold text-gray-950 dark:text-white" x-text="lastRunLabel()"></p>
                                    <p x-show="now?.totals" x-cloak class="mt-1 text-[13px] tabular-nums text-gray-600 dark:text-gray-300" x-text="summary(now?.totals)"></p>
                                </div>
                            </template>

                            <p x-show="groupError()" x-cloak class="mt-2 text-[12.5px] font-medium text-error-600 dark:text-error-400" x-text="groupError()"></p>
                            <p class="mt-3 text-[12px] leading-snug text-gray-400" x-text="d.now.schedule"></p>
                            <p class="mt-1 text-[12px] tabular-nums text-gray-500" x-text="d.counts.today.replace(':tried', number(data.today.tried)).replace(':added', number(data.today.added))"></p>
                        </div>

                        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-gray-100 px-4 py-3 dark:border-gray-800">
                            <x-driver-check.button size="sm" icon="refresh" x-on:click="retry()" ::disabled="busy || !data.group || counts().retryable === 0">
                                <span x-text="d.retry + (counts().retryable > 0 ? ' · ' + number(counts().retryable) : '')"></span>
                            </x-driver-check.button>
                            <x-driver-check.button size="sm" icon="play" x-show="!running" x-on:click="run()" ::disabled="busy || !data.group || counts().pending === 0" :title="__('driver_check.drivers_group.run_hint')">
                                <span x-text="d.run + (counts().pending > 0 ? ' · ' + number(counts().pending) : '')"></span>
                            </x-driver-check.button>
                        </div>
                    </x-driver-check.surface>

                    {{-- The group and the rules --}}
                    <x-driver-check.surface class="overflow-hidden">
                        <form x-on:submit.prevent="save()">
                            <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.drivers_group.settings.title') }}</h2>
                                <a href="{{ route('driver-check.group-invites.groups') }}" class="shrink-0 text-[13px] font-medium text-gray-600 hover:text-gray-950 hover:underline dark:text-gray-300 dark:hover:text-white">
                                    {{ __('driver_check.drivers_group.settings.open_groups') }} →
                                </a>
                            </div>

                            <div class="grid gap-4 p-4 sm:grid-cols-2">
                                <div class="sm:col-span-2">
                                    <x-driver-check.field :label="__('driver_check.drivers_group.settings.group')" :hint="__('driver_check.drivers_group.settings.group_hint')">
                                        <x-driver-check.select x-model="form.group_id">
                                            <option value="">{{ __('driver_check.drivers_group.settings.no_group') }}</option>
                                            <template x-for="g in data.groups" :key="g.id">
                                                <option :value="String(g.id)" x-text="groupOption(g)" :selected="String(g.id) === form.group_id"></option>
                                            </template>
                                        </x-driver-check.select>
                                    </x-driver-check.field>
                                    <p x-show="data.groups.length === 0" x-cloak class="mt-1.5 text-[12px] text-warning-600 dark:text-warning-400">{{ __('driver_check.drivers_group.settings.no_groups') }}</p>
                                </div>

                                <label class="flex cursor-pointer items-start gap-3 sm:col-span-2">
                                    <span class="relative mt-0.5 inline-flex h-6 w-10 shrink-0 items-center">
                                        <input type="checkbox" x-model="form.on_resolve" class="peer sr-only">
                                        <span class="absolute inset-0 rounded-full bg-gray-300 transition peer-checked:bg-brand-500 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/50 dark:bg-gray-700"></span>
                                        <span class="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-4"></span>
                                    </span>
                                    <span>
                                        <span class="block text-[13px] font-semibold text-gray-900 dark:text-white">{{ __('driver_check.drivers_group.settings.on_resolve') }}</span>
                                        <span class="block text-[11.5px] leading-snug text-gray-400">{{ __('driver_check.drivers_group.settings.on_resolve_hint') }}</span>
                                        <span x-show="sinceLabel()" x-cloak class="mt-0.5 block text-[11.5px] text-gray-500" x-text="sinceLabel()"></span>
                                    </span>
                                </label>

                                <div class="sm:col-span-2">
                                    <x-driver-check.field :label="__('driver_check.drivers_group.settings.per_account_day')" :hint="__('driver_check.drivers_group.settings.per_account_day_hint')">
                                        <x-driver-check.input type="number" inputmode="numeric" min="1" max="200" x-model="form.per_account_day" required />
                                    </x-driver-check.field>
                                </div>

                                <x-driver-check.field :label="__('driver_check.drivers_group.settings.delay_min')">
                                    <x-driver-check.input type="number" inputmode="numeric" min="0" max="600" x-model="form.delay_min" required />
                                </x-driver-check.field>

                                <x-driver-check.field :label="__('driver_check.drivers_group.settings.delay_max')">
                                    <x-driver-check.input type="number" inputmode="numeric" ::min="form.delay_min" max="600" x-model="form.delay_max" required />
                                </x-driver-check.field>
                            </div>

                            <div class="flex items-center justify-end gap-2 border-t border-gray-100 px-4 py-3 dark:border-gray-800">
                                <x-driver-check.button type="submit" size="sm" variant="primary" ::disabled="saving || !dirty()">
                                    {{ __('driver_check.drivers_group.settings.save') }}
                                </x-driver-check.button>
                            </div>
                        </form>
                    </x-driver-check.surface>
                </div>

                <p x-show="!data.group" x-cloak class="rounded-2xl border border-dashed border-gray-300 px-4 py-6 text-center text-[13px] text-gray-500 dark:border-gray-700">
                    {{ __('driver_check.drivers_group.no_group') }}
                </p>

                <template x-if="data.group">
                    <div class="flex flex-col gap-5">

                        {{-- The drivers in numbers --}}
                        <x-driver-check.surface class="overflow-hidden">
                            <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">
                                    <span>{{ __('driver_check.drivers_group.counts.title') }}</span>
                                    <span class="font-normal text-gray-400" x-text="' · ' + data.group.title"></span>
                                </h2>
                                <p class="mt-0.5 text-[12px] text-gray-400">{{ __('driver_check.drivers_group.counts.hint') }}</p>
                            </div>

                            <div class="p-4">
                                <div class="flex flex-wrap items-end gap-x-6 gap-y-2">
                                    <div>
                                        <p class="dc-eyebrow">{{ __('driver_check.drivers_group.counts.lead') }}</p>
                                        <p class="mt-1.5 text-[32px] font-semibold leading-none tracking-[-0.02em] tabular-nums text-gray-950 dark:text-white">
                                            <span x-text="number(counts().invited + counts().already)"></span>
                                            <span class="text-[17px] font-medium text-gray-400" x-text="' / ' + number(counts().found)"></span>
                                        </p>
                                    </div>
                                    <p class="pb-0.5 text-[13px] tabular-nums text-gray-500" x-text="d.counts.of_found.replace(':n', percent(counts().invited + counts().already, counts().found))"></p>
                                </div>

                                <div
                                    class="mt-4 flex h-3 w-full gap-[2px] overflow-hidden rounded-full"
                                    :class="counts().found === 0 && 'bg-gray-100 dark:bg-white/[0.06]'"
                                    role="img"
                                    :aria-label="parts().map((p) => partTitle(p)).join(', ')"
                                >
                                    <template x-for="part in parts()" :key="part.key">
                                        <button
                                            type="button"
                                            x-show="part.value > 0"
                                            x-on:click="showPart(part.key)"
                                            class="h-full first:rounded-l-full last:rounded-r-full transition-opacity hover:opacity-80"
                                            :class="partFill(part.key)"
                                            :style="`width: ${part.share}%; min-width: 4px`"
                                            :title="partTitle(part)"
                                        ></button>
                                    </template>
                                </div>

                                <ul class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
                                    <template x-for="part in parts()" :key="'legend-' + part.key">
                                        <li>
                                            <button
                                                type="button"
                                                x-on:click="showPart(part.key)"
                                                class="block w-full rounded-xl border border-gray-100 p-3 text-left transition hover:border-gray-300 dark:border-gray-800 dark:hover:border-gray-600"
                                                :class="filters.status === part.key && 'border-gray-400 dark:border-gray-500'"
                                            >
                                                <span class="flex items-center gap-2">
                                                    <span class="h-2.5 w-2.5 shrink-0 rounded-full ring-1 ring-inset ring-black/5" :class="partFill(part.key)"></span>
                                                    <span class="truncate text-[12.5px] font-medium text-gray-600 dark:text-gray-300" x-text="d.statuses[part.key]"></span>
                                                </span>
                                                <span class="mt-1.5 block text-[22px] font-semibold leading-none tabular-nums text-gray-950 dark:text-white" x-text="number(part.value)"></span>
                                                <span class="mt-1 block text-[11.5px] leading-snug text-gray-400" x-text="d.status_hints[part.key]"></span>
                                            </button>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                        </x-driver-check.surface>

                        <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">

                            {{-- Who adds --}}
                            <x-driver-check.surface class="overflow-hidden">
                                <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                                    <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.drivers_group.accounts.title') }}</h2>
                                    <p class="mt-0.5 text-[12px] text-gray-400">{{ __('driver_check.drivers_group.accounts.hint') }}</p>
                                </div>

                                <p x-show="data.accounts.length === 0" x-cloak class="px-4 py-8 text-center text-[13px] text-gray-500">{{ __('driver_check.drivers_group.accounts.empty') }}</p>

                                <div x-show="data.accounts.length > 0" class="overflow-x-auto">
                                    <table class="w-full min-w-[560px] text-[13px]">
                                        <thead>
                                            <tr class="border-b border-gray-100 text-left text-[11px] uppercase tracking-wide text-gray-400 dark:border-gray-800">
                                                <th class="px-4 py-2 font-medium">{{ __('driver_check.drivers_group.accounts.cols.account') }}</th>
                                                <th class="px-3 py-2 text-right font-medium">{{ __('driver_check.drivers_group.accounts.cols.waiting') }}</th>
                                                <th class="px-3 py-2 text-right font-medium">{{ __('driver_check.drivers_group.accounts.cols.today') }}</th>
                                                <th class="px-3 py-2 text-right font-medium">{{ __('driver_check.drivers_group.accounts.cols.added') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('driver_check.drivers_group.accounts.cols.state') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                            <template x-for="account in data.accounts" :key="account.id">
                                                <tr>
                                                    <td class="whitespace-nowrap px-4 py-2.5">
                                                        <span class="font-medium tabular-nums text-gray-950 dark:text-white" x-text="phone(account.phone) || '#' + account.id"></span>
                                                        <span class="ml-1 text-[11.5px] text-gray-400" x-text="'#' + account.id"></span>
                                                    </td>
                                                    <td class="px-3 py-2.5 text-right font-semibold tabular-nums text-gray-950 dark:text-white" x-text="number(account.waiting)"></td>
                                                    <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums text-gray-700 dark:text-gray-300" x-text="number(account.tried_today) + ' / ' + number(account.cap)"></td>
                                                    <td class="px-3 py-2.5 text-right tabular-nums text-gray-700 dark:text-gray-300" x-text="number(account.added_today)"></td>
                                                    <td class="px-4 py-2.5 text-[12.5px]" :class="stateTone(account)" x-text="stateText(account)"></td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </x-driver-check.surface>

                            {{-- The last run --}}
                            <x-driver-check.surface class="overflow-hidden">
                                <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                                    <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.drivers_group.log.title') }}</h2>
                                </div>

                                <p x-show="lines().length === 0" x-cloak class="px-4 py-8 text-center text-[13px] text-gray-500">{{ __('driver_check.drivers_group.log.empty') }}</p>

                                <ul class="max-h-[420px] divide-y divide-gray-100 overflow-y-auto dark:divide-gray-800">
                                    <template x-for="(line, i) in lines()" :key="i + line.at">
                                        <li class="flex items-start gap-3 px-4 py-2">
                                            <span class="mt-0.5 w-[62px] shrink-0 text-[12px] tabular-nums text-gray-400" x-text="clock(line.at)"></span>
                                            <span class="dc-dot mt-1.5 shrink-0" :class="lineTone(line)"></span>
                                            <p class="min-w-0 flex-1 text-[13px] text-gray-900 dark:text-gray-100" x-text="lineText(line)"></p>
                                        </li>
                                    </template>
                                </ul>
                            </x-driver-check.surface>
                        </div>

                        {{-- Every driver of the group --}}
                        <x-driver-check.surface class="overflow-hidden" x-ref="list">
                            <div class="flex flex-col gap-3 border-b border-gray-100 p-4 dark:border-gray-800">
                                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.drivers_group.list.title') }}</h2>
                                <x-driver-check.chips items="statusChips()" selected="filters.status" action="setStatus" />
                                <x-driver-check.input x-model="filters.q" x-on:input.debounce.450ms="loadRows()" :placeholder="__('driver_check.drivers_group.list.search')" />
                            </div>

                            <div x-show="listLoading && rows.length === 0" class="space-y-2 p-4">
                                <template x-for="i in 5" :key="'sk-' + i">
                                    <div class="dc-skeleton h-14 rounded-xl bg-gray-100 dark:bg-white/[0.06]"></div>
                                </template>
                            </div>

                            <p x-show="!listLoading && rows.length === 0" x-cloak class="px-4 py-14 text-center text-[13px] text-gray-500">{{ __('driver_check.drivers_group.list.empty') }}</p>

                            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                                <template x-for="row in rows" :key="row.id">
                                    <li class="grid gap-2 px-4 py-3 md:grid-cols-[minmax(0,2fr)_minmax(0,2fr)_minmax(0,2fr)] md:items-start md:gap-5">
                                        <div class="min-w-0">
                                            <p class="truncate text-[13.5px] font-medium text-gray-950 dark:text-white" x-text="row.driver_name || phone(row.phone)"></p>
                                            <p class="truncate text-[12px] tabular-nums text-gray-500" x-text="phone(row.phone)"></p>
                                        </div>

                                        <div class="min-w-0">
                                            <p class="truncate text-[12.5px] text-gray-700 dark:text-gray-200" x-text="telegramLine(row)"></p>
                                            <p class="truncate text-[12px] text-gray-500" x-text="whoLine(row)"></p>
                                        </div>

                                        <div class="min-w-0">
                                            <x-driver-check.badge x-bind:class="statusTone(row.status)">
                                                <span x-text="statusText(row.status)"></span>
                                            </x-driver-check.badge>
                                            <p x-show="row.error" x-cloak class="mt-1 text-[12px] leading-snug text-gray-500" x-text="errorText(row.error)"></p>
                                            <p class="mt-0.5 text-[11.5px] text-gray-400" x-text="whenLine(row)"></p>
                                        </div>
                                    </li>
                                </template>
                            </ul>

                            <div x-show="hasMore()" x-cloak class="border-t border-gray-100 px-4 py-3 text-center dark:border-gray-800">
                                <x-driver-check.button size="sm" x-on:click="loadRows(true)" ::disabled="listLoading">
                                    {{ __('driver_check.list.more') }}
                                </x-driver-check.button>
                            </div>
                        </x-driver-check.surface>
                    </div>
                </template>
            </div>
        </template>
    </x-driver-check.shell>
</div>

@endsection
