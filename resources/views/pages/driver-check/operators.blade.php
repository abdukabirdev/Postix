@extends('layouts.driver-check')

{{--
    One page, two lists: operators (/driver-check/operators) and sales
    managers (/driver-check/sales). $role is fixed by the route; the tabs on
    top are links between the two, so each has its own menu entry and URL.
--}}
@php
    $page = __("telegram.operators.pages.{$role}");
    $isSales = $role === \App\Models\Telegram\OperationUser::ROLE_SALES;
    /* "all": operators and sales managers in one list, a role badge on every row. */
    $isAll = $role === 'all';
@endphp

@section('title', $page['title'])

@section('content')

<div
    x-data="dcOperators({
        role: @js($role),
        endpoints: {
            index: @js(route('api.telegram.operators.index')),
            store: @js(route('api.telegram.operators.store')),
            base: @js(url('/api/telegram/operators')),
            penalties: @js(route('driver-check.penalties')),
        },
        translations: @js(__('telegram.operators')),
        languages: @js(__('telegram.penalty_settings.languages')),
        ui: @js(__('telegram.ui')),
    })"
>
    <x-driver-check.shell>

        {{-- ============================================================
             Header
        ============================================================= --}}
        <x-driver-check.page-header
            :title="$page['title']"
            :description="$page['description']"
        >
            <x-slot:actions>
                <x-driver-check.button x-on:click="load()" ::disabled="loading">
                    <x-driver-check.icon
                        name="refresh"
                        class="h-4 w-4"
                        ::class="loading && 'animate-spin'"
                    />
                    <span x-text="loading ? ui.loading : ui.refresh"></span>
                </x-driver-check.button>

                <x-driver-check.button variant="primary" icon="plus" x-on:click="openCreate()">
                    {{ $page['create'] }}
                </x-driver-check.button>
            </x-slot:actions>
        </x-driver-check.page-header>

        {{-- ============================================================
             Counters
        ============================================================= --}}
        <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-driver-check.stat-card
                :label="$page['total']"
                icon="users"
            >
                <span x-text="number(stats.total)">0</span>
            </x-driver-check.stat-card>

            <x-driver-check.stat-card
                :label="__('telegram.operators.stats.linked')"
                icon="link"
            >
                <span x-text="number(stats.linked)">0</span>
            </x-driver-check.stat-card>

            <x-driver-check.stat-card
                :label="__('telegram.operators.stats.dm_enabled')"
                icon="send"
            >
                <span x-text="number(stats.dm_enabled)">0</span>
            </x-driver-check.stat-card>

            <x-driver-check.stat-card
                :label="__('telegram.operators.stats.failing')"
                icon="alert"
            >
                <span
                    :class="stats.failing > 0
                        ? 'text-error-600 dark:text-error-400'
                        : 'text-gray-900 dark:text-white'"
                    x-text="number(stats.failing)"
                >0</span>
            </x-driver-check.stat-card>
        </section>

        <x-driver-check.error-alert :title="__('telegram.operators.errors.title')" />

        {{-- ============================================================
             Rows

             A table from xl up, a card list below it. One layout squeezed
             into both widths is what made the columns collide on a phone.
        ============================================================= --}}
        <x-driver-check.surface class="overflow-hidden" x-ref="listTop">
            <x-driver-check.filters
                embedded
                :search-placeholder="__('telegram.operators.search_placeholder')"
                :sort-options="$isSales
                        ? [
                            'name' => __('telegram.operators.table.operator'),
                            'penalties' => __('telegram.operators.table.penalties'),
                            'last_penalty_at' => __('telegram.operators.table.last_penalty'),
                            'dm_last_sent_at' => __('telegram.operators.table.last_sent'),
                            'created_at' => __('telegram.ui.created'),
                        ]
                        : [
                            'name' => __('telegram.operators.table.operator'),
                            'drivers' => __('telegram.operators.table.drivers'),
                            'checks' => __('telegram.operators.table.checks'),
                            'penalties' => __('telegram.operators.table.penalties'),
                            'dm_last_sent_at' => __('telegram.operators.table.last_sent'),
                            'created_at' => __('telegram.ui.created'),
                        ]"
            >
                <x-driver-check.field :label="__('telegram.operators.filters.dm')">
                    <x-driver-check.select x-model="filters.dm_enabled" x-on:change="applyFilters()">
                        <option value="">{{ __('telegram.operators.filters.dm_all') }}</option>
                        <option value="1">{{ __('telegram.operators.filters.dm_on') }}</option>
                        <option value="0">{{ __('telegram.operators.filters.dm_off') }}</option>
                    </x-driver-check.select>
                </x-driver-check.field>

                <x-driver-check.field :label="__('telegram.operators.filters.linked')">
                    <x-driver-check.select x-model="filters.linked" x-on:change="applyFilters()">
                        <option value="">{{ __('telegram.operators.filters.linked_all') }}</option>
                        <option value="1">{{ __('telegram.operators.filters.linked_yes') }}</option>
                        <option value="0">{{ __('telegram.operators.filters.linked_no') }}</option>
                    </x-driver-check.select>
                </x-driver-check.field>
            </x-driver-check.filters>

            {{-- Table (xl and up) --}}
            <div class="hidden xl:block">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1040px] table-fixed text-left">
                        <colgroup>
                            @if ($isSales)
                                <col class="w-[25%]">
                                <col class="w-[19%]">
                                <col class="w-[13%]">
                                <col class="w-[9%]">
                                <col class="w-[13%]">
                                <col class="w-[13%]">
                                <col class="w-[8%]">
                            @else
                                <col class="w-[20%]">
                                <col class="w-[17%]">
                                <col class="w-[13%]">
                                <col class="w-[11%]">
                                <col class="w-[8%]">
                                <col class="w-[8%]">
                                <col class="w-[15%]">
                                <col class="w-[8%]">
                            @endif
                        </colgroup>

                        <thead class="dc-thead border-b border-gray-200 bg-gray-25 dark:border-gray-800 dark:bg-white/[0.02]">
                            <tr class="text-[10.5px] uppercase tracking-[0.08em] text-gray-500 dark:text-gray-400">
                                <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('telegram.operators.table.operator') }}</th>
                                <th scope="col" class="px-3 py-2.5 font-semibold">{{ __('telegram.operators.table.telegram') }}</th>
                                <th scope="col" class="px-3 py-2.5 font-semibold">{{ __('telegram.operators.table.dm') }}</th>
                                @unless ($isSales)
                                    <th scope="col" class="px-3 py-2.5 text-right font-semibold">{{ __('telegram.operators.table.drivers') }}</th>
                                    <th scope="col" class="px-3 py-2.5 text-right font-semibold">{{ __('telegram.operators.table.checks') }}</th>
                                @endunless
                                <th scope="col" class="px-3 py-2.5 text-right font-semibold">{{ __('telegram.operators.table.penalties') }}</th>
                                @if ($isSales)
                                    <th scope="col" class="px-3 py-2.5 font-semibold">{{ __('telegram.operators.table.last_penalty') }}</th>
                                @endif
                                <th scope="col" class="px-3 py-2.5 font-semibold">{{ __('telegram.operators.table.last_sent') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-semibold">{{ __('telegram.operators.table.actions') }}</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <x-driver-check.skeleton-rows :cols="$isSales ? 7 : 8" />

                            <template x-for="row in rows" :key="row.id">
                                <tr class="group align-middle transition hover:bg-gray-25 dark:hover:bg-white/[0.025]">
                                    {{-- Operator --}}
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-3">
                                            <x-driver-check.avatar
                                                size="sm"
                                                x-text="initials(row.name)"
                                            />

                                            <div class="min-w-0">
                                                <p
                                                    class="truncate text-sm font-medium text-gray-900 dark:text-white"
                                                    :title="row.name"
                                                    x-text="row.name"
                                                ></p>
                                                @if ($isAll)
                                                    <span
                                                        class="mt-0.5 inline-block rounded bg-gray-100 px-1.5 py-px text-[10.5px] font-medium text-gray-600 dark:bg-white/[0.08] dark:text-gray-300"
                                                        x-text="translations.tabs[row.role] || row.role"
                                                    ></span>
                                                @endif
                                                <p
                                                    class="truncate text-[11px] text-gray-400 dark:text-gray-500"
                                                    x-show="row.name_normalized !== String(row.name || '').trim().replace(/\s+/g, ' ').toUpperCase()"
                                                    :title="row.name_normalized"
                                                    x-text="row.name_normalized"
                                                ></p>
                                            </div>
                                        </div>
                                    </td>

                                    {{-- Telegram --}}
                                    <td class="px-3 py-3">
                                        <div class="flex items-center gap-1">
                                            <a
                                                :href="telegramUrl(row.telegram_username)"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="truncate text-[13px] transition"
                                                :class="row.telegram_username
                                                    ? 'text-brand-600 hover:underline dark:text-brand-400'
                                                    : 'text-gray-400 dark:text-gray-500'"
                                                x-text="handle(row.telegram_username) || translations.table.no_username"
                                            ></a>

                                            <template x-if="row.telegram_username">
                                                <x-driver-check.copy-button value="row.telegram_username" />
                                            </template>
                                        </div>

                                        <div class="flex items-center gap-1">
                                            <p
                                                class="truncate text-[11px] tabular-nums"
                                                :class="row.telegram_id
                                                    ? 'text-gray-500 dark:text-gray-400'
                                                    : 'text-gray-400 dark:text-gray-500'"
                                                x-text="row.telegram_id ? 'ID ' + row.telegram_id : translations.table.no_id"
                                            ></p>

                                            <template x-if="row.telegram_id">
                                                <x-driver-check.copy-button value="row.telegram_id" />
                                            </template>
                                        </div>
                                    </td>

                                    {{-- Delivery (dot + label), then how we write: language, respect, address --}}
                                    <td class="px-3 py-3">
                                        <p class="inline-flex items-center gap-1.5 text-[13px] font-medium text-gray-800 dark:text-gray-100">
                                            <span class="h-1.5 w-1.5 rounded-full" :class="deliveryDot(row)"></span>
                                            <span x-text="deliveryLabel(row)"></span>
                                        </p>
                                        <div class="mt-1 flex flex-wrap items-center gap-1">
                                            <span
                                                class="rounded bg-gray-100 px-1.5 py-px font-mono text-[10px] font-semibold text-gray-600 dark:bg-white/[0.06] dark:text-gray-300"
                                                :title="translations.form.language"
                                                x-text="languageOf(row)"
                                            ></span>
                                            <span
                                                x-show="row.respectful"
                                                x-cloak
                                                class="rounded bg-brand-50 px-1.5 py-px text-[10px] font-medium text-brand-700 dark:bg-brand-500/10 dark:text-brand-300"
                                                x-text="translations.table.respectful"
                                            ></span>
                                            <span
                                                x-show="switchesOff(row).length"
                                                x-cloak
                                                class="rounded bg-warning-50 px-1.5 py-px text-[10px] font-medium text-warning-700 dark:bg-warning-500/10 dark:text-warning-400"
                                                :title="switchesOff(row).join(', ')"
                                                x-text="translations.form.switches_off.replace(':n', switchesOff(row).length)"
                                            ></span>
                                            <span
                                                x-show="addressOf(row)"
                                                x-cloak
                                                class="max-w-[9rem] truncate rounded bg-gray-100 px-1.5 py-px text-[10px] font-medium text-gray-600 dark:bg-white/[0.06] dark:text-gray-300"
                                                :title="translations.form.address_short"
                                                x-text="addressOf(row)"
                                            ></span>
                                        </div>
                                    </td>

                                    @unless ($isSales)
                                        <td
                                            class="px-3 py-3 text-right text-sm tabular-nums text-gray-700 dark:text-gray-300"
                                            x-text="number(row.drivers_count)"
                                        ></td>

                                        <td
                                            class="px-3 py-3 text-right text-sm tabular-nums text-gray-700 dark:text-gray-300"
                                            x-text="number(row.checks_count)"
                                        ></td>
                                    @endunless

                                    {{-- Penalties: a link to the penalties page, narrowed to this person --}}
                                    <td class="px-3 py-3 text-right text-sm tabular-nums">
                                        <a
                                            x-show="row.penalties_count > 0"
                                            :href="penaltiesUrl(row)"
                                            class="font-medium text-brand-600 hover:underline dark:text-brand-400"
                                            :title="translations.table.open_penalties"
                                            x-text="number(row.penalties_count)"
                                        ></a>
                                        <span
                                            x-show="row.penalties_count === 0"
                                            class="text-gray-400 dark:text-gray-500"
                                        >0</span>
                                    </td>

                                    @if ($isSales)
                                        <td class="px-3 py-3">
                                            <p
                                                class="text-[13px] text-gray-700 dark:text-gray-300"
                                                :title="row.last_penalty_at ? date(row.last_penalty_at) : ''"
                                                x-text="row.last_penalty_at
                                                    ? relative(row.last_penalty_at)
                                                    : translations.table.no_penalties"
                                            ></p>
                                        </td>
                                    @endif

                                    {{-- Last delivery --}}
                                    <td class="px-3 py-3">
                                        <p
                                            class="text-[13px] text-gray-700 dark:text-gray-300"
                                            :title="row.dm_last_sent_at ? date(row.dm_last_sent_at) : ''"
                                            x-text="row.dm_last_sent_at
                                                ? relative(row.dm_last_sent_at)
                                                : translations.table.never"
                                        ></p>

                                        <p
                                            x-show="row.dm_last_error"
                                            x-cloak
                                            class="mt-0.5 line-clamp-2 text-[11px] text-error-600 dark:text-error-400"
                                            :title="row.dm_last_error"
                                            x-text="row.dm_last_error"
                                        ></p>
                                    </td>

                                    {{-- Actions --}}
                                    <td class="px-4 py-3 text-right">
                                        <x-driver-check.row-action icon="pencil" :label="__('telegram.operators.table.edit')" x-on:click="openEdit(row)" />
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Cards (below xl) --}}
            <div class="divide-y divide-gray-100 xl:hidden dark:divide-gray-800">
                <x-driver-check.skeleton-cards />

                <template x-for="row in rows" :key="'card-' + row.id">
                    <article class="flex flex-col gap-3 p-4">
                        <div class="flex items-start gap-3">
                            <x-driver-check.avatar x-text="initials(row.name)" />

                            <div class="min-w-0 flex-1">
                                <p class="dc-break text-sm font-semibold text-gray-900 dark:text-white" x-text="row.name"></p>
                                @if ($isAll)
                                    <span
                                        class="inline-block rounded bg-gray-100 px-1.5 py-px text-[10.5px] font-medium text-gray-600 dark:bg-white/[0.08] dark:text-gray-300"
                                        x-text="translations.tabs[row.role] || row.role"
                                    ></span>
                                @endif
                                <p
                                    x-show="row.name_normalized !== String(row.name || '').trim().replace(/\s+/g, ' ').toUpperCase()"
                                    class="dc-break text-[11px] text-gray-400 dark:text-gray-500"
                                    x-text="row.name_normalized"
                                ></p>
                            </div>

                            <p class="inline-flex shrink-0 items-center gap-1.5 pt-0.5 text-[12px] font-medium text-gray-700 dark:text-gray-200">
                                <span class="h-1.5 w-1.5 rounded-full" :class="deliveryDot(row)"></span>
                                <span x-text="deliveryLabel(row)"></span>
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-1">
                            <span
                                class="rounded bg-gray-100 px-1.5 py-px font-mono text-[10px] font-semibold text-gray-600 dark:bg-white/[0.06] dark:text-gray-300"
                                x-text="languageOf(row)"
                            ></span>
                            <span
                                x-show="row.respectful"
                                x-cloak
                                class="rounded bg-brand-50 px-1.5 py-px text-[10px] font-medium text-brand-700 dark:bg-brand-500/10 dark:text-brand-300"
                                x-text="translations.table.respectful"
                            ></span>
                            <span
                                x-show="switchesOff(row).length"
                                x-cloak
                                class="rounded bg-warning-50 px-1.5 py-px text-[10px] font-medium text-warning-700 dark:bg-warning-500/10 dark:text-warning-400"
                                :title="switchesOff(row).join(', ')"
                                x-text="translations.form.switches_off.replace(':n', switchesOff(row).length)"
                            ></span>
                            <span
                                x-show="addressOf(row)"
                                x-cloak
                                class="max-w-[9rem] truncate rounded bg-gray-100 px-1.5 py-px text-[10px] font-medium text-gray-600 dark:bg-white/[0.06] dark:text-gray-300"
                                x-text="addressOf(row)"
                            ></span>
                        </div>

                        <dl class="grid grid-cols-2 gap-x-3 gap-y-2.5 rounded-lg bg-gray-25 p-3 ring-1 ring-inset ring-gray-100 dark:bg-white/[0.02] dark:ring-gray-800">
                            <x-driver-check.kv :label="__('telegram.operators.table.telegram')" class="col-span-2">
                                <a
                                    :href="telegramUrl(row.telegram_username)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    :class="row.telegram_username && 'text-brand-600 dark:text-brand-400'"
                                    x-text="handle(row.telegram_username) || translations.table.no_username"
                                ></a>
                                <span
                                    class="block text-[11px] tabular-nums text-gray-500 dark:text-gray-400"
                                    x-text="row.telegram_id ? 'ID ' + row.telegram_id : translations.table.no_id"
                                ></span>
                            </x-driver-check.kv>

                            @unless ($isSales)
                                <x-driver-check.kv :label="__('telegram.operators.table.drivers')">
                                    <span class="tabular-nums" x-text="number(row.drivers_count)"></span>
                                </x-driver-check.kv>

                                <x-driver-check.kv :label="__('telegram.operators.table.checks')">
                                    <span class="tabular-nums" x-text="number(row.checks_count)"></span>
                                </x-driver-check.kv>
                            @endunless

                            <x-driver-check.kv :label="__('telegram.operators.table.penalties')">
                                <a
                                    x-show="row.penalties_count > 0"
                                    :href="penaltiesUrl(row)"
                                    class="tabular-nums font-medium text-brand-600 dark:text-brand-400"
                                    x-text="number(row.penalties_count)"
                                ></a>
                                <span x-show="row.penalties_count === 0" class="tabular-nums">0</span>
                            </x-driver-check.kv>

                            @if ($isSales)
                                <x-driver-check.kv :label="__('telegram.operators.table.last_penalty')">
                                    <span x-text="row.last_penalty_at
                                        ? relative(row.last_penalty_at)
                                        : translations.table.no_penalties"></span>
                                </x-driver-check.kv>
                            @endif

                            <x-driver-check.kv :label="__('telegram.operators.table.last_sent')" class="col-span-2">
                                <span x-text="row.dm_last_sent_at
                                    ? date(row.dm_last_sent_at)
                                    : translations.table.never"></span>
                            </x-driver-check.kv>
                        </dl>

                        <p
                            x-show="row.dm_last_error"
                            x-cloak
                            class="dc-break rounded-lg bg-error-50 px-3 py-2 text-[11px] leading-snug text-error-600
                                   dark:bg-error-500/10 dark:text-error-400"
                        >
                            <span class="font-semibold">{{ __('telegram.operators.errors.dm_last_error') }}:</span>
                            <span x-text="row.dm_last_error"></span>
                        </p>

                        <x-driver-check.button class="w-full" x-on:click="openEdit(row)">
                            {{ __('telegram.operators.table.edit') }}
                        </x-driver-check.button>
                    </article>
                </template>
            </div>

            <x-driver-check.empty-state
                icon="operator"
                :title="$page['empty_title']"
                :description="$page['empty_description']"
            >
                <div class="mt-3">
                    <x-driver-check.button variant="primary" size="sm" icon="plus" x-on:click="openCreate()">
                        {{ $page['create'] }}
                    </x-driver-check.button>
                </div>
            </x-driver-check.empty-state>

            <x-driver-check.pagination />
        </x-driver-check.surface>
    </x-driver-check.shell>

    {{-- ================================================================
         Create / edit dialog
    ================================================================= --}}
    <x-driver-check.modal
        open="formOpen"
        close="closeForm()"
        size="sm:max-w-xl"
    >
        <x-slot:heading>
            <span x-text="form.id ? @js($page['edit_title']) : @js($page['create'])"></span>
        </x-slot:heading>

        <form id="operator-form" x-on:submit.prevent="save()" class="-mx-4 -my-4 flex flex-col sm:-mx-5">

            {{-- Section: who --}}
            <section class="flex flex-col gap-4 px-4 py-4 sm:px-5">
                <h3 class="dc-eyebrow">{{ __('telegram.operators.table.operator') }}</h3>

                <x-driver-check.field
                    :label="__('telegram.operators.form.name')"
                    :hint="$page['name_hint']"
                    for="operator-name"
                >
                    <x-driver-check.input
                        id="operator-name"
                        x-ref="firstField"
                        x-model="form.name"
                        required
                        autocomplete="off"
                        ::data-invalid="!!(fieldError('name') || fieldError('name_normalized'))"
                    />

                    <x-slot:error>
                        <p
                            x-show="form.name"
                            x-cloak
                            class="dc-break mt-1.5 rounded-md bg-gray-50 px-2.5 py-1.5 text-[11px]
                                   text-gray-500 dark:bg-white/[0.03] dark:text-gray-400"
                        >
                            {{ __('telegram.operators.form.name_normalized') }}:
                            <span class="font-mono font-medium text-gray-700 dark:text-gray-200" x-text="normalizedPreview()"></span>
                        </p>

                        <p
                            x-show="fieldError('name') || fieldError('name_normalized')"
                            x-cloak
                            class="mt-1 text-[11px] font-medium text-error-600 dark:text-error-400"
                            x-text="fieldError('name') || fieldError('name_normalized')"
                        ></p>
                    </x-slot:error>
                </x-driver-check.field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-driver-check.field
                        :label="__('telegram.operators.form.telegram_username')"
                        :hint="__('telegram.operators.form.telegram_username_hint')"
                        for="operator-username"
                    >
                        <div class="relative">
                            <span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-gray-400">&#64;</span>

                            <x-driver-check.input
                                id="operator-username"
                                x-model="form.telegram_username"
                                placeholder="username"
                                autocomplete="off"
                                autocapitalize="off"
                                spellcheck="false"
                                class="pl-8"
                                ::data-invalid="!!fieldError('telegram_username')"
                            />
                        </div>

                        <x-slot:error>
                    <p
                        x-show="fieldError('telegram_username')"
                        x-cloak
                        class="mt-1 text-[11px] font-medium text-error-600 dark:text-error-400"
                        x-text="fieldError('telegram_username')"
                    ></p>
                </x-slot:error>
                    </x-driver-check.field>

                    <x-driver-check.field
                        :label="__('telegram.operators.form.telegram_id')"
                        :hint="__('telegram.operators.form.telegram_id_hint')"
                        for="operator-telegram-id"
                    >
                        <x-driver-check.input
                            id="operator-telegram-id"
                            type="number"
                            inputmode="numeric"
                            min="1"
                            x-model="form.telegram_id"
                            placeholder="123456789"
                            ::data-invalid="!!fieldError('telegram_id')"
                        />

                        <x-slot:error>
                    <p
                        x-show="fieldError('telegram_id')"
                        x-cloak
                        class="mt-1 text-[11px] font-medium text-error-600 dark:text-error-400"
                        x-text="fieldError('telegram_id')"
                    ></p>
                </x-slot:error>
                    </x-driver-check.field>
                </div>

                {{-- Operation or Sales --}}
                <x-driver-check.field
                    :label="__('telegram.operators.form.role')"
                    :hint="__('telegram.operators.form.role_hint')"
                    for="operator-role"
                >
                    <x-driver-check.select id="operator-role" x-model="form.role">
                        <option value="operation">{{ __('telegram.operators.roles.operation') }}</option>
                        <option value="sales">{{ __('telegram.operators.roles.sales') }}</option>
                    </x-driver-check.select>

                    <x-slot:error>
                    <p
                        x-show="fieldError('role')"
                        x-cloak
                        class="mt-1 text-[11px] font-medium text-error-600 dark:text-error-400"
                        x-text="fieldError('role')"
                    ></p>
                </x-slot:error>
                </x-driver-check.field>
            </section>

            {{-- Section: how penalty comments are written to this person --}}
            <section class="flex flex-col gap-4 border-t border-gray-100 px-4 py-4 sm:px-5 dark:border-gray-800">
                <h3 class="dc-eyebrow">{{ __('telegram.operators.form.language') }}</h3>

                <x-driver-check.field
                    :label="__('telegram.operators.form.language')"
                    :hint="__('telegram.operators.form.language_hint')"
                    for="operator-language"
                >
                    <x-driver-check.select id="operator-language" x-model="form.language">
                        <option value="" x-text="languageAutoLabel()"></option>
                        @foreach (\App\Models\Telegram\OperationUser::LANGUAGES as $language)
                            <option value="{{ $language }}">{{ __("telegram.penalty_settings.languages.{$language}") }}</option>
                        @endforeach
                    </x-driver-check.select>

                    <x-slot:error>
                    <p
                        x-show="fieldError('language')"
                        x-cloak
                        class="mt-1 text-[11px] font-medium text-error-600 dark:text-error-400"
                        x-text="fieldError('language')"
                    ></p>
                </x-slot:error>
                </x-driver-check.field>

                {{-- How to call the person: "Ali aka", "jigar" - {address} in the texts --}}
                <div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach (\App\Models\Telegram\OperationUser::LANGUAGES as $language)
                            <x-driver-check.field
                                :label="__('telegram.operators.form.address', ['language' => __('telegram.penalty_settings.languages.' . $language)])"
                                for="operator-address-{{ $language }}"
                            >
                                <x-driver-check.input
                                    id="operator-address-{{ $language }}"
                                    x-model="form.address.{{ $language }}"
                                    maxlength="60"
                                    autocomplete="off"
                                    placeholder="{{ __('telegram.operators.form.address_placeholder.' . $language) }}"
                                    ::data-invalid="!!fieldError('address.{{ $language }}')"
                                />

                                <x-slot:error>
                                    <p
                                        x-show="fieldError('address.{{ $language }}')"
                                        x-cloak
                                        class="mt-1 text-[11px] font-medium text-error-600 dark:text-error-400"
                                        x-text="fieldError('address.{{ $language }}')"
                                    ></p>
                                </x-slot:error>
                            </x-driver-check.field>
                        @endforeach
                    </div>

                    <p class="mt-1.5 text-[11.5px] leading-snug text-gray-400 dark:text-gray-500">
                        {{ __('telegram.operators.form.address_hint') }}
                    </p>
                </div>

                {{-- Respectful form of address --}}
                <div class="dc-surface overflow-hidden rounded-xl">
                    <label class="dc-tap flex cursor-pointer items-center justify-between gap-4 px-3.5 py-3 transition hover:bg-gray-25 dark:hover:bg-white/[0.025]">
                        <span class="min-w-0">
                            <span class="block text-[13px] font-medium text-gray-900 dark:text-white">
                                {{ __('telegram.operators.form.respectful') }}
                            </span>
                            <span class="mt-0.5 block text-[11.5px] leading-snug text-gray-500 dark:text-gray-400">
                                {{ __('telegram.operators.form.respectful_hint') }}
                            </span>
                        </span>
                        <input type="checkbox" x-model="form.respectful" class="peer sr-only">
                        <span
                        class="relative h-5 w-9 shrink-0 rounded-full bg-gray-200 transition-colors
                               after:absolute after:left-0.5 after:top-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white
                               after:shadow-sm after:transition-transform peer-checked:bg-brand-500 peer-checked:after:translate-x-4
                               peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/40 dark:bg-white/[0.14]"
                    ></span>
                    </label>
                </div>
            </section>

            {{-- Section: the private chat. The master switch, then what goes there one by one
                 (OperationUser::MESSAGE_SWITCHES) --}}
            <section class="flex flex-col gap-3 border-t border-gray-100 px-4 py-4 sm:px-5 dark:border-gray-800">
                <h3 class="dc-eyebrow">{{ __('telegram.operators.form.dm_enabled') }}</h3>

                <div class="dc-surface overflow-hidden rounded-xl">
                    <label class="dc-tap flex cursor-pointer items-center justify-between gap-4 px-3.5 py-3 transition hover:bg-gray-25 dark:hover:bg-white/[0.025]">
                        <span class="min-w-0">
                            <span class="block text-[13px] font-medium text-gray-900 dark:text-white">
                                {{ __('telegram.operators.form.dm_enabled') }}
                            </span>
                            <span class="mt-0.5 block text-[11.5px] leading-snug text-gray-500 dark:text-gray-400">
                                {{ $page['dm_enabled_hint'] }}
                            </span>
                        </span>
                        <input type="checkbox" x-model="form.dm_enabled" class="peer sr-only">
                        <span
                        class="relative h-5 w-9 shrink-0 rounded-full bg-gray-200 transition-colors
                               after:absolute after:left-0.5 after:top-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white
                               after:shadow-sm after:transition-transform peer-checked:bg-brand-500 peer-checked:after:translate-x-4
                               peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/40 dark:bg-white/[0.14]"
                    ></span>
                    </label>

                    <div x-show="form.dm_enabled" x-collapse>
                        <div class="border-t border-gray-100 bg-gray-25 px-3.5 py-2.5 dark:border-gray-800 dark:bg-white/[0.015]">
                            <p class="text-[12px] font-semibold text-gray-600 dark:text-gray-400">
                                {{ __('telegram.operators.form.switches') }}
                            </p>
                            <p class="mt-0.5 text-[11.5px] leading-snug text-gray-500 dark:text-gray-400">
                                {{ __('telegram.operators.form.switches_hint') }}
                            </p>
                        </div>

                        <div class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-gray-800 dark:border-gray-800">
                            @foreach (\App\Models\Telegram\OperationUser::MESSAGE_SWITCHES as $switch)
                                <label class="dc-tap flex cursor-pointer items-center justify-between gap-4 px-3.5 py-2.5 transition hover:bg-gray-25 dark:hover:bg-white/[0.025]">
                                    <span class="min-w-0">
                                        <span class="block text-[13px] font-medium text-gray-800 dark:text-gray-200">
                                            {{ __('telegram.operators.form.switch.' . $switch . '.label') }}
                                        </span>
                                        <span class="block text-[11.5px] leading-snug text-gray-500 dark:text-gray-400">
                                            {{ __('telegram.operators.form.switch.' . $switch . '.hint') }}
                                        </span>
                                    </span>
                                    <input type="checkbox" x-model="form.switches.{{ $switch }}" class="peer sr-only">
                                    <span
                        class="relative h-5 w-9 shrink-0 rounded-full bg-gray-200 transition-colors
                               after:absolute after:left-0.5 after:top-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white
                               after:shadow-sm after:transition-transform peer-checked:bg-brand-500 peer-checked:after:translate-x-4
                               peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/40 dark:bg-white/[0.14]"
                    ></span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>
            </section>

            {{-- Errors and delete. Delete: only a row nothing points at yet --}}
            <div
                x-show="formError || (form.id && form.deletable)"
                x-cloak
                class="flex flex-col gap-3 border-t border-gray-100 px-4 py-4 sm:px-5 dark:border-gray-800"
            >
                <p
                    x-show="formError"
                    x-cloak
                    class="dc-break rounded-lg bg-error-50 px-3 py-2.5 text-xs text-error-600
                           dark:bg-error-500/10 dark:text-error-400"
                    x-text="formError"
                ></p>

                <div
                    x-show="form.id && form.deletable"
                    x-cloak
                    class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
                >
                    <p
                        class="text-[11.5px] leading-snug text-gray-500 dark:text-gray-400"
                        x-text="deleteArmed ? translations.confirm.delete_text : translations.form.delete_hint"
                    ></p>

                    <div class="flex shrink-0 gap-2">
                        <x-driver-check.button
                            size="sm"
                            variant="ghost"
                            x-show="deleteArmed"
                            x-cloak
                            x-on:click="deleteArmed = false"
                            ::disabled="saving"
                        >
                            {{ __('telegram.operators.confirm.cancel') }}
                        </x-driver-check.button>

                        <x-driver-check.button
                            size="sm"
                            variant="danger"
                            x-on:click="remove()"
                            ::disabled="saving"
                        >
                            <span x-text="deleteArmed ? translations.confirm.delete : translations.form.delete"></span>
                        </x-driver-check.button>
                    </div>
                </div>
            </div>
        </form>

        <x-slot:footer>
            <x-driver-check.button x-on:click="closeForm()" ::disabled="saving">
                {{ __('telegram.operators.form.cancel') }}
            </x-driver-check.button>

            <x-driver-check.button
                variant="primary"
                type="submit"
                form="operator-form"
                ::disabled="saving"
            >
                <x-driver-check.icon
                    name="refresh"
                    class="h-4 w-4 animate-spin"
                    x-show="saving"
                    x-cloak
                />
                <span x-text="saving ? translations.form.saving : translations.form.save"></span>
            </x-driver-check.button>
        </x-slot:footer>
    </x-driver-check.modal>
</div>

@endsection
