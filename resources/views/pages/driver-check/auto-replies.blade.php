@extends('layouts.driver-check')

@section('title', __('driver_check.tree.pages.auto_' . $part))

@php
    $placeholders = collect(\App\Application\Telegram\Services\AutoReplyRules::PLACEHOLDERS)
        ->map(fn (string $name) => ['name' => $name, 'hint' => __("telegram.auto_replies.placeholders.{$name}")])
        ->values();

    // Shared looks of the editor: a label, a text field, a number field
    $label = 'text-[12px] font-semibold text-gray-600 dark:text-gray-400';
    $hint = 'text-[11px] leading-snug text-gray-400 dark:text-gray-500';
    $field = 'dc-input rounded-lg border bg-white px-2.5 text-gray-900 outline-none transition hover:border-gray-400
              focus:border-brand-500 focus:ring-[3px] focus:ring-brand-500/15 dark:bg-gray-950 dark:text-white';
    $area = $field . ' w-full resize-y py-2 font-mono text-[12px] leading-relaxed';
    $num = $field . ' h-10 w-20 text-sm tabular-nums sm:h-9';
    $iconBtn = 'dc-tap inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-gray-500 transition
                hover:bg-gray-100 disabled:opacity-30 sm:h-8 sm:w-8 dark:text-gray-400 dark:hover:bg-white/[0.07]';
    $errBox = 'rounded-lg bg-error-50 px-3 py-2 text-[12px] font-medium text-error-600 dark:bg-error-500/10 dark:text-error-400';
@endphp

@section('content')

<div
    x-data="dcAutoReplies({
        endpoints: {
            rules: @js(route('api.telegram.auto-replies')),
            upload: @js(route('api.telegram.auto-replies.media.upload')),
            media: @js(url('/api/telegram/auto-replies/media')),
            telegramGifs: @js(route('api.telegram.auto-replies.telegram-gifs.search')),
        },
        uzCyrillic: @js(\App\Application\Telegram\Services\UzbekScript::enabled()),
        maxReplies: @js(\App\Application\Telegram\Services\AutoReplyRules::MAX_REPLIES),
        maxGreetings: @js(\App\Application\Telegram\Services\AutoReplyRules::MAX_GREETINGS),
        placeholders: @js($placeholders),
        languages: @js(__('telegram.penalty_settings.languages')),
        translations: @js(__('telegram.auto_replies')),
    })"
    class="pb-24"
