@extends('layouts.driver-check')

@section('title', __('driver_check.menu.group_invites'))

@section('content')

<div
    x-data="dcGroupInvites({
        endpoints: {
            show: @js(route('api.telegram.group-invites.show')),
            run: @js(route('api.telegram.group-invites.run')),
            check: @js(route('api.telegram.group-invites.check')),
            checkLeft: @js(route('api.telegram.group-invites.check-left')),
            stop: @js(route('api.telegram.group-invites.stop')),
            spamCheck: @js(route('api.telegram.group-invites.accounts.spam-check-all')),
        },
        links: {
            people: @js(route('driver-check.group-invites.people')),
        },
        translations: @js(__('driver_check.invites')),
    })"
>
    <x-driver-check.shell>

        <x-driver-check.page-header
            :title="__('driver_check.invites.overview.title')"
            :description="__('driver_check.invites.overview.description')"
        >
            <x-slot:actions>
                <x-driver-check.button
                    size="icon"
                    x-on:click="load()"
                    ::disabled="loading || anyStageLoading()"
                    :title="__('telegram.ui.refresh')"
                    :aria-label="__('telegram.ui.refresh')"
                >
                    <x-driver-check.icon name="refresh" class="h-4 w-4" ::class="(loading || anyStageLoading()) && 'animate-spin'" />
                </x-driver-check.button>
            </x-slot:actions>
        </x-driver-check.page-header>

        <x-driver-check.error-alert :title="__('driver_check.invites.load_failed')" retry="load()" />

        {{-- What runs now, or how the last run ended - and the buttons that start, stop, check --}}
        <div class="dc-state" :data-tone="running ? 'run' : (error ? 'bad' : 'off')">
            <div class="dc-state__text">
                <div class="flex items-center gap-2">
                    <span class="dc-dot" :class="running ? 'animate-pulse text-success-500' : 'text-gray-300 dark:text-gray-600'"></span>
                    <p class="dc-eyebrow" x-text="running ? t.now.title_running : t.now.title_idle"></p>
                    <span x-show="running && now" x-cloak class="dc-tag" x-text="modeLabel(now)"></span>
                </div>

                <div x-show="!stages.now.loaded" class="mt-3 space-y-2" aria-hidden="true">
                    <div class="dc-skeleton h-4 w-2/5 rounded bg-gray-100 dark:bg-white/[0.06]"></div>
                    <div class="dc-skeleton h-3 w-1/4 rounded bg-gray-100 dark:bg-white/[0.06]"></div>
                </div>

                <template x-if="stages.now.loaded && running">
                    <div class="mt-2">
                        <p class="dc-state__title" x-text="doingLabel()"></p>
                        <p class="dc-state__line">
                            <span x-text="whereLabel()"></span>
                            <span x-show="untilLabel()" x-text="(whereLabel() ? ' · ' : '') + untilLabel()"></span>
                        </p>

                        <div x-show="progress() !== null" x-cloak class="mt-3 h-1.5 w-full max-w-md overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                            <div class="h-full rounded-full bg-brand-500 transition-all duration-500" :style="`width: ${progress() ?? 0}%`"></div>
                        </div>

                        <p class="mt-2 text-[12px] tabular-nums text-gray-400" x-text="clockLine()"></p>
                        <p x-show="now?.stop_asked" x-cloak class="mt-2 text-[12.5px] font-medium text-warning-600 dark:text-warning-400" x-text="t.now.stopping"></p>
                        <p x-show="silent()" x-cloak class="mt-2 text-[12.5px] font-medium text-error-600 dark:text-error-400" x-text="silentLabel()"></p>
                        <p x-show="now?.totals" x-cloak class="mt-2 text-[13px] tabular-nums text-gray-700 dark:text-gray-300">
                            <span class="text-gray-400" x-text="t.now.so_far + ': '"></span>
                            <span x-text="summaryText(now?.totals || {})"></span>
                        </p>
                    </div>
                </template>

                <template x-if="stages.now.loaded && !running">
                    <div class="mt-2">
                        <p class="dc-state__title" x-text="lastRunLabel()"></p>
                        <p x-show="now?.totals" x-cloak class="dc-state__line tabular-nums" x-text="summaryText(now?.totals || {})"></p>
                        <p class="dc-state__line" x-show="data.settings" x-text="scheduleLabel() + ' · ' + audienceLabel()"></p>
                    </div>
                </template>
            </div>

            <div class="dc-state__acts">
                <x-driver-check.button variant="primary" icon="play" x-show="!running" x-on:click="run()" ::disabled="busy || !stages.now.loaded">
                    {{ __('driver_check.invites.run') }}
                </x-driver-check.button>

                <x-driver-check.button variant="danger" icon="stop" x-show="running" x-cloak x-on:click="stop()" ::disabled="busy || now?.stop_asked">
                    <span x-text="now?.stop_asked ? t.now.doing.stopping : t.stop"></span>
                </x-driver-check.button>

                <x-driver-check.button icon="check-circle" x-show="!running" x-on:click="check()" ::disabled="busy || !stages.groups.loaded || data.added.sent === 0" :title="__('driver_check.invites.check_hint')">
                    <span x-text="t.check + (data.added.sent > 0 ? ' · ' + number(data.added.sent) : '')"></span>
                </x-driver-check.button>
                <x-driver-check.button icon="logout" x-show="!running" x-on:click="checkLeft()" ::disabled="busy || !stages.groups.loaded || data.added.still === 0" :title="__('driver_check.invites.overview.left_hint')">
                    {{ __('driver_check.invites.overview.left_button') }}
                </x-driver-check.button>
                <x-driver-check.button icon="shield" x-on:click="askSpamBot()" ::disabled="busy" :title="__('driver_check.invites.accounts.spam_hint')">
                    {{ __('driver_check.invites.accounts.spam_ask_all') }}
                </x-driver-check.button>
            </div>
        </div>

        {{-- Today in numbers --}}
        <div class="dc-stats">
            <div class="dc-stat">
                <p class="dc-stat__label">{{ __('driver_check.invites.overview.today_added') }}</p>
                <p class="dc-stat__value" x-text="stages.groups.loaded ? number(data.today.added) : '—'"></p>
            </div>
            <div class="dc-stat">
                <p class="dc-stat__label">{{ __('driver_check.invites.overview.today_calls') }}</p>
                <p class="dc-stat__value" x-text="stages.groups.loaded ? number(data.today.calls) : '—'"></p>
            </div>
            <div class="dc-stat">
                <p class="dc-stat__label">{{ __('driver_check.invites.overview.today_lookups') }}</p>
                <p class="dc-stat__value" x-text="stages.groups.loaded ? number(data.today.lookups) : '—'"></p>
            </div>
            <a href="{{ route('driver-check.group-invites.analysis') }}" class="dc-stat hover:bg-gray-25 dark:hover:bg-white/[0.02]">
                <p class="dc-stat__label">{{ __('driver_check.invites.overview.today_stops') }} →</p>
                <p class="dc-stat__value" :class="stages.groups.loaded && data.today.stops > 0 && 'text-warning-600 dark:text-warning-400'" x-text="stages.groups.loaded ? number(data.today.stops) : '—'"></p>
            </a>
            <div class="dc-stat">
                <p class="dc-stat__label">{{ __('driver_check.invites.overview.progress.done') }}</p>
                <p class="dc-stat__value" x-text="stages.day.loaded ? Math.floor(dayShare()) + '%' : '—'"></p>
                <p class="dc-stat__note" x-text="stages.day.loaded ? dayMadeLine() : ''"></p>
            </div>
        </div>

        <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">

            {{-- Our groups --}}
            <x-driver-check.block :title="__('driver_check.invites.overview.groups_title')" stage="groups">
                <x-slot:actions>
                    <span class="hidden text-[12px] text-gray-400 md:inline" x-text="lastLeftLabel()"></span>
                    <x-driver-check.button size="sm" variant="ghost" :href="route('driver-check.group-invites.groups')">
                        {{ __('driver_check.invites.overview.open_groups') }} →
                    </x-driver-check.button>
                </x-slot:actions>

                {{-- The one group people go into now, and who is waiting for it --}}
                <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                    <template x-if="data.current">
                        <div>
                            <p class="inline-flex items-center gap-1.5 text-[11.5px] font-semibold text-success-700 dark:text-success-400">
                                <span class="dc-dot text-success-500"></span>
                                {{ __('driver_check.invites.overview.current') }}
                            </p>
                            <p class="mt-1 truncate text-[15px] font-semibold text-gray-950 dark:text-white" x-text="data.current.title"></p>
                            <p class="mt-0.5 text-[13px] text-gray-700 dark:text-gray-200" x-text="queueLine(data.current)"></p>
                            <p x-show="data.current.last_error" x-cloak class="mt-1 text-[12px] text-error-600 dark:text-error-400" x-text="errorLabel(data.current.last_error, true)"></p>
                        </div>
                    </template>
                    <p x-show="!data.current" x-cloak class="text-[13px] text-gray-500">{{ __('driver_check.invites.overview.no_current') }}</p>
                </div>

                <p x-show="activeGroups().length === 0" x-cloak class="dc-none">{{ __('driver_check.invites.overview.no_groups') }}</p>

                <x-driver-check.table min="460px" x-show="activeGroups().length > 0">
                    <thead>
                        <tr>
                            <th>{{ __('driver_check.invites.groups.table.cols.group') }}</th>
                            <th class="dc-num">{{ __('driver_check.invites.overview.col_added') }}</th>
                            <th class="dc-num">{{ __('driver_check.invites.overview.col_left') }}</th>
                            <th class="dc-num">{{ __('driver_check.invites.overview.col_not_added') }}</th>
                            <th class="dc-num">{{ __('driver_check.invites.overview.col_failed') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="group in activeGroups()" :key="group.id">
                            <tr :data-on="group.id === data.current?.id || null">
                                <td>
                                    <span class="dc-main block max-w-[240px] truncate" :class="group.id !== data.current?.id && '!font-medium !text-gray-500'" x-text="group.title"></span>
                                    <span class="dc-sub2" x-text="group.id === data.current?.id ? t.overview.is_current : t.overview.is_waiting"></span>
                                </td>
                                <td class="dc-num dc-main" x-text="number(group.added)"></td>
                                <td class="dc-num">
                                    <span x-text="number(group.left)"></span>
                                    <span x-show="group.left > 0" x-cloak class="dc-sub2" x-text="leftShare(group) + '%'"></span>
                                </td>
                                <td class="dc-num" x-text="number(group.not_added)"></td>
                                <td class="dc-num" x-text="number(group.failed)"></td>
                            </tr>
                        </template>
                    </tbody>
                </x-driver-check.table>
            </x-driver-check.block>

            {{-- Accounts and the way the run is set --}}
            <x-driver-check.block :title="__('driver_check.invites.overview.accounts_title')" stage="light">
                <x-slot:actions>
                    <x-driver-check.button size="sm" variant="ghost" :href="route('driver-check.group-invites.accounts')">
                        {{ __('driver_check.invites.overview.open_accounts') }} →
                    </x-driver-check.button>
                </x-slot:actions>

                <dl class="divide-y divide-gray-100 text-[13px] dark:divide-gray-800">
                    <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                        <dt class="text-gray-500">{{ __('driver_check.invites.overview.taking_part') }}</dt>
                        <dd class="font-semibold tabular-nums text-gray-950 dark:text-white" x-text="number(data.accounts.taking_part) + ' / ' + number(data.accounts.total)"></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                        <dt class="text-gray-500">{{ __('driver_check.invites.overview.resting') }}</dt>
                        <dd class="font-semibold tabular-nums" :class="data.accounts.resting > 0 ? 'text-warning-600 dark:text-warning-400' : 'text-gray-950 dark:text-white'" x-text="number(data.accounts.resting)"></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                        <dt class="text-gray-500">{{ __('driver_check.invites.overview.held') }}</dt>
                        <dd class="font-semibold tabular-nums" :class="data.accounts.held > 0 ? 'text-error-600 dark:text-error-400' : 'text-gray-950 dark:text-white'" x-text="number(data.accounts.held)"></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                        <dt class="text-gray-500">{{ __('driver_check.invites.overview.pace') }}</dt>
                        <dd class="text-right tabular-nums text-gray-950 dark:text-white" x-text="paceLabel()"></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                        <dt class="text-gray-500">{{ __('driver_check.invites.overview.audience') }}</dt>
                        <dd class="text-right text-gray-950 dark:text-white" x-text="audienceLabel()"></dd>
                    </div>
                </dl>

                <x-slot:foot>
                    <a href="{{ route('driver-check.group-invites.settings') }}" class="font-medium text-gray-600 hover:text-gray-950 hover:underline dark:text-gray-300 dark:hover:text-white">
                        {{ __('driver_check.invites.overview.open_settings') }} →
                    </a>
                </x-slot:foot>
            </x-driver-check.block>
        </div>

        {{-- The base: everybody, and where each one stands --}}
        <x-driver-check.block :title="__('driver_check.invites.overview.base_title')" :hint="__('driver_check.invites.overview.base_hint')" stage="base" :lines="3">
            <x-slot:actions>
                <x-driver-check.button size="sm" variant="ghost" :href="route('driver-check.group-invites.people')">
                    {{ __('driver_check.invites.overview.open_base') }} →
                </x-driver-check.button>
            </x-slot:actions>

            <div class="p-4">
                <div class="flex flex-wrap items-end gap-x-6 gap-y-2">
                    <div>
                        <p class="dc-eyebrow">{{ __('driver_check.invites.overview.processed') }}</p>
                        <p class="mt-1.5 text-[30px] font-semibold leading-none tracking-[-0.02em] tabular-nums text-gray-950 dark:text-white">
                            <span x-text="number(base().processed)"></span>
                            <span class="text-[17px] font-medium text-gray-400" x-text="' / ' + number(base().total)"></span>
                        </p>
                    </div>
                    <p class="pb-0.5 text-[13px] tabular-nums text-gray-500" x-text="t.overview.processed_share.replace(':n', percent(base().processed, base().total))"></p>
                </div>

                {{-- One bar, four parts, 2px apart; each says what it is on hover and opens its people --}}
                <div
                    class="mt-4 flex h-3 w-full gap-[2px] overflow-hidden rounded-full"
                    :class="base().total === 0 && 'bg-gray-100 dark:bg-white/[0.06]'"
                    role="img"
                    :aria-label="segments().map((s) => segmentTitle(s)).join(', ')"
                >
                    <template x-for="segment in segments()" :key="segment.flag">
                        <a
                            x-show="segment.value > 0"
                            :href="peopleLink({ flag: segment.flag })"
                            class="h-full first:rounded-l-full last:rounded-r-full transition-opacity hover:opacity-80"
                            :class="flagFill(segment.flag)"
                            :style="`width: ${segment.share}%; min-width: 4px`"
                            :title="segmentTitle(segment)"
                        ></a>
                    </template>
                </div>

                <ul class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <template x-for="segment in segments()" :key="'legend-' + segment.flag">
                        <li>
                            <a :href="peopleLink({ flag: segment.flag })" class="group block rounded-xl border border-gray-100 p-3 transition hover:border-gray-300 dark:border-gray-800 dark:hover:border-gray-600">
                                <span class="flex items-center gap-2">
                                    <span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="flagFill(segment.flag)"></span>
                                    <span class="truncate text-[12.5px] font-medium text-gray-600 dark:text-gray-300" x-text="flagLabel(segment.flag)"></span>
                                </span>
                                <span class="mt-1.5 flex items-baseline gap-1.5">
                                    <span class="text-[22px] font-semibold leading-none tabular-nums text-gray-950 dark:text-white" x-text="number(segment.value)"></span>
                                    <span class="text-[12px] tabular-nums text-gray-400" x-text="percent(segment.value, base().total) + '%'"></span>
                                </span>
                                <span class="mt-1 block text-[11.5px] leading-snug text-gray-400" x-text="t.flag_hints[segment.flag]"></span>
                            </a>
                        </li>
                    </template>
                </ul>

                <p x-show="base().untried > 0" x-cloak class="mt-3 text-[12.5px] text-gray-500">
                    <span x-text="t.overview.untried_split_lead"></span>
                    <a :href="peopleLink({ flag: 'untried', reach: 'reachable' })" class="font-medium text-gray-900 hover:underline dark:text-white" x-text="t.overview.untried_reachable.replace(':n', number(base().untried_reachable))"></a>
                    ·
                    <a :href="peopleLink({ flag: 'untried', reach: 'unreachable' })" class="font-medium text-gray-900 hover:underline dark:text-white" x-text="t.overview.untried_unreachable.replace(':n', number(base().untried_unreachable))"></a>
                </p>
            </div>
        </x-driver-check.block>

        {{-- The day: the ideal one (every account makes all its calls of today) and how far the accounts are into it --}}
        <x-driver-check.block :title="__('driver_check.invites.overview.progress.title')" stage="day" :lines="2">
            <div class="p-4">
                <p class="-mt-1 mb-3 text-[12px] leading-snug text-gray-400" x-text="t.overview.progress.hint.replace(':size', number(day().batch_size))"></p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <p class="dc-eyebrow">{{ __('driver_check.invites.overview.progress.max') }}</p>
                        <p class="mt-1.5 text-[24px] font-semibold leading-none tracking-[-0.02em] tabular-nums text-gray-950 dark:text-white" x-text="number(day().max)"></p>
                        <p class="mt-1 text-[12px] tabular-nums text-gray-500" x-text="t.overview.progress.max_line.replace(':n', number(day().accounts))"></p>
                    </div>
                    <div>
                        <p class="dc-eyebrow">{{ __('driver_check.invites.overview.progress.done') }}</p>
                        <p class="mt-1.5 text-[24px] font-semibold leading-none tracking-[-0.02em] tabular-nums text-success-600 dark:text-success-400" x-text="Math.floor(dayShare()) + '%'"></p>
                        <p class="mt-1 text-[12px] tabular-nums text-gray-500" x-text="dayMadeLine()"></p>
                    </div>
                </div>

                <div
                    class="mt-4 h-3 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/[0.06]"
                    role="progressbar"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    :aria-valuenow="Math.floor(dayShare())"
                >
                    <span x-show="day().made > 0" class="block h-full rounded-full bg-success-500" :style="`width: ${dayShare()}%; min-width: 4px`"></span>
                </div>
            </div>
        </x-driver-check.block>

        {{-- The last lines of the latest run --}}
        <x-driver-check.block :title="__('driver_check.invites.overview.recent_title')" stage="now" :lines="3">
            <x-slot:actions>
                <x-driver-check.button size="sm" variant="ghost" :href="route('driver-check.group-invites.log')">
                    {{ __('driver_check.invites.overview.open_log') }} →
                </x-driver-check.button>
            </x-slot:actions>

            <p x-show="data.recent.length === 0" x-cloak class="dc-none">{{ __('driver_check.invites.log.empty') }}</p>

            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                <template x-for="line in data.recent" :key="line.id">
                    <li class="flex items-start gap-3 px-4 py-2">
                        <span class="mt-0.5 w-[62px] shrink-0 text-[12px] tabular-nums text-gray-400" x-text="clock(line.created_at)"></span>
                        <span class="dc-dot mt-1.5 shrink-0" :class="toneClass(lineTone(line))"></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[13px] text-gray-900 dark:text-gray-100" x-text="lineText(line)"></p>
                            <p x-show="line.account || line.group" class="truncate text-[11.5px] text-gray-400" x-text="[phone(line.account), line.group].filter(Boolean).join(' · ')"></p>
                        </div>
                    </li>
                </template>
            </ul>
        </x-driver-check.block>
    </x-driver-check.shell>
</div>

@endsection
