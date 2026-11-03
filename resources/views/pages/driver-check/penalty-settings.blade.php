@extends('layouts.driver-check')

@section('title', $part === 'texts' ? __('driver_check.tree.pages.penalty_texts') : __('driver_check.tree.pages.penalty_sending'))

@php
    $placeholders = collect(\App\Application\Telegram\Services\ClientCheckRules::PLACEHOLDERS)
        ->map(fn (string $name) => ['name' => $name, 'hint' => __("telegram.penalty_settings.placeholders.{$name}")])
        ->values();
@endphp

@section('content')

<div
    x-data="dcPenaltySettings({
        endpoints: {
            rules: @js(route('api.telegram.client-checks.rules')),
            settings: @js(route('api.telegram.client-checks.settings.update')),
        },
        settings: @js([
            'enabled' => \App\Models\Telegram\TelegramSetting::clientChecksEnabled(),
            'comments_enabled' => \App\Models\Telegram\TelegramSetting::clientCheckCommentsEnabled(),
            'operation_enabled' => \App\Models\Telegram\TelegramSetting::clientChecksEnabledFor('operation'),
            'sales_enabled' => \App\Models\Telegram\TelegramSetting::clientChecksEnabledFor('sales'),
        ]),
        settingsSaved: @js(__('telegram.penalties.settings.saved')),
        maxLevels: @js(\App\Application\Telegram\Services\ClientCheckRules::MAX_LEVELS),
        uzCyrillic: @js(\App\Application\Telegram\Services\UzbekScript::enabled()),
        placeholders: @js($placeholders),
        translations: @js(__('telegram.penalty_settings')),
        ui: @js(__('telegram.ui')),
    })"
    class="pb-24"
