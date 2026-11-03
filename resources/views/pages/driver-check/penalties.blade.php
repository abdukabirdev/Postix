@extends('layouts.driver-check')

@section('title', __('telegram.penalties.title'))

@section('content')

@php
    $rules = app(\App\Application\Telegram\Services\ClientCheckRulesStore::class)->current();

    /*
     * Level names as the settings page has them, per role - operators and
     * sales have ladders of their own. A level without a name is "Level N".
     */
    $levelNames = collect(\App\Models\Telegram\OperationUser::ROLES)
        ->mapWithKeys(fn (string $role) => [
            $role => collect($rules->levels($role))
                ->map(fn (array $level, int $n) => $level['name']
                    ?? __('telegram.penalties.level_n', ['n' => $n + 1]))
                ->values()
                ->all(),
        ])
        ->all();

    /*
     * The level filter lists the longer ladder; a level both have is named
     * after the operators' one.
     */
    $levelOptions = collect(range(0, max(
        count($levelNames['operation']), count($levelNames['sales'])
    ) - 1))
        ->mapWithKeys(fn (int $n) => [$n => $levelNames['operation'][$n] ?? $levelNames['sales'][$n]])
        ->all();
@endphp

<div
    x-data="dcPenalties({
        endpoints: {
            index: @js(route('api.telegram.client-checks.index')),
            operators: @js(route('driver-check.operators')),
            sales: @js(route('driver-check.sales')),
        },
        levelNames: @js($levelNames),
        translations: @js(__('telegram.penalties')),
        languages: @js(__('telegram.penalty_settings.languages')),
        tones: @js(__('telegram.penalty_settings.tones')),
        ui: @js(__('telegram.ui')),
    })"