>
    <x-driver-check.shell>

        {{-- ============================================================
             Header
        ============================================================= --}}
        <x-driver-check.page-header
            :title="__('driver_check.tree.pages.auto_' . $part)"
            :description="$part === 'main' ? __('telegram.auto_replies.description') : __('driver_check.pages.auto_' . $part)"
        >
            <x-slot:actions>
                @if ($part === 'main')
                {{-- Whether the file is the default or edited by hand --}}
                <span
                    x-show="!loading"
                    x-cloak
                    class="hidden h-7 items-center gap-1.5 rounded-md px-2 text-[12px] font-medium sm:inline-flex"
                    :class="customised
                        ? 'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400'
                        : 'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300'"
                    x-text="customised ? translations.file.customised : translations.file.default"
                ></span>

                <x-driver-check.button icon="download" :href="route('api.telegram.auto-replies.download')" class="col-span-2 whitespace-nowrap sm:col-span-1">
                    {{ __('telegram.auto_replies.file.download') }}
                </x-driver-check.button>
                @endif
            </x-slot:actions>
        </x-driver-check.page-header>

        <div
            x-show="loadError"
            x-cloak
            class="dc-break rounded-xl bg-error-50 px-4 py-3 text-sm text-error-700 dark:bg-error-500/10 dark:text-error-400"
        >
            <span x-text="loadError"></span>
            <button type="button" class="ml-2 font-semibold underline" x-on:click="load()">{{ __('telegram.ui.refresh') }}</button>
        </div>

        {{-- A hand edit gone wrong: nothing is answered until it is fixed --}}
        <div
            x-show="fileError"
            x-cloak
            class="dc-break flex items-start gap-2 rounded-xl bg-error-50 px-4 py-3 text-sm text-error-700 dark:bg-error-500/10 dark:text-error-400"
        >
            <x-driver-check.icon name="alert" class="mt-0.5 h-4 w-4 shrink-0" />
            <span x-text="translations.file.broken.replace(':error', fileError)"></span>
        </div>

        @if ($part === 'main')
        {{-- Try a message, against the form as it is now --}}
        <x-driver-check.surface class="p-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <h3 class="dc-eyebrow shrink-0 sm:w-40">
                    {{ __('telegram.auto_replies.sections.test') }}
                </h3>

                <input
                    type="text"
                    x-model="test.text"
                    :placeholder="translations.test.placeholder"
                    class="{{ $field }} h-10 min-w-0 text-sm sm:flex-1 placeholder:text-gray-400 sm:h-9 border-gray-300 dark:border-gray-700"
                >

                <div class="flex shrink-0 items-center gap-2 text-[12px] text-gray-500 dark:text-gray-400">
                    <span x-text="translations.test.as"></span>
                    <select
                        x-model="test.language"
                        class="h-10 rounded-lg border border-gray-300 bg-white px-2 text-[13px] text-gray-700 sm:h-9 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200"
                    >
                        <template x-for="lang in languages" :key="'tl-' + lang">
                            <option :value="lang" x-text="lang.toUpperCase()"></option>
                        </template>
                    </select>
                    <select
                        x-model="test.tone"
                        class="h-10 rounded-lg border border-gray-300 bg-white px-2 text-[13px] text-gray-700 sm:h-9 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200"
                    >
                        <template x-for="t in tones" :key="'tt-' + t">
                            <option :value="t" x-text="translations.tones[t]"></option>
                        </template>
                    </select>
                </div>
            </div>

            <div x-show="tried().state !== 'empty'" x-cloak class="mt-3 flex flex-col gap-1.5 border-t border-gray-100 pt-3 dark:border-gray-800">
                {{-- A greeting is answered first, in a message of its own --}}
                <template x-if="tried().greeting">
                    <div class="flex flex-col gap-1.5">
                        <p class="text-[12px] font-medium text-success-700 dark:text-success-400" x-text="triedGreeting()"></p>

                        {{-- One is picked at random. Already reduced to Telegram's tags by telegramHtml(). --}}
                        <template x-for="(answer, a) in tried().greetingAnswers" :key="'tg-' + a">
                            <p
                                class="dc-break self-start whitespace-pre-wrap rounded-lg bg-gray-100 px-3 py-2 text-[13px] leading-relaxed
                                       text-gray-800 dark:bg-white/[0.06] dark:text-gray-200"
                                x-html="answer"
                            ></p>
                        </template>

                        <p
                            x-show="tried().greetingGifs || tried().greetingVoices"
                            class="text-[11px] text-gray-500 dark:text-gray-400"
                            x-text="translations.test.media.replace(':gifs', tried().greetingGifs || 0).replace(':voices', tried().greetingVoices || 0)"
                        ></p>

                        <p class="text-[11px] text-gray-500 dark:text-gray-400" x-text="translations.test.greeted_once"></p>
                    </div>
                </template>

                <p
                    x-show="triedText() !== ''"
                    class="text-[12px] font-medium"
                    :class="tried().state === 'match' ? 'text-success-700 dark:text-success-400' : 'text-gray-500 dark:text-gray-400'"
                    x-text="triedText()"
                ></p>

                {{-- Every possible answer, one is picked at random. Already reduced to Telegram's tags by telegramHtml(). --}}
                <template x-for="(answer, a) in tried().answers || []" :key="'ta-' + a">
                    <p
                        class="dc-break self-start whitespace-pre-wrap rounded-lg bg-gray-100 px-3 py-2 text-[13px] leading-relaxed
                               text-gray-800 dark:bg-white/[0.06] dark:text-gray-200"
                        x-html="answer"
                    ></p>
                </template>

                <p
                    x-show="tried().gifs || tried().voices"
                    class="text-[11px] text-gray-500 dark:text-gray-400"
                    x-text="translations.test.media.replace(':gifs', tried().gifs || 0).replace(':voices', tried().voices || 0)"
                ></p>
            </div>
        </x-driver-check.surface>

        @endif

        <div @class([
            'grid items-start gap-5',
            'xl:grid-cols-[minmax(0,1fr)_340px]' => $part !== 'main',
        ])>

            @if ($part !== 'main')
            {{-- ========================================================
                 The kinds: what they write, what we answer
            ========================================================= --}}
            <section class="flex min-w-0 flex-col gap-5">
                @if ($part !== 'keywords')
                <div class="flex justify-end">
                    @include('pages.driver-check.partials.auto-reply-language')
                </div>
                @endif

                @if ($part === 'keywords')
                <div class="flex flex-col gap-3">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <div class="min-w-0">
                            <h2 class="text-[15px] font-semibold text-gray-900 dark:text-white">
                                {{ __('telegram.auto_replies.sections.kinds') }}
                            </h2>
                            <p class="mt-0.5 max-w-2xl text-[13px] leading-relaxed text-gray-500 dark:text-gray-400">
                                {{ __('telegram.auto_replies.sections.kinds_hint') }}
                            </p>
                        </div>

                        @include('pages.driver-check.partials.auto-reply-language')
                    </div>

                    {{-- Loading --}}
                    <template x-if="loading">
                        <div class="dc-surface flex flex-col divide-y divide-gray-100 rounded-2xl dark:divide-gray-800">
                            <template x-for="i in 3" :key="'sk-' + i">
                                <div class="dc-skeleton h-14 bg-gray-50 dark:bg-white/[0.02]"></div>
                            </template>
                        </div>
                    </template>

                    {{-- One row per kind; a row opens into its editor --}}
                    <div
                        x-show="form.replies.length > 0"
                        x-cloak
                        class="dc-surface divide-y divide-gray-100 overflow-hidden rounded-2xl dark:divide-gray-800"
                    >
                        <template x-for="(kind, k) in form.replies" :key="kind.key">
                            {{-- Opens by itself when it is the first, brand new, or has an error --}}
                            <article
                                x-data="{ open: k === 0 || (!kind.name && !kind.keywords) }"
                                x-effect="if (kindErrors(k).length) open = true"
                            >
                                {{-- Head: order, name, remove --}}
                                <div class="flex items-center gap-1.5 px-3 py-2 sm:px-4" :class="open ? 'bg-gray-25 dark:bg-white/[0.02]' : ''">
                                    <button
                                        type="button"
                                        x-on:click="open = !open"
                                        :aria-expanded="open"
                                        class="dc-tap inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-gray-400 transition
                                               hover:bg-gray-100 hover:text-gray-700 sm:h-8 sm:w-8 dark:hover:bg-white/[0.07] dark:hover:text-gray-200"
                                    >
                                        <x-driver-check.icon name="chevron-right" class="h-4 w-4 transition-transform" ::class="open ? 'rotate-90' : ''" />
                                    </button>

                                    <span
                                        class="inline-flex h-6 min-w-6 shrink-0 items-center justify-center rounded-md bg-gray-100 px-1.5 text-[11px]
                                               font-semibold tabular-nums text-gray-600 dark:bg-white/[0.07] dark:text-gray-300"
                                        x-text="k + 1"
                                    ></span>

                                    <input
                                        type="text"
                                        x-model="kind.name"
                                        maxlength="60"
                                        :placeholder="kindTitle(k)"
                                        :aria-label="translations.kind.name"
                                        class="h-9 min-w-0 flex-1 rounded-lg border border-transparent bg-transparent px-2 text-sm font-semibold
                                               text-gray-900 outline-none transition placeholder:text-gray-400 hover:border-gray-200
                                               focus:border-brand-500 focus:ring-[3px] focus:ring-brand-500/15 sm:h-8
                                               dark:text-white dark:placeholder:text-gray-500 dark:hover:border-gray-700"
                                    >

                                    <span
                                        x-show="kindErrors(k).length"
                                        x-cloak
                                        class="dc-dot h-2 w-2 shrink-0 rounded-full bg-error-500"
                                    ></span>

                                    <span
                                        class="hidden shrink-0 rounded-md bg-gray-100 px-1.5 py-0.5 text-[11px] font-medium tabular-nums text-gray-600
                                               sm:inline dark:bg-white/[0.06] dark:text-gray-300"
                                        x-text="keywordCount(k)"
                                    ></span>

                                    <button
                                        type="button"
                                        x-on:click="moveKind(k, -1)"
                                        :disabled="k === 0"
                                        class="{{ $iconBtn }} max-sm:hidden"
                                        :title="translations.kind.move_up"
                                        :aria-label="translations.kind.move_up"
                                    >
                                        <x-driver-check.icon name="arrow-up" class="h-4 w-4" />
                                    </button>

                                    <button
                                        type="button"
                                        x-on:click="moveKind(k, 1)"
                                        :disabled="k === form.replies.length - 1"
                                        class="{{ $iconBtn }} max-sm:hidden"
                                        :title="translations.kind.move_down"
                                        :aria-label="translations.kind.move_down"
                                    >
                                        <x-driver-check.icon name="arrow-down" class="h-4 w-4" />
                                    </button>

                                    <button
                                        type="button"
                                        x-on:click="removeKind(k)"
                                        class="dc-tap inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-gray-400 transition
                                               hover:bg-error-50 hover:text-error-600 sm:h-8 sm:w-8 dark:hover:bg-error-500/10 dark:hover:text-error-400"
                                        :title="translations.kind.remove"
                                        :aria-label="translations.kind.remove"
                                    >
                                        <x-driver-check.icon name="close" class="h-4 w-4" />
                                    </button>
                                </div>

                                <div x-show="open" x-cloak class="flex flex-col gap-4 border-t border-gray-100 px-3 py-4 sm:px-4 dark:border-gray-800">

                                    {{-- Order on a phone, where the head has no room for it --}}
                                    <div class="flex gap-2 sm:hidden">
                                        <button type="button" x-on:click="moveKind(k, -1)" :disabled="k === 0" class="{{ $iconBtn }} border border-gray-200 dark:border-gray-700" :title="translations.kind.move_up" :aria-label="translations.kind.move_up">
                                            <x-driver-check.icon name="arrow-up" class="h-4 w-4" />
                                        </button>
                                        <button type="button" x-on:click="moveKind(k, 1)" :disabled="k === form.replies.length - 1" class="{{ $iconBtn }} border border-gray-200 dark:border-gray-700" :title="translations.kind.move_down" :aria-label="translations.kind.move_down">
                                            <x-driver-check.icon name="arrow-down" class="h-4 w-4" />
                                        </button>
                                    </div>

                                    {{-- What they write --}}
                                    <label class="flex flex-col gap-1.5">
                                        <span class="{{ $label }}" x-text="translations.kind.keywords"></span>
                                        <textarea
                                            x-model="kind.keywords"
                                            rows="2"
                                            class="{{ $area }}"
                                            :class="fieldError('replies.' + k + '.keywords')
                                                ? 'border-error-400 dark:border-error-500/60'
                                                : 'border-gray-300 dark:border-gray-700'"
                                        ></textarea>
                                        <span class="{{ $hint }}" x-text="translations.kind.keywords_hint"></span>
                                    </label>

                                    {{-- How long a message this kind still takes --}}
                                    <label class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                        <span class="{{ $label }}" x-text="translations.kind.max_words"></span>
                                        <input
                                            type="number"
                                            min="1"
                                            max="50"
                                            inputmode="numeric"
                                            x-model="kind.max_words"
                                            :placeholder="form.max_words"
                                            class="{{ $num }}"
                                            :class="fieldError('replies.' + k + '.max_words')
                                                ? 'border-error-400 dark:border-error-500/60'
                                                : 'border-gray-300 dark:border-gray-700'"
                                        >
                                        <span class="{{ $hint }}" x-text="maxWordsHint()"></span>
                                    </label>

                                    {{-- What we answer: both tones side by side --}}
                                    <div class="flex flex-col gap-2">
                                        <span class="{{ $label }}" x-text="translations.kind.answers"></span>
                                        @include('pages.driver-check.partials.auto-reply-answers', ['owner' => 'k', 'uid' => 'col'])
                                    </div>

                                    {{-- GIFs and voice messages: picked at random among the texts --}}
                                    <div class="flex flex-col gap-2">
                                        <span class="{{ $label }}" x-text="translations.media.title"></span>
                                        @include('pages.driver-check.partials.auto-reply-media', ['owner' => 'k'])
                                    </div>

                                    <template x-for="message in kindErrors(k)" :key="message">
                                        <p class="{{ $errBox }}" x-text="message"></p>
                                    </template>
                                </div>
                            </article>
                        </template>
                    </div>

                    <p
                        x-show="!loading && form.replies.length === 0"
                        x-cloak
                        class="rounded-xl border border-dashed border-gray-300 px-4 py-3 text-[13px] text-gray-500
                               dark:border-gray-700 dark:text-gray-400"
                    >{{ __('telegram.auto_replies.kind.none') }}</p>

                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-[12px] text-gray-400 dark:text-gray-500">
                            {{ __('telegram.auto_replies.kind.random_hint') }}
                        </p>

                        <x-driver-check.button
                            icon="plus"
                            x-show="!loading && form.replies.length < maxReplies"
                            x-cloak
                            x-on:click="addKind()"
                            x-ref="kindsEnd"
                            class="self-start"
                        >
                            {{ __('telegram.auto_replies.kind.add') }}
                        </x-driver-check.button>
                    </div>
                </div>

                @endif

                @if ($part === 'greetings')
                {{-- ====================================================
                     Greetings: "Доброе утро" answered before the kind
                ===================================================== --}}
                <section x-show="!loading" x-cloak class="dc-surface rounded-2xl">
                    <div class="flex items-start justify-between gap-4 px-4 py-3.5">
                        <div class="min-w-0">
                            <h2 class="text-[15px] font-semibold text-gray-900 dark:text-white">
                                {{ __('telegram.auto_replies.sections.greetings') }}
                            </h2>
                            <p class="mt-0.5 text-[13px] leading-relaxed text-gray-500 dark:text-gray-400">
                                {{ __('telegram.auto_replies.greetings.enabled_hint') }}
                            </p>
                        </div>

                        @include('pages.driver-check.partials.auto-reply-switch', ['model' => 'form.greetings.enabled', 'label' => __('telegram.auto_replies.greetings.enabled')])
                    </div>

                    <div x-show="form.greetings.enabled" x-collapse>
                        <div class="flex flex-col gap-3 border-t border-gray-100 px-3 py-4 sm:px-4 dark:border-gray-800">
                            <p class="{{ $hint }}">
                                {{ __('telegram.auto_replies.greetings.order_hint') }}
                            </p>

                            <div
                                x-show="form.greetings.list.length > 0"
                                class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 dark:divide-gray-800 dark:border-gray-800"
                            >
                                <template x-for="(greeting, g) in form.greetings.list" :key="greeting.key">
                                    <div
                                        x-data="{ open: g === 0 || (!greeting.name && !greeting.keywords) }"
                                        x-effect="if (greetingErrors(g).length) open = true"
                                    >
                                        {{-- Head: order, name, remove --}}
                                        <div class="flex items-center gap-1.5 px-2.5 py-2" :class="open ? 'bg-gray-25 dark:bg-white/[0.02]' : ''">
                                            <button
                                                type="button"
                                                x-on:click="open = !open"
                                                :aria-expanded="open"
                                                class="dc-tap inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-gray-400 transition
                                                       hover:bg-gray-100 hover:text-gray-700 sm:h-8 sm:w-8 dark:hover:bg-white/[0.07] dark:hover:text-gray-200"
                                            >
                                                <x-driver-check.icon name="chevron-right" class="h-4 w-4 transition-transform" ::class="open ? 'rotate-90' : ''" />
                                            </button>

                                            <span
                                                class="inline-flex h-6 min-w-6 shrink-0 items-center justify-center rounded-md bg-gray-100 px-1.5 text-[11px]
                                                       font-semibold tabular-nums text-gray-600 dark:bg-white/[0.07] dark:text-gray-300"
                                                x-text="g + 1"
                                            ></span>

                                            <input
                                                type="text"
                                                x-model="greeting.name"
                                                maxlength="60"
                                                :placeholder="greetingTitle(g)"
                                                :aria-label="translations.kind.name"
                                                class="h-9 min-w-0 flex-1 rounded-lg border border-transparent bg-transparent px-2 text-sm font-semibold
                                                       text-gray-900 outline-none transition placeholder:text-gray-400 hover:border-gray-200
                                                       focus:border-brand-500 focus:ring-[3px] focus:ring-brand-500/15 sm:h-8
                                                       dark:text-white dark:placeholder:text-gray-500 dark:hover:border-gray-700"
                                            >

                                            <span
                                                x-show="greetingErrors(g).length"
                                                x-cloak
                                                class="dc-dot h-2 w-2 shrink-0 rounded-full bg-error-500"
                                            ></span>

                                            <span
                                                class="hidden shrink-0 rounded-md bg-gray-100 px-1.5 py-0.5 text-[11px] font-medium tabular-nums text-gray-600
                                                       sm:inline dark:bg-white/[0.06] dark:text-gray-300"
                                                x-text="greetingKeywordCount(g)"
                                            ></span>

                                            <button
                                                type="button"
                                                x-on:click="moveGreeting(g, -1)"
                                                :disabled="g === 0"
                                                class="{{ $iconBtn }} max-sm:hidden"
                                                :title="translations.kind.move_up"
                                                :aria-label="translations.kind.move_up"
                                            >
                                                <x-driver-check.icon name="arrow-up" class="h-4 w-4" />
                                            </button>

                                            <button
                                                type="button"
                                                x-on:click="moveGreeting(g, 1)"
                                                :disabled="g === form.greetings.list.length - 1"
                                                class="{{ $iconBtn }} max-sm:hidden"
                                                :title="translations.kind.move_down"
                                                :aria-label="translations.kind.move_down"
                                            >
                                                <x-driver-check.icon name="arrow-down" class="h-4 w-4" />
                                            </button>

                                            <button
                                                type="button"
                                                x-on:click="removeGreeting(g)"
                                                class="dc-tap inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-gray-400 transition
                                                       hover:bg-error-50 hover:text-error-600 sm:h-8 sm:w-8 dark:hover:bg-error-500/10 dark:hover:text-error-400"
                                                :title="translations.greetings.remove"
                                                :aria-label="translations.greetings.remove"
                                            >
                                                <x-driver-check.icon name="close" class="h-4 w-4" />
                                            </button>
                                        </div>

                                        <div x-show="open" x-cloak class="flex flex-col gap-4 border-t border-gray-100 px-3 py-4 dark:border-gray-800">

                                            {{-- Order on a phone, where the head has no room for it --}}
                                            <div class="flex gap-2 sm:hidden">
                                                <button type="button" x-on:click="moveGreeting(g, -1)" :disabled="g === 0" class="{{ $iconBtn }} border border-gray-200 dark:border-gray-700" :title="translations.kind.move_up" :aria-label="translations.kind.move_up">
                                                    <x-driver-check.icon name="arrow-up" class="h-4 w-4" />
                                                </button>
                                                <button type="button" x-on:click="moveGreeting(g, 1)" :disabled="g === form.greetings.list.length - 1" class="{{ $iconBtn }} border border-gray-200 dark:border-gray-700" :title="translations.kind.move_down" :aria-label="translations.kind.move_down">
                                                    <x-driver-check.icon name="arrow-down" class="h-4 w-4" />
                                                </button>
                                            </div>

                                            {{-- What they write --}}
                                            <label class="flex flex-col gap-1.5">
                                                <span class="{{ $label }}" x-text="translations.kind.keywords"></span>
                                                <textarea
                                                    x-model="greeting.keywords"
                                                    rows="2"
                                                    class="{{ $area }}"
                                                    :class="fieldError('greetings.list.' + g + '.keywords')
                                                        ? 'border-error-400 dark:border-error-500/60'
                                                        : 'border-gray-300 dark:border-gray-700'"
                                                ></textarea>
                                            </label>

                                            {{-- What we answer: both tones side by side --}}
                                            @include('pages.driver-check.partials.auto-reply-answers', ['owner' => "'g' + g", 'uid' => 'gcol'])

                                            {{-- GIFs and voice messages: picked at random among the texts --}}
                                            <div class="flex flex-col gap-2">
                                                <span class="{{ $label }}" x-text="translations.media.title"></span>
                                                @include('pages.driver-check.partials.auto-reply-media', ['owner' => "'g' + g"])
                                            </div>

                                            <template x-for="message in greetingErrors(g)" :key="message">
                                                <p class="{{ $errBox }}" x-text="message"></p>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            <p
                                x-show="form.greetings.list.length === 0"
                                class="rounded-xl border border-dashed border-gray-300 px-4 py-3 text-[13px] text-gray-500
                                       dark:border-gray-700 dark:text-gray-400"
                            >{{ __('telegram.auto_replies.greetings.none') }}</p>

                            <x-driver-check.button
                                icon="plus"
                                x-show="form.greetings.list.length < maxGreetings"
                                x-on:click="addGreeting()"
                                class="self-start"
                            >
                                {{ __('telegram.auto_replies.greetings.add') }}
                            </x-driver-check.button>

                            {{-- What may follow a greeting and still leave it a greeting alone --}}
                            <label class="flex flex-col gap-1.5">
                                <span class="{{ $label }}">
                                    {{ __('telegram.auto_replies.greetings.fillers') }}
                                </span>
                                <textarea
                                    x-model="form.greetings.fillers"
                                    rows="2"
                                    class="{{ $area }}"
                                    :class="fieldError('greetings.fillers')
                                        ? 'border-error-400 dark:border-error-500/60'
                                        : 'border-gray-300 dark:border-gray-700'"
                                ></textarea>
                                <span class="{{ $hint }}">
                                    {{ __('telegram.auto_replies.greetings.fillers_hint') }}
                                </span>
                            </label>
                        </div>
                    </div>
                </section>

                @endif

                @if ($part === 'reminders')
                {{-- ====================================================
                     When they stay silent: one nudge per penalty
                ===================================================== --}}
                <section
                    x-show="!loading"
                    x-cloak
                    class="dc-surface rounded-2xl"
                    :class="fieldError('silence.answers') ? '!border-error-300 dark:!border-error-500/40' : ''"
                >
                    <div class="flex items-start justify-between gap-4 px-4 py-3.5">
                        <div class="min-w-0">
                            <h2 class="text-[15px] font-semibold text-gray-900 dark:text-white">
                                {{ __('telegram.auto_replies.sections.silence') }}
                            </h2>
                            <p class="mt-0.5 text-[13px] leading-relaxed text-gray-500 dark:text-gray-400">
                                {{ __('telegram.auto_replies.silence.enabled_hint') }}
                            </p>
                        </div>

                        @include('pages.driver-check.partials.auto-reply-switch', ['model' => 'form.silence.enabled', 'label' => __('telegram.auto_replies.silence.enabled')])
                    </div>

                    <div x-show="form.silence.enabled" x-collapse>
                        <div class="flex flex-col gap-4 border-t border-gray-100 px-3 py-4 sm:px-4 dark:border-gray-800">
                            <label class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="{{ $label }}">
                                    {{ __('telegram.auto_replies.silence.after_penalties') }}
                                </span>
                                <input
                                    type="number"
                                    min="1"
                                    max="50"
                                    inputmode="numeric"
                                    x-model="form.silence.after_penalties"
                                    class="{{ $num }}"
                                    :class="fieldError('silence.after_penalties')
                                        ? 'border-error-400 dark:border-error-500/60'
                                        : 'border-gray-300 dark:border-gray-700'"
                                >
                                <span class="{{ $hint }}">
                                    {{ __('telegram.auto_replies.silence.after_penalties_hint') }}
                                </span>
                            </label>

                            @include('pages.driver-check.partials.auto-reply-answers', ['owner' => "'silence'", 'uid' => 'silence'])

                            {{-- GIFs and voice messages: picked at random among the texts --}}
                            <div class="flex flex-col gap-2">
                                <span class="{{ $label }}" x-text="translations.media.title"></span>
                                @include('pages.driver-check.partials.auto-reply-media', ['owner' => "'silence'"])
                            </div>

                            <p
                                x-show="fieldError('silence.answers')"
                                x-cloak
                                class="{{ $errBox }}"
                                x-text="fieldError('silence.answers')"
                            ></p>
                        </div>
                    </div>
                </section>

                @endif

                @if ($part === 'greetings')
                {{-- ====================================================
                     Before the day's first penalty: we greet first
                ===================================================== --}}
                <section
                    x-show="!loading"
                    x-cloak
                    class="dc-surface rounded-2xl"
                    :class="fieldError('opening.answers') ? '!border-error-300 dark:!border-error-500/40' : ''"
                >
                    <div class="flex items-start justify-between gap-4 px-4 py-3.5">
                        <div class="min-w-0">
                            <h2 class="text-[15px] font-semibold text-gray-900 dark:text-white">
                                {{ __('telegram.auto_replies.sections.opening') }}
                            </h2>
                            <p class="mt-0.5 text-[13px] leading-relaxed text-gray-500 dark:text-gray-400">
                                {{ __('telegram.auto_replies.opening.enabled_hint') }}
                            </p>
                        </div>

                        @include('pages.driver-check.partials.auto-reply-switch', ['model' => 'form.opening.enabled', 'label' => __('telegram.auto_replies.opening.enabled')])
                    </div>

                    <div x-show="form.opening.enabled" x-collapse>
                        <div class="flex flex-col gap-4 border-t border-gray-100 px-3 py-4 sm:px-4 dark:border-gray-800">

                            @include('pages.driver-check.partials.auto-reply-answers', ['owner' => "'opening'", 'uid' => 'opening'])

                            {{-- GIFs and voice messages: picked at random among the texts --}}
                            <div class="flex flex-col gap-2">
                                <span class="{{ $label }}" x-text="translations.media.title"></span>
                                @include('pages.driver-check.partials.auto-reply-media', ['owner' => "'opening'"])
                            </div>

                            <p
                                x-show="fieldError('opening.answers')"
                                x-cloak
                                class="{{ $errBox }}"
                                x-text="fieldError('opening.answers')"
                            ></p>
                        </div>
                    </div>
                </section>

                @endif

                @if ($part === 'reminders')
                {{-- ====================================================
                     The same level again soon: "это тоже"
                ===================================================== --}}
                <section
                    x-show="!loading"
                    x-cloak
                    class="dc-surface rounded-2xl"
                    :class="fieldError('follow_up.answers') ? '!border-error-300 dark:!border-error-500/40' : ''"
                >
                    <div class="flex items-start justify-between gap-4 px-4 py-3.5">
                        <div class="min-w-0">
                            <h2 class="text-[15px] font-semibold text-gray-900 dark:text-white">
                                {{ __('telegram.auto_replies.sections.follow_up') }}
                            </h2>
                            <p class="mt-0.5 text-[13px] leading-relaxed text-gray-500 dark:text-gray-400">
                                {{ __('telegram.auto_replies.follow_up.enabled_hint') }}
                            </p>
                        </div>

                        @include('pages.driver-check.partials.auto-reply-switch', ['model' => 'form.follow_up.enabled', 'label' => __('telegram.auto_replies.follow_up.enabled')])
                    </div>

                    <div x-show="form.follow_up.enabled" x-collapse>
                        <div class="flex flex-col gap-4 border-t border-gray-100 px-3 py-4 sm:px-4 dark:border-gray-800">

                            <label class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="{{ $label }}">
                                    {{ __('telegram.auto_replies.follow_up.window_minutes') }}
                                </span>
                                <input
                                    type="number"
                                    min="1"
                                    max="1440"
                                    inputmode="numeric"
                                    x-model="form.follow_up.window_minutes"
                                    class="{{ $num }}"
                                    :class="fieldError('follow_up.window_minutes')
                                        ? 'border-error-400 dark:border-error-500/60'
                                        : 'border-gray-300 dark:border-gray-700'"
                                >
                                <span class="{{ $hint }}">
                                    {{ __('telegram.auto_replies.follow_up.window_minutes_hint') }}
                                </span>
                            </label>

                            <div class="flex flex-col gap-2">
                                <span class="{{ $label }}">
                                    {{ __('telegram.auto_replies.follow_up.one') }}
                                </span>
                                @include('pages.driver-check.partials.auto-reply-answers', ['owner' => "'follow_up'", 'uid' => 'follow_up'])
                            </div>

                            <div class="flex flex-col gap-2">
                                <span class="{{ $label }}">
                                    {{ __('telegram.auto_replies.follow_up.many') }}
                                </span>
                                @include('pages.driver-check.partials.auto-reply-answers', ['owner' => "'follow_up_many'", 'uid' => 'follow_up_many'])
                            </div>

                            <p
                                x-show="fieldError('follow_up.answers')"
                                x-cloak
                                class="{{ $errBox }}"
                                x-text="fieldError('follow_up.answers')"
                            ></p>
                        </div>
                    </div>
                </section>
                @endif
            </section>
            @endif

            {{-- ========================================================
                 Aside: the switch, when to answer, placeholders,
                 the file
            ========================================================= --}}
            <aside @class([
                'flex flex-col gap-5',
                'lg:grid lg:grid-cols-2 lg:items-start' => $part === 'main',
                'xl:sticky xl:top-20' => $part !== 'main',
            ])>

                @if ($part === 'main')
                {{-- When to answer --}}
                <x-driver-check.surface class="divide-y divide-gray-100 dark:divide-gray-800">
                    <h3 class="dc-eyebrow px-4 py-3">
                        {{ __('telegram.auto_replies.sections.behaviour') }}
                    </h3>

                    <div class="flex items-start justify-between gap-4 px-4 py-3.5">
                        <div class="min-w-0">
                            <p class="text-[13px] font-semibold text-gray-900 dark:text-white">{{ __('telegram.auto_replies.fields.enabled') }}</p>
                            <p class="mt-0.5 text-[12px] leading-snug text-gray-500 dark:text-gray-400">{{ __('telegram.auto_replies.fields.enabled_hint') }}</p>
                        </div>

                        @include('pages.driver-check.partials.auto-reply-switch', ['model' => 'form.enabled', 'label' => __('telegram.auto_replies.fields.enabled')])
                    </div>

                    {{-- Whom: any message of theirs, or only a penalty's reply --}}
                    <div class="flex flex-col gap-2 px-4 py-3.5" role="radiogroup">
                        @foreach (['scope_any' => false, 'scope_penalty' => true] as $scope => $only)
                            <label
                                class="dc-tap flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition"
                                :class="form.only_after_penalty === @js($only)
                                    ? 'border-brand-500 bg-brand-25 dark:border-brand-500/50 dark:bg-brand-500/[0.07]'
                                    : 'border-gray-200 hover:bg-gray-25 dark:border-gray-800 dark:hover:bg-white/[0.03]'"
                            >
                                <input
                                    type="radio"
                                    name="auto-replies-scope"
                                    :checked="form.only_after_penalty === @js($only)"
                                    x-on:change="form.only_after_penalty = @js($only)"
                                    class="mt-0.5 h-4 w-4 shrink-0 border-gray-300 text-brand-600 focus:ring-brand-500/30 dark:border-gray-600 dark:bg-gray-900"
                                >
                                <span class="min-w-0">
                                    <span class="block text-[13px] font-medium text-gray-900 dark:text-white">
                                        {{ __("telegram.auto_replies.fields.{$scope}") }}
                                    </span>
                                    <span class="mt-0.5 block text-[11px] leading-snug text-gray-500 dark:text-gray-400">
                                        {{ __("telegram.auto_replies.fields.{$scope}_hint") }}
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <div class="flex flex-col gap-3.5 px-4 py-3.5">
                        @foreach (['penalty_window_minutes' => 1, 'cooldown_minutes' => 0, 'max_words' => 1] as $fname => $min)
                            <label class="flex items-start justify-between gap-3">
                                <span class="min-w-0">
                                    <span class="block text-[13px] font-medium text-gray-800 dark:text-gray-200">
                                        {{ __("telegram.auto_replies.fields.{$fname}") }}
                                    </span>
                                    <span class="mt-0.5 block {{ $hint }}">
                                        {{ __("telegram.auto_replies.fields.{$fname}_hint") }}
                                    </span>
                                    <span
                                        x-show="fieldError('{{ $fname }}')"
                                        x-cloak
                                        class="mt-1 block text-[11px] text-error-600 dark:text-error-400"
                                        x-text="fieldError('{{ $fname }}')"
                                    ></span>
                                </span>
                                <input
                                    type="number"
                                    min="{{ $min }}"
                                    inputmode="numeric"
                                    x-model="form.{{ $fname }}"
                                    class="{{ $num }} shrink-0 text-right"
                                    :class="fieldError('{{ $fname }}')
                                        ? 'border-error-400 dark:border-error-500/60'
                                        : 'border-gray-300 dark:border-gray-700'"
                                >
                            </label>
                        @endforeach
                    </div>
                </x-driver-check.surface>

                @endif

                @if ($part !== 'main')
                {{-- Placeholders --}}
                <x-driver-check.surface class="p-4">
                    <h3 class="dc-eyebrow">
                        {{ __('telegram.auto_replies.sections.placeholders') }}
                    </h3>
                    <p class="mt-1.5 text-[12px] leading-snug text-gray-500 dark:text-gray-400">
                        {{ __('telegram.auto_replies.sections.placeholders_hint') }}
                    </p>

                    <ul class="mt-3 flex flex-col gap-0.5">
                        <template x-for="item in placeholders" :key="item.name">
                            <li>
                                <button
                                    type="button"
                                    x-on:click="insertPlaceholder(item.name)"
                                    class="dc-tap flex min-h-9 w-full items-center justify-between gap-3 rounded-lg px-2 py-1.5 text-left transition
                                           hover:bg-gray-100 dark:hover:bg-white/[0.06]"
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

                @endif

                @if ($part === 'main')
                {{-- The file --}}
                <x-driver-check.surface class="p-4">
                    <h3 class="dc-eyebrow">
                        {{ __('telegram.auto_replies.sections.file') }}
                    </h3>

                    <p class="dc-break mt-2 font-mono text-[11px] text-gray-500 dark:text-gray-400" x-text="path"></p>

                    <div class="mt-3 flex flex-wrap gap-2">
                        <x-driver-check.button size="sm" icon="download" :href="route('api.telegram.auto-replies.download')">
                            {{ __('telegram.auto_replies.file.download') }}
                        </x-driver-check.button>

                        <x-driver-check.button
                            size="sm"
                            variant="ghost"
                            icon="refresh"
                            x-show="customised"
                            x-cloak
                            x-on:click="reset()"
                            ::disabled="saving"
                        >
                            <span x-text="resetArmed ? translations.file.reset_confirm : translations.file.reset"></span>
                        </x-driver-check.button>
                    </div>
                </x-driver-check.surface>
                @endif
            </aside>
        </div>
    </x-driver-check.shell>

    {{-- ================================================================
         GIFs out of Telegram: searched by the listener, picked here
    ================================================================= --}}
    @include('pages.driver-check.partials.telegram-gif-picker')

    {{-- ================================================================
         Save bar: only there when there is something to save
    ================================================================= --}}
    <div
        x-show="dirty() || saving || formError"
        x-cloak
        x-transition.opacity
        class="fixed inset-x-0 bottom-0 z-40 border-t lg:left-[264px] border-gray-200 bg-white/95 px-4 py-3 backdrop-blur
               dark:border-gray-800 dark:bg-gray-950/95"
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
                    {{ __('telegram.auto_replies.actions.discard') }}
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