>
    <x-driver-check.shell>

        {{-- ============================================================
             Header
        ============================================================= --}}
        <x-driver-check.page-header
            :title="$part === 'texts' ? __('driver_check.tree.pages.penalty_texts') : __('driver_check.tree.pages.penalty_sending')"
            :description="$part === 'texts' ? __('telegram.penalty_settings.description') : __('driver_check.pages.penalty_sending')"
        >
            <x-slot:actions>
                <span
                    x-show="!loading"
                    x-cloak
                    class="hidden h-7 items-center gap-1.5 rounded-md px-2.5 text-[12px] font-medium sm:inline-flex"
                    :class="customised
                        ? 'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400'
                        : 'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300'"
                    x-text="customised ? translations.actions.customised : translations.actions.default"
                ></span>

                <x-driver-check.button icon="arrow-left" :href="route('driver-check.penalties')" class="col-span-2 whitespace-nowrap sm:col-span-1">
                    {{ __('telegram.penalty_settings.back') }}
                </x-driver-check.button>
            </x-slot:actions>
        </x-driver-check.page-header>

        <div
            x-show="loadError"
            x-cloak
            class="dc-break rounded-xl border border-error-200 bg-error-50 px-4 py-2.5 text-[13px] dark:border-error-500/30 text-error-700 dark:bg-error-500/10 dark:text-error-400"
        >
            <span x-text="loadError"></span>
            <button type="button" class="ml-2 font-semibold underline" x-on:click="load()">{{ __('telegram.ui.refresh') }}</button>
        </div>

        @if ($part === 'texts')
        {{-- What to write: the ladder of texts, how one is chosen, the placeholders --}}
        <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_320px]">

            {{-- ========================================================
                 The ladder: one level per penalty number
            ========================================================= --}}
            <section class="flex min-w-0 flex-col gap-3">
                {{-- Whose ladder: operators and sales are edited apart --}}
                <div class="dc-surface flex flex-col gap-2 rounded-2xl p-2 sm:flex-row sm:items-center sm:justify-between">
                    <div class="dc-chips inline-flex gap-0.5 rounded-lg bg-gray-100 p-0.5 dark:bg-white/[0.06]" role="tablist">
                        <template x-for="r in roles" :key="'role-' + r">
                            <button
                                type="button"
                                role="tab"
                                x-on:click="setRole(r)"
                                :aria-selected="role === r"
                                :class="role === r
                                    ? 'bg-white text-gray-950 shadow-sm dark:bg-gray-700 dark:text-white'
                                    : 'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white'"
                                class="dc-tap relative inline-flex h-10 flex-1 items-center justify-center gap-2 rounded-md px-4 text-[13px] font-medium transition outline-none focus-visible:ring-2 focus-visible:ring-brand-500/50 sm:h-8 sm:flex-none"
                            >
                                <span x-text="translations.roles[r]"></span>
                                <span
                                    x-show="roleHasErrors(r)"
                                    x-cloak
                                    class="h-1.5 w-1.5 rounded-full bg-error-500"
                                ></span>
                            </button>
                        </template>
                    </div>

                    <div class="flex items-center gap-2 px-1 sm:px-0">
                        <p class="hidden text-[12px] text-gray-500 lg:block dark:text-gray-400" x-text="translations.roles_hint"></p>

                        <x-driver-check.button size="sm" variant="ghost" icon="copy" x-on:click="copyFromOther()" ::disabled="loading">
                            <span x-text="(copyArmed ? translations.copy_confirm : translations.copy_from).replace(':role', translations.roles[otherRole()])"></span>
                        </x-driver-check.button>
                    </div>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div class="min-w-0">
                        <h2 class="text-[15px] font-semibold text-gray-950 dark:text-white">
                            {{ __('telegram.penalty_settings.sections.levels') }}
                        </h2>
                        <p class="mt-0.5 max-w-2xl text-[13px] leading-relaxed text-gray-500 dark:text-gray-400">
                            {{ __('telegram.penalty_settings.sections.levels_hint') }}
                        </p>
                    </div>

                    {{-- The language being edited --}}
                    <div
                        class="dc-chips inline-flex shrink-0 gap-0.5 self-start rounded-lg bg-gray-100 p-0.5 sm:self-auto dark:bg-white/[0.06]"
                        role="group"
                        aria-label="{{ __('telegram.penalty_settings.sections.language') }}"
                    >
                        <template x-for="lang in languages" :key="'lang-' + lang">
                            <button
                                type="button"
                                x-on:click="language = lang"
                                :aria-pressed="language === lang"
                                :class="language === lang
                                    ? 'bg-white text-gray-950 shadow-sm dark:bg-gray-700 dark:text-white'
                                    : 'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white'"
                                class="dc-tap inline-flex h-10 items-center gap-2 rounded-md px-3.5 text-[13px] font-medium transition outline-none focus-visible:ring-2 focus-visible:ring-brand-500/50 sm:h-8"
                            >
                                <span class="font-mono text-[10px] font-semibold opacity-60" x-text="lang.toUpperCase()"></span>
                                <span x-text="translations.languages[lang]"></span>
                            </button>
                        </template>
                    </div>
                </div>

                {{-- Loading --}}
                <template x-if="loading">
                    <div class="flex flex-col gap-3">
                        <template x-for="i in 4" :key="'sk-' + i">
                            <div class="dc-skeleton h-28 rounded-2xl"></div>
                        </template>
                    </div>
                </template>

                <template x-for="(level, index) in ladder()" :key="level.key">
                    <article
                        class="relative overflow-hidden rounded-2xl border bg-white transition dark:bg-white/[0.03]"
                        :class="levelErrors(index).length
                            ? 'border-error-300 dark:border-error-500/40'
                            : 'border-gray-200 dark:border-gray-800'"
                    >
                        {{-- How harsh: a coloured rail down the left edge --}}
                        <span class="absolute inset-y-0 left-0 w-[3px]" :class="tone(index).rail"></span>

                        <div class="flex flex-col gap-3 p-3.5 pl-5 sm:p-4 sm:pl-5">

                            {{-- Head: always visible --}}
                            <div class="flex flex-wrap items-center gap-2.5">
                                <span
                                    class="inline-flex h-6 shrink-0 items-center rounded-md px-2 text-[11.5px] font-semibold"
                                    :class="tone(index).badge"
                                    x-text="summary(index)"
                                ></span>

                                <input
                                    type="text"
                                    x-model="level.name"
                                    maxlength="60"
                                    :placeholder="levelTitle(index)"
                                    :aria-label="translations.level.name"
                                    class="h-8 min-w-0 flex-1 rounded-lg border border-transparent bg-transparent px-2 text-sm font-semibold
                                           text-gray-900 outline-none transition placeholder:text-gray-400 hover:border-gray-200
                                           focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10
                                           dark:text-white dark:placeholder:text-gray-500 dark:hover:border-gray-700"
                                >

                                <span
                                    class="hidden shrink-0 rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[11px] font-medium text-gray-600
                                           sm:inline dark:bg-white/[0.06] dark:text-gray-300"
                                    x-text="counts(index)"
                                ></span>

                                <button
                                    type="button"
                                    x-show="index > 0"
                                    x-on:click="removeLevel(index)"
                                    class="dc-tap inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg sm:h-8 sm:w-8 text-gray-400
                                           transition hover:bg-error-50 hover:text-error-600 dark:hover:bg-error-500/10 dark:hover:text-error-400"
                                    :title="translations.level.remove"
                                    :aria-label="translations.level.remove"
                                >
                                    <x-driver-check.icon name="close" class="h-4 w-4" />
                                </button>

                                <button
                                    type="button"
                                    x-on:click="toggleLevel(index)"
                                    :aria-expanded="level.open"
                                    class="dc-tap inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg sm:h-8 sm:w-8 text-gray-500
                                           transition hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/[0.06]"
                                >
                                    <x-driver-check.icon
                                        name="chevron-down"
                                        class="h-4 w-4 transition-transform duration-200"
                                        ::class="level.open && 'rotate-180'"
                                    />
                                </button>
                            </div>

                            {{-- What goes out at this level, for this role --}}
                            <div class="flex flex-wrap items-center gap-1" role="radiogroup" :aria-label="translations.mode_hint">
                                <template x-for="m in modes" :key="'mode-' + m">
                                    <button
                                        type="button"
                                        role="radio"
                                        x-on:click="setMode(index, m)"
                                        :aria-checked="level.mode === m"
                                        :class="level.mode === m
                                            ? modeClass(m) + ' ring-1 ring-inset ring-current/20'
                                            : 'text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/[0.06]'"
                                        class="dc-tap inline-flex h-8 items-center gap-1.5 rounded-md px-2.5 text-[12px] font-medium transition"
                                        x-text="translations.modes[m]"
                                    ></button>
                                </template>
                            </div>

                            {{-- Folded: what each tone says, in the language being edited --}}
                            <button
                                type="button"
                                x-show="!level.open && level.mode !== 'off'"
                                x-on:click="toggleLevel(index)"
                                class="grid gap-1.5 text-left sm:grid-cols-2"
                                :class="level.mode === 'forward' && 'opacity-50'"
                            >
                                <template x-for="t in tones" :key="'fold-' + t">
                                    <span class="flex min-w-0 items-start gap-2 rounded-md bg-gray-25 px-2.5 py-1.5 ring-1 ring-inset ring-gray-100 dark:bg-white/[0.03] dark:ring-gray-800">
                                        <span
                                            class="mt-px shrink-0 text-[10px] font-semibold uppercase tracking-wider"
                                            :class="t === 'respectful' ? 'text-brand-500' : 'text-gray-400 dark:text-gray-500'"
                                            x-text="translations.tones[t]"
                                        ></span>
                                        {{-- Already reduced to Telegram's tags by telegramHtml(). --}}
                                        <span
                                            class="line-clamp-1 text-[12px]"
                                            :class="firstPhrase(index, t) ? 'text-gray-700 dark:text-gray-300' : 'italic text-gray-400 dark:text-gray-500'"
                                            x-html="firstPhrase(index, t) || emptyText(index, t)"
                                        ></span>
                                    </span>
                                </template>
                            </button>

                            {{-- Open: the number it starts at, and both tones side by side --}}
                            <div x-show="level.open" x-collapse>
                                <div class="flex flex-col gap-4 border-t border-gray-100 pt-3.5 dark:border-gray-800">

                                    <label x-show="index > 0" class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                        <span class="text-[13px] font-medium text-gray-700 dark:text-gray-300" x-text="translations.level.from"></span>
                                        <span class="inline-flex items-center gap-1">
                                            <span class="text-sm text-gray-400">№</span>
                                            <input
                                                type="number"
                                                min="2"
                                                inputmode="numeric"
                                                x-model="level.from"
                                                class="h-10 w-20 rounded-lg border bg-white px-2.5 text-sm tabular-nums sm:h-9 text-gray-900 outline-none transition
                                                       focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 dark:bg-gray-900 dark:text-white"
                                                :class="fieldError('roles.' + role + '.levels.' + index + '.from')
                                                    ? 'border-error-400 dark:border-error-500/60'
                                                    : 'border-gray-300 dark:border-gray-700'"
                                            >
                                        </span>
                                        <span class="text-[11px] text-gray-400 dark:text-gray-500" x-text="translations.level.from_hint"></span>
                                    </label>

                                    <div class="grid gap-4 md:grid-cols-2">
                                        <template x-for="t in tones" :key="'col-' + t">
                                            <div
                                                class="flex min-w-0 flex-col gap-2 rounded-lg p-2.5"
                                                :class="t === 'respectful'
                                                    ? 'bg-gray-25 ring-1 ring-inset ring-gray-200 dark:bg-white/[0.02] dark:ring-gray-700'
                                                    : 'bg-gray-50 ring-1 ring-inset ring-gray-100 dark:bg-white/[0.02] dark:ring-gray-800'"
                                            >
                                                <div class="flex items-baseline justify-between gap-2">
                                                    <p
                                                        class="dc-eyebrow"
                                                        :class="t === 'respectful' ? 'text-gray-950 dark:text-white' : 'text-gray-700 dark:text-gray-200'"
                                                        x-text="translations.tones[t]"
                                                    ></p>
                                                    <p class="truncate text-[11px] text-gray-400 dark:text-gray-500" x-text="translations.tones[t + '_hint']"></p>
                                                </div>

                                                <p
                                                    x-show="phrases(index, t).length === 0"
                                                    class="rounded-lg border border-dashed border-gray-300 px-2.5 py-2 text-[12px] italic text-gray-400
                                                           dark:border-gray-700 dark:text-gray-500"
                                                    x-text="emptyText(index, t)"
                                                ></p>

                                                <template x-for="(phrase, p) in phrases(index, t)" :key="phrase.key">
                                                    <div class="rounded-lg bg-white p-2 ring-1 ring-gray-200 focus-within:ring-brand-500/60 dark:bg-gray-900 dark:ring-gray-700">
                                                        <div class="flex items-start gap-1.5">
                                                            <textarea
                                                                :id="phraseId(index, t, p)"
                                                                x-model="phrase.text"
                                                                x-on:focus="focusPhrase(index, t, p)"
                                                                rows="2"
                                                                maxlength="1000"
                                                                class="min-h-[2.75rem] w-full resize-y rounded-md border-0 bg-transparent px-1.5 py-1 text-[13px]
                                                                       leading-relaxed text-gray-900 outline-none focus:ring-0 dark:text-white"
                                                            ></textarea>

                                                            <button
                                                                type="button"
                                                                x-on:click="removePhrase(index, t, p)"
                                                                class="dc-tap inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-gray-400
                                                                       transition hover:bg-error-50 hover:text-error-600 dark:hover:bg-error-500/10
                                                                       dark:hover:text-error-400"
                                                                :title="translations.level.remove_phrase"
                                                                :aria-label="translations.level.remove_phrase"
                                                            >
                                                                <x-driver-check.icon name="close" class="h-3.5 w-3.5" />
                                                            </button>
                                                        </div>

                                                        {{-- Already reduced to Telegram's tags by telegramHtml(). --}}
                                                        <div
                                                            x-show="String(phrase.text || '').trim() !== ''"
                                                            class="mt-1.5 flex items-start gap-1.5 border-t border-gray-100 px-1.5 pt-1.5 dark:border-gray-800"
                                                        >
                                                            <x-driver-check.icon name="send" class="mt-0.5 h-3 w-3 shrink-0 text-gray-300 dark:text-gray-600" />
                                                            <p
                                                                class="dc-break whitespace-pre-wrap text-[12px] leading-relaxed text-gray-500 dark:text-gray-400"
                                                                x-html="preview(phrase.text, index)"
                                                            ></p>
                                                        </div>
                                                    </div>
                                                </template>

                                                <button
                                                    type="button"
                                                    x-on:click="addPhrase(index, t)"
                                                    class="dc-tap inline-flex h-9 items-center gap-1.5 self-start rounded-md px-2 text-[12px] font-medium sm:h-8
                                                           text-gray-500 transition hover:bg-white hover:text-gray-900 dark:text-gray-400
                                                           dark:hover:bg-white/[0.06] dark:hover:text-white"
                                                >
                                                    <x-driver-check.icon name="plus" class="h-3.5 w-3.5" />
                                                    <span x-text="translations.level.add_phrase"></span>
                                                </button>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>

                            <template x-for="message in levelErrors(index)" :key="message">
                                <p class="rounded-md bg-error-50 px-3 py-2 text-[12px] font-medium text-error-600 dark:bg-error-500/10 dark:text-error-400"
                                   x-text="message"></p>
                            </template>
                        </div>
                    </article>
                </template>

                <button
                    type="button"
                    x-show="!loading && ladder().length < maxLevels"
                    x-cloak
                    x-on:click="addLevel()"
                    x-ref="levelsEnd"
                    class="dc-tap flex h-11 items-center justify-center gap-2 rounded-2xl border border-dashed border-gray-300
                           text-sm font-medium text-gray-500 transition hover:border-brand-400 hover:bg-brand-25 hover:text-brand-600
                           dark:border-gray-700 dark:text-gray-400 dark:hover:border-brand-500/50 dark:hover:bg-brand-500/[0.06]
                           dark:hover:text-brand-400"
                >
                    <x-driver-check.icon name="plus" class="h-4 w-4" />
                    {{ __('telegram.penalty_settings.level.add') }}
                </button>

                <p class="px-1 text-[12px] text-gray-400 dark:text-gray-500">
                    {{ __('telegram.penalty_settings.level.random_hint') }}
                </p>
            </section>

            {{-- ========================================================
                 Aside: how a text is chosen, placeholders
            ========================================================= --}}
            <aside class="flex flex-col gap-4 xl:sticky xl:top-20">

                {{-- How a text is chosen --}}
                <x-driver-check.surface class="p-4">
                    <h3 class="dc-eyebrow">
                        {{ __('telegram.penalty_settings.sections.how') }}
                    </h3>
                    <p class="mt-2 text-[12px] leading-relaxed text-gray-600 dark:text-gray-300">
                        {{ __('telegram.penalty_settings.sections.how_text') }}
                    </p>
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <x-driver-check.button size="sm" :href="route('driver-check.operators')" icon="operator">
                            {{ __('telegram.operators.tabs.operation') }}
                        </x-driver-check.button>
                        <x-driver-check.button size="sm" :href="route('driver-check.sales')" icon="users">
                            {{ __('telegram.operators.tabs.sales') }}
                        </x-driver-check.button>
                    </div>
                </x-driver-check.surface>

                {{-- Placeholders --}}
                <x-driver-check.surface class="p-4">
                    <h3 class="dc-eyebrow">
                        {{ __('telegram.penalty_settings.sections.placeholders') }}
                    </h3>
                    <p class="mt-1 text-[12px] leading-snug text-gray-500 dark:text-gray-400">
                        {{ __('telegram.penalty_settings.sections.placeholders_hint') }}
                    </p>

                    <ul class="mt-3 flex flex-col gap-0.5">
                        <template x-for="item in placeholders" :key="item.name">
                            <li>
                                <button
                                    type="button"
                                    x-on:click="insertPlaceholder(item.name)"
                                    class="dc-tap flex w-full items-center justify-between gap-3 rounded-lg px-2 py-1.5 text-left transition
                                           hover:bg-brand-25 dark:hover:bg-brand-500/[0.07]"
                                >
                                    <code
                                        class="shrink-0 rounded-md bg-gray-100 px-1.5 py-0.5 font-mono text-[11px] text-gray-700
                                               dark:bg-white/[0.06] dark:text-gray-300"
                                        x-text="'{' + item.name + '}'"
                                    ></code>
                                    <span class="truncate text-[12px] text-gray-500 dark:text-gray-400" x-text="item.hint"></span>
                                </button>
                            </li>
                        </template>
                    </ul>
                </x-driver-check.surface>

                <div class="flex justify-end">
                    <x-driver-check.button
                        size="sm"
                        variant="ghost"
                        icon="refresh"
                        x-show="customised"
                        x-cloak
                        x-on:click="reset()"
                        ::disabled="saving"
                    >
                        <span x-text="resetArmed ? translations.actions.reset_confirm : translations.actions.reset"></span>
                    </x-driver-check.button>
                </div>
            </aside>
        </div>
        @else
        {{-- How and when to send: the switches, the working hours, the retries --}}
        <div class="grid items-start gap-5 lg:grid-cols-2">
            <div class="flex flex-col gap-4">
                {{-- Delivery: saved at once, these are the "stop now" buttons --}}
                <x-driver-check.surface class="divide-y divide-gray-100 dark:divide-gray-800">
                    <h3 class="dc-eyebrow px-4 pb-2 pt-4">
                        {{ __('telegram.penalty_settings.sections.delivery') }}
                    </h3>

                    @foreach ([
                        'enabled' => ['telegram.penalties.settings.enabled', 'telegram.penalties.settings.enabled_hint'],
                        'comments_enabled' => ['telegram.penalties.settings.comments', 'telegram.penalties.settings.comments_hint'],
                    ] as $key => [$label, $hint])
                        <div class="flex items-start justify-between gap-4 px-4 py-3.5">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ __($label) }}</p>
                                <p class="mt-0.5 text-[12px] leading-snug text-gray-500 dark:text-gray-400">{{ __($hint) }}</p>
                            </div>

                            <button
                                type="button"
                                role="switch"
                                :aria-checked="settings.{{ $key }}"
                                aria-label="{{ __($label) }}"
                                x-on:click="toggleSetting('{{ $key }}')"
                                :disabled="!!settingsBusy{{ $key === 'comments_enabled' ? ' || !settings.enabled' : '' }}"
                                :class="settings.{{ $key }} ? 'bg-brand-500' : 'bg-gray-200 dark:bg-white/[0.12]'"
                                class="dc-tap relative mt-0.5 inline-flex h-7 w-12 shrink-0 items-center rounded-full transition
                                       outline-none focus-visible:ring-4 focus-visible:ring-brand-500/20 disabled:opacity-50"
                            >
                                <span
                                    :class="settings.{{ $key }} ? 'translate-x-6' : 'translate-x-1'"
                                    class="inline-block h-5 w-5 rounded-full bg-white shadow-sm transition-transform"
                                ></span>
                            </button>
                        </div>
                    @endforeach

                    {{-- Who gets them: operators and sales apart, under the main switch --}}
                    <div class="px-4 pb-1 pt-3">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                            {{ __('telegram.penalties.settings.by_role') }}
                        </p>
                    </div>

                    @foreach (['operation_enabled', 'sales_enabled'] as $key)
                        <div class="flex items-center justify-between gap-4 px-4 py-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ __("telegram.penalties.settings.{$key}") }}</p>
                                <p class="mt-0.5 text-[12px] leading-snug text-gray-500 dark:text-gray-400">{{ __("telegram.penalties.settings.{$key}_hint") }}</p>
                            </div>

                            <button
                                type="button"
                                role="switch"
                                :aria-checked="settings.{{ $key }}"
                                aria-label="{{ __("telegram.penalties.settings.{$key}") }}"
                                x-on:click="toggleSetting('{{ $key }}')"
                                :disabled="!!settingsBusy || !settings.enabled"
                                :class="settings.{{ $key }} ? 'bg-brand-500' : 'bg-gray-200 dark:bg-white/[0.12]'"
                                class="dc-tap relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition
                                       outline-none focus-visible:ring-4 focus-visible:ring-brand-500/20 disabled:opacity-50"
                            >
                                <span
                                    :class="settings.{{ $key }} ? 'translate-x-6' : 'translate-x-1'"
                                    class="inline-block h-5 w-5 rounded-full bg-white shadow-sm transition-transform"
                                ></span>
                            </button>
                        </div>
                    @endforeach
                </x-driver-check.surface>

            </div>

            <div class="flex flex-col gap-4">
                {{-- Working hours: penalties outside them are ignored --}}
                <x-driver-check.surface class="p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="dc-eyebrow">
                                {{ __('telegram.penalty_settings.sections.hours') }}
                            </h3>
                            <p class="mt-1 text-[12px] leading-snug text-gray-500 dark:text-gray-400">
                                {{ __('telegram.penalty_settings.hours.hint') }}
                            </p>
                        </div>

                        <span
                            class="shrink-0 rounded px-1.5 py-0.5 text-[11px] font-semibold tabular-nums"
                            :class="hoursSet()
                                ? 'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300'
                                : 'bg-gray-100 text-gray-500 dark:bg-white/[0.06] dark:text-gray-400'"
                            x-text="hoursSet() ? form.work_from + '–' + form.work_to : translations.hours.always"
                        ></span>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-3">
                        @foreach (['work_from' => 'from', 'work_to' => 'to'] as $field => $end)
                            <label class="min-w-0">
                                <span class="mb-1 block text-[11px] font-medium text-gray-500 dark:text-gray-400">
                                    {{ __("telegram.penalty_settings.hours.{$end}") }}
                                </span>
                                <input
                                    type="time"
                                    step="60"
                                    x-model="form.{{ $field }}"
                                    class="h-10 w-full rounded-lg border bg-white px-3 text-sm tabular-nums text-gray-900 outline-none
                                           transition focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10
                                           dark:bg-gray-900 dark:text-white"
                                    :class="fieldError('working_hours.{{ $end }}')
                                        ? 'border-error-400 dark:border-error-500/60'
                                        : 'border-gray-300 dark:border-gray-700'"
                                >
                                <span
                                    x-show="fieldError('working_hours.{{ $end }}')"
                                    x-cloak
                                    class="mt-1 block text-[11px] text-error-600 dark:text-error-400"
                                    x-text="fieldError('working_hours.{{ $end }}')"
                                ></span>
                            </label>
                        @endforeach
                    </div>

                    <button
                        type="button"
                        x-show="hoursSet()"
                        x-cloak
                        x-on:click="clearHours()"
                        class="mt-2 text-[12px] font-medium text-gray-500 underline-offset-2 hover:text-gray-900 hover:underline
                               dark:text-gray-400 dark:hover:text-white"
                    >{{ __('telegram.penalty_settings.hours.clear') }}</button>
                </x-driver-check.surface>

                {{-- Timing --}}
                <x-driver-check.surface class="p-4">
                    <h3 class="dc-eyebrow">
                        {{ __('telegram.penalty_settings.sections.timing') }}
                    </h3>

                    <div class="mt-3 flex flex-col gap-3.5">
                        @foreach (['batch_quiet_seconds', 'max_attempts', 'retry_minutes'] as $field)
                            <label class="flex items-start justify-between gap-3">
                                <span class="min-w-0">
                                    <span class="block text-[13px] font-medium text-gray-700 dark:text-gray-300">
                                        {{ __("telegram.penalty_settings.timing.{$field}") }}
                                    </span>
                                    <span class="mt-0.5 block text-[11px] leading-snug text-gray-400 dark:text-gray-500">
                                        {{ __("telegram.penalty_settings.timing.{$field}_hint") }}
                                    </span>
                                    <span
                                        x-show="fieldError('{{ $field }}')"
                                        x-cloak
                                        class="mt-1 block text-[11px] text-error-600 dark:text-error-400"
                                        x-text="fieldError('{{ $field }}')"
                                    ></span>
                                </span>
                                <input
                                    type="number"
                                    min="1"
                                    inputmode="numeric"
                                    x-model="form.{{ $field }}"
                                    class="h-10 w-20 shrink-0 rounded-lg border bg-white px-2.5 text-right sm:h-9 text-sm tabular-nums text-gray-900 outline-none
                                           transition focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 dark:bg-gray-900 dark:text-white"
                                    :class="fieldError('{{ $field }}')
                                        ? 'border-error-400 dark:border-error-500/60'
                                        : 'border-gray-300 dark:border-gray-700'"
                                >
                            </label>
                        @endforeach
                    </div>
                </x-driver-check.surface>

            </div>
        </div>
        @endif
    </x-driver-check.shell>

    {{-- ================================================================
         Save bar: only there when there is something to save
    ================================================================= --}}
    <div
        x-show="dirty() || saving || formError"
        x-cloak
        x-transition.opacity
        class="fixed inset-x-0 bottom-0 z-40 border-t lg:left-[264px] border-gray-200 bg-white/95 px-4 py-3
               backdrop-blur dark:border-gray-800 dark:bg-gray-900/95"
    >
        <div class="mx-auto flex max-w-screen-2xl flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <p class="flex items-center gap-2 text-sm">
                <span class="h-2 w-2 shrink-0 rounded-full" :class="formError ? 'bg-error-500' : 'bg-warning-500'"></span>
                <span
                    :class="formError ? 'font-medium text-error-600 dark:text-error-400' : 'text-gray-700 dark:text-gray-300'"
                    x-text="formError || translations.actions.dirty"
                ></span>
            </p>

            <div class="flex gap-2">
                <x-driver-check.button x-on:click="discard()" ::disabled="saving" class="flex-1 sm:flex-none">
                    {{ __('telegram.penalty_settings.actions.discard') }}
                </x-driver-check.button>

                <x-driver-check.button variant="primary" x-on:click="save()" ::disabled="saving || !dirty()" class="flex-1 sm:flex-none">
                    <x-driver-check.icon name="refresh" class="h-4 w-4 animate-spin" x-show="saving" x-cloak />
                    <span x-text="saving ? translations.actions.saving : translations.actions.save"></span>
                </x-driver-check.button>
            </div>
        </div>
    </div>
</div>

@endsection