>
    <x-driver-check.shell>

        {{-- ============================================================
             Header: where delivery stands, and the way to change it
        ============================================================= --}}
        <x-driver-check.page-header
            icon="alert"
            tone="brand"
            :eyebrow="__('telegram.menu.groups.check')"
            :title="__('telegram.penalties.title')"
            :description="__('telegram.penalties.description')"
        >
            <x-slot:actions>
                <a
                    href="{{ route('driver-check.penalties.sending') }}"
                    class="dc-tap col-span-2 inline-flex h-10 items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-3
                           text-[13px] font-medium text-gray-700 transition hover:border-gray-400 sm:col-span-1 sm:h-9
                           dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200 dark:hover:border-gray-600"
                >
                    <span
                        class="h-2 w-2 rounded-full"
                        :class="settings.enabled ? 'bg-success-500' : 'bg-warning-500'"
                    ></span>
                    <span x-text="settings.enabled ? translations.delivery_on : translations.delivery_off"></span>
                    <span
                        x-show="settings.enabled && !settings.comments_enabled"
                        x-cloak
                        class="text-gray-500 dark:text-gray-400"
                        x-text="'· ' + translations.comments_off"
                    ></span>
                </a>

                <x-driver-check.button x-on:click="load()" ::disabled="loading" :title="__('telegram.ui.refresh')">
                    <x-driver-check.icon
                        name="refresh"
                        class="h-4 w-4"
                        ::class="loading && 'animate-spin'"
                    />
                    <span x-text="loading ? ui.loading : ui.refresh"></span>
                </x-driver-check.button>

                <x-driver-check.button variant="primary" icon="sliders" :href="route('driver-check.penalties.sending')">
                    {{ __('telegram.penalties.open_settings') }}
                </x-driver-check.button>
            </x-slot:actions>
        </x-driver-check.page-header>

        {{-- Delivery off: one line, and the way back on --}}
        <div
            x-show="!settings.enabled"
            x-cloak
            class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-warning-200 bg-warning-50 px-4 py-2.5
                   text-[13px] text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-300"
        >
            <x-driver-check.icon name="alert" class="h-4 w-4 shrink-0" />
            <span class="min-w-0 flex-1">{{ __('telegram.penalties.settings.off_banner') }}</span>
            <a href="{{ route('driver-check.penalties.sending') }}" class="font-semibold underline-offset-2 hover:underline">
                {{ __('telegram.penalties.off_link') }}
            </a>
        </div>

        {{-- ============================================================
             Counters: four, one row; the rest rides along as hints
             (the status filter is left out of them)
        ============================================================= --}}
        <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-driver-check.stat-card :label="__('telegram.penalties.stats.total')" icon="inbox">
                <span x-text="number(stats.total)">0</span>
                <x-slot:hint>
                    <span x-text="translations.stats.people + ': ' + number(stats.people)"></span>
                </x-slot:hint>
            </x-driver-check.stat-card>

            <x-driver-check.stat-card
                :label="__('telegram.penalties.stats.sent')"
                icon="check-circle"
            >
                <span x-text="number(stats.sent)">0</span>
                <x-slot:hint>
                    <span x-text="translations.stats.in_progress + ': ' + number(stats.in_progress)"></span>
                </x-slot:hint>
            </x-driver-check.stat-card>

            <x-driver-check.stat-card
                :label="__('telegram.penalties.stats.failed')"
                icon="x-circle"
            >
                <span
                    :class="stats.failed > 0 ? 'text-error-600 dark:text-error-400' : 'text-gray-900 dark:text-white'"
                    x-text="number(stats.failed)"
                >0</span>
                <x-slot:hint>
                    <span x-text="translations.stats.critical + ': ' + number(stats.critical)"></span>
                </x-slot:hint>
            </x-driver-check.stat-card>

            <x-driver-check.stat-card
                :label="__('telegram.penalties.stats.skipped')"
                icon="unlink"
            >
                <span x-text="number(stats.skipped)">0</span>
            </x-driver-check.stat-card>
        </section>

        <x-driver-check.error-alert :title="__('telegram.penalties.errors.title')" />

        {{-- ============================================================
             Rows: a table from lg up, cards below it
        ============================================================= --}}
        <x-driver-check.surface class="overflow-hidden" x-ref="listTop">
            <x-driver-check.filters
                embedded
                :search-placeholder="__('telegram.penalties.search_placeholder')"
                :sort-options="[
                        'created_at' => __('telegram.penalties.table.created'),
                        'level' => __('telegram.penalties.table.level'),
                        'repeat_number' => __('telegram.penalties.table.repeat'),
                        'request_number' => __('telegram.penalties.table.request'),
                    ]"
            >
                <x-slot:leading>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        {{-- All | Operation | Sales --}}
                        <nav
                            class="inline-flex w-full gap-0.5 rounded-lg bg-gray-100 p-0.5 sm:w-auto dark:bg-white/[0.06]"
                            aria-label="{{ __('telegram.penalties.filters.role') }}"
                        >
                            <template x-for="tab in roleTabs()" :key="'tab-' + tab.value">
                                <button
                                    type="button"
                                    x-on:click="setRole(tab.value)"
                                    :aria-pressed="filters.role === tab.value"
                                    :class="filters.role === tab.value
                                        ? 'bg-white text-gray-950 shadow-sm dark:bg-gray-700 dark:text-white'
                                        : 'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white'"
                                    class="dc-tap inline-flex h-10 flex-1 items-center justify-center gap-2 rounded-md px-3.5 text-[13px]
                                           font-medium transition outline-none focus-visible:ring-2 focus-visible:ring-brand-500/50 sm:h-8 sm:flex-none"
                                >
                                    <span x-text="tab.label"></span>
                                    <span
                                        :class="filters.role === tab.value
                                            ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900'
                                            : 'bg-gray-200/70 text-gray-600 dark:bg-white/[0.08] dark:text-gray-300'"
                                        class="inline-flex h-[18px] min-w-[18px] items-center justify-center rounded px-1
                                               text-[11px] font-semibold tabular-nums"
                                        x-text="number(tab.count)"
                                    ></span>
                                </button>
                            </template>
                        </nav>

                        {{-- Narrowed to one person (from the operators / sales pages) --}}
                        <span
                            x-show="filters.operation_user_id"
                            x-cloak
                            class="inline-flex h-9 max-w-full items-center gap-2 self-start rounded-lg border border-brand-200 bg-brand-50 pl-3 pr-1.5
                                   text-[13px] text-brand-700 sm:self-auto dark:border-brand-500/30 dark:bg-brand-500/10 dark:text-brand-300"
                        >
                            <x-driver-check.icon name="user" class="h-3.5 w-3.5 shrink-0" />
                            <span class="truncate font-medium" x-text="personFilterName()"></span>
                            <button
                                type="button"
                                x-on:click="clearPerson()"
                                class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md hover:bg-brand-100
                                       dark:hover:bg-brand-500/20"
                                :title="translations.filters.person_clear"
                                :aria-label="translations.filters.person_clear"
                            >
                                <x-driver-check.icon name="close" class="h-3.5 w-3.5" />
                            </button>
                        </span>
                    </div>
                </x-slot:leading>

                <x-driver-check.field
                    :label="__('telegram.penalties.filters.period')"
                    class="sm:col-span-2 xl:col-span-4"
                >
                    <div class="flex flex-col gap-2.5">
                        <x-driver-check.chips />

                        <div x-show="periodPreset === 'custom'" x-cloak class="grid grid-cols-2 gap-2 sm:max-w-md">
                            <x-driver-check.input
                                type="date"
                                x-model="filters.period_from"
                                x-on:change="applyCustomPeriod()"
                                :aria-label="__('telegram.penalties.filters.period_from')"
                            />
                            <x-driver-check.input
                                type="date"
                                x-model="filters.period_to"
                                x-on:change="applyCustomPeriod()"
                                :aria-label="__('telegram.penalties.filters.period_to')"
                            />
                        </div>
                    </div>
                </x-driver-check.field>

                <x-driver-check.field :label="__('telegram.penalties.filters.status')">
                    <x-driver-check.select x-model="filters.status" x-on:change="applyFilters()">
                        <option value="">{{ __('telegram.penalties.filters.status_all') }}</option>
                        @foreach (\App\Enums\Telegram\TelegramClientCheckStatus::cases() as $status)
                            <option value="{{ $status->value }}">{{ __("telegram.penalties.statuses.{$status->value}") }}</option>
                        @endforeach
                    </x-driver-check.select>
                </x-driver-check.field>

                <x-driver-check.field :label="__('telegram.penalties.filters.level')">
                    <x-driver-check.select x-model="filters.level" x-on:change="applyFilters()">
                        <option value="">{{ __('telegram.penalties.filters.level_all') }}</option>
                        @foreach ($levelOptions as $level => $name)
                            <option value="{{ $level }}">{{ $level + 1 }} · {{ $name }}</option>
                        @endforeach
                    </x-driver-check.select>
                </x-driver-check.field>
            </x-driver-check.filters>

            {{-- Table (lg and up) --}}
            <div class="hidden xl:block">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1080px] table-fixed text-left">
                        <colgroup>
                            <col class="w-[10%]">
                            <col class="w-[19%]">
                            <col class="w-[6%]">
                            <col class="w-[11%]">
                            <col class="w-[13%]">
                            <col class="w-[24%]">
                            <col class="w-[10%]">
                            <col class="w-[7%]">
                        </colgroup>

                        <thead class="dc-thead border-b border-gray-200 bg-gray-25 dark:border-gray-800 dark:bg-white/[0.02]">
                            <tr class="text-[10.5px] uppercase tracking-[0.08em] text-gray-500 dark:text-gray-400">
                                <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('telegram.penalties.table.request') }}</th>
                                <th scope="col" class="px-3 py-2.5 font-semibold">{{ __('telegram.penalties.table.responsible') }}</th>
                                <th scope="col" class="px-3 py-2.5 text-right font-semibold">{{ __('telegram.penalties.table.repeat') }}</th>
                                <th scope="col" class="px-3 py-2.5 font-semibold">{{ __('telegram.penalties.table.level') }}</th>
                                <th scope="col" class="px-3 py-2.5 font-semibold">{{ __('telegram.penalties.table.status') }}</th>
                                <th scope="col" class="px-3 py-2.5 font-semibold">{{ __('telegram.penalties.detail.comment') }}</th>
                                <th scope="col" class="px-3 py-2.5 font-semibold">{{ __('telegram.penalties.table.created') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-semibold">{{ __('telegram.penalties.table.actions') }}</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <x-driver-check.skeleton-rows :cols="8" />

                            <template x-for="row in rows" :key="row.id">
                                <tr class="dc-row align-middle transition hover:bg-gray-25 dark:hover:bg-white/[0.025]">
                                    {{-- Request --}}
                                    <td class="px-4 py-3">
                                        <p class="truncate font-mono text-sm font-medium text-gray-900 dark:text-white"
                                           x-text="row.request_number ? '#' + row.request_number : dash"></p>
                                        <p class="truncate text-[11px] text-gray-500 dark:text-gray-400"
                                           :title="row.crm_status"
                                           x-text="row.crm_status || dash"></p>
                                    </td>

                                    {{-- Responsible --}}
                                    <td class="px-3 py-3">
                                        <div class="flex items-center gap-1.5">
                                            <a
                                                x-show="row.person"
                                                :href="personUrl(row)"
                                                class="truncate text-[13px] font-medium text-gray-900 hover:underline dark:text-white"
                                                :title="responsibleName(row)"
                                                x-text="responsibleName(row)"
                                            ></a>
                                            <span
                                                x-show="!row.person"
                                                class="truncate text-[13px] text-gray-400 dark:text-gray-500"
                                                x-text="responsibleName(row)"
                                            ></span>

                                            <span
                                                x-show="responsibleRole(row)"
                                                class="shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium"
                                                :class="roleClass(responsibleRole(row))"
                                                x-text="roleText(responsibleRole(row))"
                                            ></span>
                                        </div>

                                        <p
                                            class="truncate text-[11px]"
                                            :class="row.person?.telegram_username
                                                ? 'text-gray-500 dark:text-gray-400'
                                                : 'text-gray-400 dark:text-gray-500'"
                                            x-text="row.person?.telegram_username ? handle(row.person.telegram_username) : (row.peer || dash)"
                                        ></p>
                                    </td>

                                    <td class="px-3 py-3 text-right text-sm tabular-nums text-gray-700 dark:text-gray-300"
                                        x-text="'№' + row.repeat_number"></td>

                                    {{-- Level --}}
                                    <td class="px-3 py-3">
                                        <span
                                            class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-[11px] font-medium"
                                            :class="levelClass(row.level, responsibleRole(row))"
                                            x-text="levelLabel(row)"
                                        ></span>
                                    </td>

                                    {{-- Status --}}
                                    <td class="px-3 py-3">
                                        <x-driver-check.badge ::class="statusClass(row)">
                                            <span class="h-1.5 w-1.5 rounded-full" :class="statusDot(row)"></span>
                                            <span x-text="statusText(row)"></span>
                                        </x-driver-check.badge>

                                        <p
                                            x-show="row.reason"
                                            x-cloak
                                            class="mt-0.5 line-clamp-2 text-[11px]"
                                            :class="row.status === 'failed'
                                                ? 'text-error-600 dark:text-error-400'
                                                : 'text-gray-500 dark:text-gray-400'"
                                            :title="row.error || reasonText(row)"
                                            x-text="reasonText(row)"
                                        ></p>
                                    </td>

                                    {{-- Comment --}}
                                    <td class="px-3 py-3">
                                        <p
                                            x-show="row.comment"
                                            class="dc-break line-clamp-2 text-[12px] leading-snug text-gray-700 dark:text-gray-300"
                                            :title="row.comment"
                                            x-text="row.comment"
                                        ></p>
                                        <span x-show="!row.comment" class="text-[12px] text-gray-300 dark:text-gray-600" x-text="dash"></span>
                                    </td>

                                    <td class="px-3 py-3">
                                        <p class="text-[13px] text-gray-700 dark:text-gray-300"
                                           :title="date(row.created_at)"
                                           x-text="relative(row.created_at)"></p>
                                    </td>

                                    <td class="px-4 py-3 text-right">
                                        <x-driver-check.row-action icon="eye" :label="__('telegram.penalties.table.details')" x-on:click="openDetail(row)" />
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Cards (below lg) --}}
            <div class="divide-y divide-gray-100 xl:hidden dark:divide-gray-800">
                <x-driver-check.skeleton-cards />

                <template x-for="row in rows" :key="'card-' + row.id">
                    <article class="dc-row flex flex-col gap-3 p-3.5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="dc-break font-mono text-sm font-semibold text-gray-900 dark:text-white"
                                   x-text="row.request_number ? '#' + row.request_number : dash"></p>
                                <p class="dc-break text-[11px] text-gray-500 dark:text-gray-400" x-text="row.crm_status || dash"></p>
                            </div>

                            <x-driver-check.badge ::class="statusClass(row)">
                                <span class="h-1.5 w-1.5 rounded-full" :class="statusDot(row)"></span>
                                <span x-text="statusText(row)"></span>
                            </x-driver-check.badge>
                        </div>

                        <dl class="grid grid-cols-2 gap-x-3 gap-y-2.5 rounded-lg bg-gray-25 p-3 ring-1 ring-inset ring-gray-100 dark:bg-white/[0.02] dark:ring-gray-800">
                            <x-driver-check.kv :label="__('telegram.penalties.table.responsible')" class="col-span-2">
                                <span x-text="responsibleName(row)"></span>
                                <span
                                    x-show="responsibleRole(row)"
                                    class="ml-1 rounded px-1.5 py-0.5 text-[10px] font-medium"
                                    :class="roleClass(responsibleRole(row))"
                                    x-text="roleText(responsibleRole(row))"
                                ></span>
                            </x-driver-check.kv>

                            <x-driver-check.kv :label="__('telegram.penalties.table.repeat')">
                                <span class="tabular-nums" x-text="'№' + row.repeat_number"></span>
                            </x-driver-check.kv>

                            <x-driver-check.kv :label="__('telegram.penalties.table.level')">
                                <span
                                    class="rounded-md px-2 py-0.5 text-[11px] font-medium"
                                    :class="levelClass(row.level, responsibleRole(row))"
                                    x-text="levelLabel(row)"
                                ></span>
                            </x-driver-check.kv>

                            <x-driver-check.kv :label="__('telegram.penalties.table.created')" class="col-span-2">
                                <span x-text="date(row.created_at)"></span>
                            </x-driver-check.kv>
                        </dl>

                        <p
                            x-show="row.comment"
                            class="dc-break line-clamp-3 rounded-lg border border-gray-100 px-3 py-2 text-[12px] leading-snug text-gray-700 dark:border-gray-800 dark:text-gray-300"
                            x-text="row.comment"
                        ></p>

                        <p
                            x-show="row.reason"
                            x-cloak
                            class="dc-break rounded-lg px-3 py-2 text-[11px] leading-snug"
                            :class="row.status === 'failed'
                                ? 'bg-error-50 text-error-600 dark:bg-error-500/10 dark:text-error-400'
                                : 'bg-gray-50 text-gray-600 dark:bg-white/[0.03] dark:text-gray-300'"
                            x-text="reasonText(row)"
                        ></p>

                        <x-driver-check.button class="w-full" x-on:click="openDetail(row)">
                            {{ __('telegram.penalties.table.details') }}
                        </x-driver-check.button>
                    </article>
                </template>
            </div>

            <x-driver-check.empty-state
                icon="inbox"
                :title="__('telegram.penalties.empty.title')"
                :description="__('telegram.penalties.empty.description')"
            />

            <x-driver-check.pagination />
        </x-driver-check.surface>
    </x-driver-check.shell>

    {{-- ================================================================
         Details
    ================================================================= --}}
    <x-driver-check.modal open="detailOpen" close="closeDetail()" size="sm:max-w-xl">
        <x-slot:heading>
            <span>{{ __('telegram.penalties.detail.title') }}</span>
            <span class="font-mono" x-text="detail?.request_number ? '#' + detail.request_number : ''"></span>
        </x-slot:heading>

        <template x-if="detail">
            <div class="flex flex-col gap-4">
                <div class="flex flex-wrap items-center gap-2">
                    <x-driver-check.badge ::class="statusClass(detail)">
                        <span class="h-1.5 w-1.5 rounded-full" :class="statusDot(detail)"></span>
                        <span x-text="statusText(detail)"></span>
                    </x-driver-check.badge>

                    <span
                        class="rounded-md px-2 py-0.5 text-[11px] font-medium"
                        :class="levelClass(detail.level, responsibleRole(detail))"
                        x-text="levelLabel(detail)"
                    ></span>

                    <span
                        x-show="responsibleRole(detail)"
                        class="rounded-md px-2 py-0.5 text-[11px] font-medium"
                        :class="roleClass(responsibleRole(detail))"
                        x-text="roleText(responsibleRole(detail))"
                    ></span>

                    <a
                        x-show="detail.crm_url"
                        :href="detail.crm_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="ml-auto inline-flex items-center gap-1 text-[13px] font-medium text-brand-600 hover:underline
                               dark:text-brand-400"
                    >
                        {{ __('telegram.penalties.table.crm') }}
                        <x-driver-check.icon name="external" class="h-3.5 w-3.5" />
                    </a>
                </div>

                {{-- What went wrong, and what fixes it --}}
                <div
                    x-show="detail.status === 'failed' || detail.status === 'skipped'"
                    class="dc-break rounded-lg px-3 py-2.5 text-xs"
                    :class="detail.status === 'failed'
                        ? 'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-400'
                        : 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400'"
                >
                    <p class="font-semibold" x-text="reasonText(detail)"></p>
                    <p x-show="detail.error" class="mt-1 font-mono text-[11px] opacity-80" x-text="detail.error"></p>
                    <p class="mt-1.5 opacity-90">{{ __('telegram.penalties.detail.retry_hint') }}</p>
                </div>

                <dl class="grid grid-cols-2 gap-x-3 gap-y-3 rounded-lg bg-gray-50 p-3 dark:bg-white/[0.02]">
                    <x-driver-check.kv :label="__('telegram.penalties.table.responsible')" class="col-span-2">
                        <a
                            x-show="detail.person"
                            :href="personUrl(detail)"
                            class="text-brand-600 hover:underline dark:text-brand-400"
                            x-text="responsibleName(detail)"
                        ></a>
                        <span x-show="!detail.person" x-text="responsibleName(detail)"></span>
                    </x-driver-check.kv>

                    <x-driver-check.kv :label="__('telegram.penalties.detail.crm_status')">
                        <span x-text="detail.crm_status || dash"></span>
                    </x-driver-check.kv>

                    <x-driver-check.kv :label="__('telegram.penalties.detail.time_in_status')">
                        <span x-text="detail.time_in_status || dash"></span>
                    </x-driver-check.kv>

                    <x-driver-check.kv :label="__('telegram.penalties.table.repeat')">
                        <span class="tabular-nums" x-text="'№' + detail.repeat_number"></span>
                    </x-driver-check.kv>

                    <x-driver-check.kv :label="__('telegram.penalties.detail.attempts')">
                        <span class="tabular-nums" x-text="number(detail.attempts)"></span>
                    </x-driver-check.kv>

                    <x-driver-check.kv :label="__('telegram.penalties.detail.peer')">
                        <span x-text="detail.peer || dash"></span>
                    </x-driver-check.kv>

                    <x-driver-check.kv :label="__('telegram.penalties.table.created')">
                        <span x-text="date(detail.created_at)"></span>
                    </x-driver-check.kv>

                    <x-driver-check.kv :label="__('telegram.penalties.detail.forwarded_at')">
                        <span x-text="detail.forwarded_at ? date(detail.forwarded_at) : dash"></span>
                    </x-driver-check.kv>

                    <x-driver-check.kv :label="__('telegram.penalties.detail.sent_at')">
                        <span x-text="detail.sent_at ? date(detail.sent_at) : dash"></span>
                    </x-driver-check.kv>

                    {{-- Which of the four texts the comment came from --}}
                    <x-driver-check.kv :label="__('telegram.penalties.detail.variant')" class="col-span-2">
                        <span x-text="variantText(detail)"></span>
                    </x-driver-check.kv>
                </dl>

                {{-- The comment: one per batch, stored on its last penalty --}}
                <section>
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        {{ __('telegram.penalties.detail.comment') }}
                        <span
                            x-show="detail.batch_count > 1"
                            class="ml-1 normal-case tracking-normal"
                            x-text="'· ' + translations.detail.batch + ': ' + detail.batch_count"
                        ></span>
                    </h3>

                    <p
                        x-show="detail.comment"
                        class="dc-break whitespace-pre-wrap rounded-lg bg-brand-25 px-3 py-2.5 text-[13px] leading-relaxed
                               text-gray-800 dark:bg-brand-500/[0.07] dark:text-gray-200"
                        x-text="detail.comment"
                    ></p>

                    <p
                        x-show="!detail.comment"
                        class="rounded-lg border border-dashed border-gray-200 px-3 py-2.5 text-[12px] text-gray-500
                               dark:border-gray-800 dark:text-gray-400"
                    >{{ __('telegram.penalties.detail.comment_on_last') }}</p>
                </section>

                {{-- The CRM had a carrier price: the sales manager's turn --}}
                <section x-show="detail.sales_turn" x-cloak>
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        {{ __('telegram.penalties.detail.sales_turn') }}
                    </h3>
                    <p class="rounded-lg bg-gray-50 px-3 py-2 text-[13px] leading-relaxed text-gray-800 dark:bg-white/[0.04] dark:text-gray-200">
                        {{ __('telegram.penalties.detail.sales_turn_price') }}:
                        <span
                            class="font-medium tabular-nums"
                            x-text="detail.sales_turn ? Number(detail.sales_turn.carrier_price).toLocaleString() + ' ' + (detail.sales_turn.currency || '') : ''"
                        ></span>
                        <span class="block text-[12px] text-gray-500 dark:text-gray-400">
                            {{ __('telegram.penalties.detail.sales_turn_named') }}:
                            <span x-text="detail.responsible_name || '—'"></span>
                        </span>
                    </p>
                </section>

                {{-- What the person wrote back, and our answer to it --}}
                <section x-show="detail.nudge_text && !detail.reply_text" x-cloak>
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        {{ __('telegram.penalties.detail.nudge') }}
                    </h3>
                    <p
                        class="dc-break whitespace-pre-wrap rounded-lg bg-warning-50 px-3 py-2 text-[13px] leading-relaxed
                               text-gray-800 dark:bg-warning-500/10 dark:text-gray-200"
                        x-text="detail.nudge_text"
                    ></p>
                </section>

                <section x-show="detail.reply_text" x-cloak>
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        {{ __('telegram.penalties.detail.reply') }}
                        <span
                            class="ml-1 normal-case tracking-normal"
                            x-text="'· ' + (detail.reply_kind || translations.detail.reply_unread)"
                        ></span>
                    </h3>

                    <div class="flex flex-col gap-1.5">
                        <p
                            class="dc-break self-start whitespace-pre-wrap rounded-lg bg-gray-100 px-3 py-2 text-[13px] leading-relaxed
                                   text-gray-800 dark:bg-white/[0.06] dark:text-gray-200"
                            x-text="detail.reply_text"
                        ></p>

                        <p
                            x-show="detail.reply_answer"
                            class="dc-break self-end whitespace-pre-wrap rounded-lg bg-brand-25 px-3 py-2 text-[13px] leading-relaxed
                                   text-gray-800 dark:bg-brand-500/[0.07] dark:text-gray-200"
                            x-text="detail.reply_answer"
                        ></p>

                        <p
                            x-show="!detail.reply_answer"
                            class="text-[11px] text-gray-400 dark:text-gray-500"
                        >{{ __('telegram.penalties.detail.reply_unanswered') }}</p>
                    </div>
                </section>

                <section>
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        {{ __('telegram.penalties.detail.message') }}
                    </h3>

                    <pre
                        class="dc-break max-h-64 overflow-y-auto whitespace-pre-wrap rounded-lg bg-gray-50 px-3 py-2.5 font-sans
                               text-[12px] leading-relaxed text-gray-700 dark:bg-white/[0.02] dark:text-gray-300"
                        x-text="detail.message_text || dash"
                    ></pre>
                </section>
            </div>
        </template>

        <x-slot:footer>
            <x-driver-check.button x-on:click="closeDetail()">
                {{ __('telegram.ui.close') }}
            </x-driver-check.button>
        </x-slot:footer>
    </x-driver-check.modal>
</div>

@endsection
