@extends('layouts.driver-check')

@section('title', __('driver_check.menu.group_invites_ranking'))

@section('content')

<div
    x-data="dcGroupInvitesRanking({
        endpoints: {
            show: @js(route('api.telegram.group-invites.ranking')),
        },
        translations: @js(__('driver_check.invites')),
    })"
>
    <x-driver-check.shell>

        <x-driver-check.page-header
            :title="__('driver_check.menu.group_invites_ranking')"
            :description="__('driver_check.invites.ranking.description')"
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

        <x-driver-check.chips items="periods()" selected="days" action="setPeriod" />

        <div x-show="!data && loading" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <template x-for="i in 8" :key="'sk-' + i">
                <div class="dc-skeleton h-24 rounded-2xl bg-gray-100 dark:bg-white/[0.06]"></div>
            </template>
        </div>

        <template x-if="data">
            <div class="flex flex-col gap-5">

                <p x-show="!leader()" class="rounded-2xl border border-dashed border-gray-200 px-4 py-10 text-center text-[13px] text-gray-500 dark:border-gray-800">
                    {{ __('driver_check.invites.ranking.leader.none') }}
                </p>

                {{-- The one that added the most --}}
                <template x-if="leader()">
                    <section class="flex flex-col gap-3">
                        <x-driver-check.surface class="flex flex-col gap-1 p-4 sm:flex-row sm:items-end sm:justify-between sm:gap-4">
                            <div class="min-w-0">
                                <p class="dc-eyebrow" x-text="leaderTitle()"></p>
                                <p class="mt-2 truncate text-[20px] font-semibold tabular-nums text-gray-950 dark:text-white" x-text="who(leader())"></p>
                                <p class="mt-0.5 truncate text-[12.5px] text-gray-500" x-text="leaderOrigin()"></p>
                            </div>
                            <p class="shrink-0 text-[12.5px] tabular-nums text-gray-500" x-text="nextLine()"></p>
                        </x-driver-check.surface>

                        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                            <x-driver-check.stat-card :label="__('driver_check.invites.ranking.leader.added')">
                                <span x-text="number(leader().added)"></span>
                                <x-slot:hint><span x-text="t.ranking.leader.place.replace(':n', ranked().length)"></span></x-slot:hint>
                            </x-driver-check.stat-card>
                            <x-driver-check.stat-card :label="__('driver_check.invites.ranking.leader.best_day')">
                                <span x-text="number(leader().best)"></span>
                                <x-slot:hint><span x-text="shortDay(leader().bestDay)"></span></x-slot:hint>
                            </x-driver-check.stat-card>
                            <x-driver-check.stat-card :label="__('driver_check.invites.ranking.leader.share')">
                                <span x-text="percent(leader().added, totalAdded()) + '%'"></span>
                                <x-slot:hint><span x-text="t.ranking.leader.share_hint.replace(':added', number(leader().added)).replace(':total', number(totalAdded()))"></span></x-slot:hint>
                            </x-driver-check.stat-card>
                            <x-driver-check.stat-card :label="__('driver_check.invites.ranking.leader.reach')">
                                <span x-text="reachText(leader().reach)"></span>
                                <x-slot:hint><span x-text="t.ranking.leader.reach_hint.replace(':n', number(leader().added + leader().not_added))"></span></x-slot:hint>
                            </x-driver-check.stat-card>
                        </div>
                    </section>
                </template>

                {{-- The picked account's days, and the first three day by day --}}
                <div x-show="leader()" class="grid gap-3 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
                    <x-driver-check.surface class="overflow-hidden">
                        <div class="flex items-start justify-between gap-3 px-4 pt-4">
                            <div class="min-w-0">
                                <h2 class="truncate text-sm font-semibold text-gray-950 dark:text-white" x-text="dailyTitle()"></h2>
                                <p class="mt-0.5 text-[12px] text-gray-400">{{ __('driver_check.invites.ranking.daily.hint') }}</p>
                            </div>
                            <p class="min-h-[2.5rem] shrink-0 text-right text-[12px] leading-snug tabular-nums text-gray-600 dark:text-gray-300">
                                <template x-if="hover">
                                    <span>
                                        <span class="block font-semibold text-gray-950 dark:text-white" x-text="day(hover.day)"></span>
                                        <span x-text="hoverLine(hover)"></span>
                                    </span>
                                </template>
                            </p>
                        </div>

                        <div class="relative px-4 pb-3 pt-4">
                            <div class="pointer-events-none absolute inset-x-4 top-4 flex items-center gap-2">
                                <span class="text-[10.5px] tabular-nums text-gray-400" x-text="number(dailyMax())"></span>
                                <span class="h-px flex-1 border-t border-gray-100 dark:border-gray-800"></span>
                            </div>

                            <div class="flex h-44 items-end gap-[2px] border-b border-gray-200 pt-5 dark:border-gray-700" x-on:mouseleave="hover = null">
                                <template x-for="point in daily()" :key="point.day">
                                    <div
                                        class="flex h-full min-w-0 flex-1 cursor-default items-end justify-center"
                                        x-on:mouseenter="hover = point"
                                        :aria-label="day(point.day) + ': ' + hoverLine(point)"
                                    >
                                        <div
                                            class="w-full max-w-6 rounded-t-[4px] transition-colors"
                                            :class="hover?.day === point.day ? 'bg-brand-600' : 'bg-brand-500'"
                                            :style="`height: ${barHeight(point)}%`"
                                        ></div>
                                    </div>
                                </template>
                            </div>

                            <div class="mt-1.5 flex gap-[2px]">
                                <template x-for="(point, index) in daily()" :key="'l-' + point.day">
                                    <span class="min-w-0 flex-1 overflow-visible whitespace-nowrap text-center text-[10.5px] tabular-nums text-gray-400" x-text="showsLabel(index) ? shortDay(point.day) : ''"></span>
                                </template>
                            </div>
                        </div>
                    </x-driver-check.surface>

                    <x-driver-check.surface class="flex flex-col overflow-hidden">
                        <h2 class="px-4 pb-3 pt-4 text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.ranking.top.title') }}</h2>
                        <div class="max-h-[19rem] overflow-auto">
                            <table class="w-full text-left text-[12.5px]">
                                <thead class="sticky top-0 border-b border-gray-100 text-[10.5px] uppercase tracking-wide text-gray-400 dark:border-gray-800" style="background: var(--dc-surface)">
                                    <tr>
                                        <th class="px-4 py-2 font-medium">{{ __('driver_check.invites.ranking.top.day') }}</th>
                                        <template x-for="account in top3()" :key="'h-' + account.id">
                                            <th class="px-4 py-2 text-right font-medium">
                                                <button type="button" class="hover:text-gray-900 dark:hover:text-white" :class="isSelected(account) && 'text-brand-600 dark:text-brand-400'" x-on:click="pick(account)" x-text="'#' + account.id"></button>
                                            </th>
                                        </template>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 tabular-nums dark:divide-gray-800">
                                    <template x-for="row in topRows()" :key="'d-' + row.day">
                                        <tr>
                                            <td class="px-4 py-1.5 text-gray-500" :class="row.today && 'font-semibold text-gray-950 dark:text-white'" x-text="shortDay(row.day)"></td>
                                            <template x-for="(value, index) in row.values" :key="row.day + '-' + index">
                                                <td class="px-4 py-1.5 text-right text-gray-950 dark:text-white" :class="row.today && 'font-semibold'" x-text="number(value)"></td>
                                            </template>
                                        </tr>
                                    </template>
                                </tbody>
                                <tfoot class="border-t border-gray-200 tabular-nums dark:border-gray-700">
                                    <tr>
                                        <td class="px-4 py-2 font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.ranking.top.total') }}</td>
                                        <template x-for="account in top3()" :key="'f-' + account.id">
                                            <td class="px-4 py-2 text-right font-semibold text-gray-950 dark:text-white" x-text="number(account.added)"></td>
                                        </template>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </x-driver-check.surface>
                </div>

                {{-- The ranking: everybody who added someone --}}
                <x-driver-check.surface x-show="leader()" class="overflow-hidden">
                    <div class="px-4 pt-4">
                        <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.ranking.board.title') }}</h2>
                        <p class="mt-0.5 text-[12px] text-gray-400">{{ __('driver_check.invites.ranking.board.hint') }}</p>
                    </div>

                    <ol class="flex flex-col gap-0.5 px-2 py-3">
                        <template x-for="(account, index) in board()" :key="'b-' + account.id">
                            <li>
                                <button
                                    type="button"
                                    class="grid w-full grid-cols-[2rem_minmax(0,9.5rem)_minmax(0,1fr)_3rem] items-center gap-3 rounded-lg px-2 py-1.5 text-left transition hover:bg-gray-25 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/50 dark:hover:bg-white/[0.03]"
                                    :class="isSelected(account) && 'bg-brand-50 hover:bg-brand-50 dark:bg-brand-500/10 dark:hover:bg-brand-500/10'"
                                    x-on:click="pick(account)"
                                    :title="boardLine(account)"
                                    :aria-label="who(account) + ': ' + boardLine(account)"
                                >
                                    <span class="text-right text-[12px] tabular-nums text-gray-400" x-text="index + 1"></span>
                                    <span class="min-w-0 truncate text-[12.5px] tabular-nums">
                                        <span class="font-medium text-gray-950 dark:text-white" x-text="'#' + account.id"></span>
                                        <span class="text-gray-400" x-text="countryShort(account)"></span>
                                    </span>
                                    <span class="h-3.5 min-w-0">
                                        <span class="block h-full rounded-r-[4px]" :class="isSelected(account) ? 'bg-brand-500' : 'bg-gray-300 dark:bg-gray-600'" :style="`width: ${boardWidth(account)}%`"></span>
                                    </span>
                                    <span class="text-right text-[13px] font-semibold tabular-nums text-gray-950 dark:text-white" x-text="number(account.added)"></span>
                                </button>
                            </li>
                        </template>
                    </ol>

                    <div x-show="ranked().length > boardTop" class="border-t border-gray-100 px-4 py-2.5 dark:border-gray-800">
                        <x-driver-check.button size="sm" x-on:click="showAll = !showAll">
                            <span x-text="boardToggle()"></span>
                        </x-driver-check.button>
                    </div>
                </x-driver-check.surface>

                {{-- Age by the Telegram id against how much an account adds a day --}}
                <x-driver-check.surface x-show="points().length > 0" class="overflow-hidden">
                    <div class="flex items-start justify-between gap-3 px-4 pt-4">
                        <div class="min-w-0">
                            <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.ranking.age.title') }}</h2>
                            <p class="mt-0.5 max-w-3xl text-[12px] leading-relaxed text-gray-400">{{ __('driver_check.invites.ranking.age.hint') }}</p>
                        </div>
                        <p class="min-h-[2.5rem] max-w-[16rem] shrink-0 text-right text-[12px] leading-snug tabular-nums text-gray-600 dark:text-gray-300">
                            <template x-if="hoverPoint">
                                <span>
                                    <span class="block font-semibold text-gray-950 dark:text-white" x-text="who(hoverPoint)"></span>
                                    <span x-text="pointLine(hoverPoint)"></span>
                                </span>
                            </template>
                        </p>
                    </div>

                    <div class="overflow-x-auto px-2 pb-2 pt-3">
                        <svg
                            viewBox="0 0 640 300"
                            class="h-auto w-full min-w-[560px]"
                            role="img"
                            aria-label="{{ __('driver_check.invites.ranking.age.title') }}"
                            x-html="scatter()"
                            x-on:mouseover="scatterOver($event)"
                            x-on:mouseleave="hoverPoint = null"
                            x-on:click="scatterClick($event)"
                        ></svg>
                    </div>

                    <p x-show="unplaced() > 0" class="border-t border-gray-100 px-4 py-2.5 text-[11.5px] text-gray-500 dark:border-gray-800" x-text="t.ranking.age.unknown.replace(':n', unplaced())"></p>
                </x-driver-check.surface>

                {{-- By the number's country --}}
                <x-driver-check.surface class="overflow-hidden">
                    <div class="px-4 pt-4">
                        <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.ranking.countries.title') }}</h2>
                        <p class="mt-0.5 text-[12px] text-gray-400">{{ __('driver_check.invites.ranking.countries.hint') }}</p>
                    </div>

                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full min-w-[720px] text-left text-[12.5px]">
                            <thead class="bg-gray-25 text-[10.5px] uppercase tracking-wide text-gray-400 dark:bg-white/[0.02]">
                                <tr>
                                    <th class="px-4 py-2 font-medium">{{ __('driver_check.invites.ranking.countries.cols.country') }}</th>
                                    <th class="px-4 py-2 text-right font-medium">{{ __('driver_check.invites.ranking.countries.cols.accounts') }}</th>
                                    <th class="px-4 py-2 text-right font-medium">{{ __('driver_check.invites.ranking.countries.cols.worked') }}</th>
                                    <th class="px-4 py-2 text-right font-medium">{{ __('driver_check.invites.ranking.countries.cols.tried') }}</th>
                                    <th class="px-4 py-2 text-right font-medium">{{ __('driver_check.invites.ranking.countries.cols.added') }}</th>
                                    <th class="px-4 py-2 font-medium">{{ __('driver_check.invites.ranking.countries.cols.per_day') }}</th>
                                    <th class="px-4 py-2 text-right font-medium">{{ __('driver_check.invites.ranking.countries.cols.reach') }}</th>
                                    <th class="px-4 py-2 text-right font-medium">{{ __('driver_check.invites.ranking.countries.cols.floods') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 tabular-nums dark:divide-gray-800">
                                <template x-for="row in countries()" :key="'c-' + row.key">
                                    <tr>
                                        <td class="px-4 py-2 font-medium text-gray-950 dark:text-white" x-text="row.label"></td>
                                        <td class="px-4 py-2 text-right" x-text="number(row.accounts)"></td>
                                        <td class="px-4 py-2 text-right" x-text="number(row.worked)"></td>
                                        <td class="px-4 py-2 text-right" x-text="number(row.tried)"></td>
                                        <td class="px-4 py-2 text-right font-semibold text-gray-950 dark:text-white" x-text="number(row.added)"></td>
                                        <td class="px-4 py-2">
                                            <span class="flex items-center gap-2">
                                                <span class="h-2 w-24 shrink-0">
                                                    <span class="block h-full rounded-r-[3px] bg-brand-500" :style="`width: ${row.bar}%`"></span>
                                                </span>
                                                <span x-text="one(row.perDay)"></span>
                                            </span>
                                        </td>
                                        <td class="px-4 py-2 text-right" x-text="reachText(row.reach)"></td>
                                        <td class="px-4 py-2 text-right" x-text="number(row.floods)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </x-driver-check.surface>

                {{-- Every account, sortable; a row shows its days above --}}
                <x-driver-check.surface class="overflow-hidden">
                    <div class="px-4 pt-4">
                        <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('driver_check.invites.ranking.table.title') }}</h2>
                        <p class="mt-0.5 text-[12px] text-gray-400">{{ __('driver_check.invites.ranking.table.hint') }}</p>
                    </div>

                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full min-w-[1180px] text-left text-[12.5px]">
                            <thead class="bg-gray-25 text-[10.5px] uppercase tracking-wide text-gray-400 dark:bg-white/[0.02]">
                                <tr>
                                    @foreach (__('driver_check.invites.ranking.table.cols') as $column => $label)
                                        <th
                                            class="px-4 py-2 font-medium {{ in_array($column, ['account', 'country', 'age', 'state'], true) ? '' : 'text-right' }}"
                                            :aria-sort="sortState('{{ $column }}')"
                                        >
                                            <button type="button" class="inline-flex items-center gap-1 uppercase tracking-wide hover:text-gray-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/50 dark:hover:text-white" :class="sortState('{{ $column }}') && 'text-gray-900 dark:text-white'" x-on:click="setSort('{{ $column }}')">
                                                <span>{{ $label }}</span>
                                                <span x-show="sortState('{{ $column }}') === 'descending'" x-cloak><x-driver-check.icon name="arrow-down" class="h-3 w-3" /></span>
                                                <span x-show="sortState('{{ $column }}') === 'ascending'" x-cloak><x-driver-check.icon name="arrow-up" class="h-3 w-3" /></span>
                                            </button>
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 tabular-nums dark:divide-gray-800">
                                <template x-for="account in table()" :key="'a-' + account.id">
                                    <tr
                                        class="cursor-pointer transition hover:bg-gray-25 dark:hover:bg-white/[0.02]"
                                        :class="[isSelected(account) && 'bg-brand-50 dark:bg-brand-500/10', account.excluded && 'opacity-60']"
                                        x-on:click="pick(account)"
                                    >
                                        <td class="px-4 py-2">
                                            <span class="block font-medium text-gray-950 dark:text-white" x-text="'#' + account.id"></span>
                                            <span class="block text-[11px] text-gray-400" x-text="phone(account.phone)"></span>
                                        </td>
                                        <td class="px-4 py-2 text-gray-600 dark:text-gray-300" x-text="countryLabel(account.country)"></td>
                                        <td class="px-4 py-2">
                                            <span class="block text-gray-950 dark:text-white" x-text="ageCell(account)"></span>
                                            <span class="block text-[11px] text-gray-400" x-text="t.accounts.ages[account.age] ?? ''"></span>
                                        </td>
                                        <td class="px-4 py-2 text-right text-gray-500" x-text="account.since ? shortDay(account.since) : '—'"></td>
                                        <td class="px-4 py-2 text-right" x-text="number(account.activeDays)"></td>
                                        <td class="px-4 py-2 text-right" x-text="number(account.tried)"></td>
                                        <td class="px-4 py-2 text-right font-semibold text-gray-950 dark:text-white" x-text="number(account.added)"></td>
                                        <td class="px-4 py-2 text-right" x-text="one(account.perDay)"></td>
                                        <td class="px-4 py-2 text-right" x-text="reachText(account.reach)"></td>
                                        <td class="px-4 py-2 text-right">
                                            <span x-text="account.best > 0 ? number(account.best) : '—'"></span>
                                            <span x-show="account.best > 0" class="text-[11px] text-gray-400" x-text="shortDay(account.bestDay)"></span>
                                        </td>
                                        <td class="px-4 py-2 text-right" x-text="number(account.today)"></td>
                                        <td class="px-4 py-2 text-right" :title="floodTitle(account)" x-text="floodCell(account)"></td>
                                        <td class="px-4 py-2">
                                            <span class="flex items-center gap-2">
                                                <span class="dc-dot shrink-0" :class="stateDot(account)"></span>
                                                <span class="max-w-[16rem] truncate text-gray-600 dark:text-gray-300" :title="stateLine(account)" x-text="stateLine(account)"></span>
                                            </span>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <p class="border-t border-gray-100 px-4 py-2.5 text-[11.5px] leading-relaxed text-gray-500 dark:border-gray-800">{{ __('driver_check.invites.ranking.notes') }}</p>
                </x-driver-check.surface>
            </div>
        </template>
    </x-driver-check.shell>
</div>

@endsection
