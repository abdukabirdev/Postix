@extends('layouts.driver-check')

@section('title', __('telegram.personal_answers.title'))

@php
    $placeholders = collect(\App\Application\Telegram\Services\ClientCheckRules::PLACEHOLDERS)
        ->map(fn (string $name) => ['name' => $name, 'hint' => __("telegram.personal_answers.placeholders.{$name}")])
        ->values();
@endphp

@section('content')

<div
    x-data="dcPersonalAnswers({
        endpoints: {
            index: @js(route('api.telegram.personal-answers')),
            person: @js(url('/api/telegram/personal-answers')),
            upload: @js(route('api.telegram.auto-replies.media.upload')),
            media: @js(url('/api/telegram/auto-replies/media')),
            telegramGifs: @js(route('api.telegram.auto-replies.telegram-gifs.search')),
        },
        placeholders: @js($placeholders),
        translations: @js([...__('telegram.personal_answers'), 'media' => __('telegram.auto_replies.media')]),
    })"
    class="pb-24"
>
    <x-driver-check.shell>

        <x-driver-check.page-header
            :title="__('telegram.personal_answers.title')"
            :description="__('telegram.personal_answers.description')"
        >
            <x-slot:actions>
                <span
                    x-show="!loading"
                    x-cloak
                    class="dc-surface inline-flex h-9 items-center rounded-lg px-3 text-[12px] font-medium tabular-nums text-gray-600
                           dark:text-gray-300"
                    x-text="translations.progress.replace(':done', withVoice()).replace(':total', people.length)"
                ></span>
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

        <div class="grid items-start gap-5 lg:grid-cols-[320px_minmax(0,1fr)]">

            {{-- ============================================================
                 People
            ============================================================= --}}
            <x-driver-check.surface class="overflow-hidden lg:sticky lg:top-28">
                <div class="flex flex-col gap-2.5 border-b border-gray-100 p-3 dark:border-gray-800">
                <div class="relative">
                    <x-driver-check.icon
                        name="search"
                        class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400"
                    />
                    <input
                        type="search"
                        autocomplete="off"
                        x-model="filters.search"
                        placeholder="{{ __('telegram.personal_answers.people.search') }}"
                        aria-label="{{ __('telegram.personal_answers.people.search') }}"
                        class="h-10 w-full rounded-lg border border-gray-300 bg-white pl-9 pr-3 text-sm text-gray-900 outline-none
                               transition placeholder:text-gray-400 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10
                               sm:h-9 dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:placeholder:text-gray-500"
                    >
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <select
                        x-model="filters.role"
                        aria-label="{{ __('telegram.personal_answers.people.role') }}"
                        class="h-10 rounded-lg border border-gray-300 bg-white px-2 text-[13px] text-gray-700 outline-none
                               focus:border-brand-500 sm:h-8 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
                    >
                        <option value="">{{ __('telegram.personal_answers.people.all') }}</option>
                        <option value="operation">{{ __('telegram.personal_answers.roles.operation') }}</option>
                        <option value="sales">{{ __('telegram.personal_answers.roles.sales') }}</option>
                    </select>

                    <label class="dc-tap inline-flex cursor-pointer items-center gap-1.5 text-[12px] text-gray-600 dark:text-gray-300">
                        <input type="checkbox" x-model="filters.withoutVoice" class="h-4 w-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500/20">
                        {{ __('telegram.personal_answers.people.without_voice') }}
                    </label>
                </div>
                </div>

                <div class="flex max-h-[60vh] flex-col divide-y divide-gray-100 overflow-y-auto overscroll-contain dark:divide-gray-800">
                    <template x-if="loading">
                        <p class="py-6 text-center text-[12px] text-gray-400">{{ __('telegram.ui.loading') }}</p>
                    </template>

                    <template x-for="p in filtered()" :key="p.id">
                        <button
                            type="button"
                            x-on:click="select(p.id)"
                            class="dc-tap flex w-full items-center gap-2.5 border-l-2 px-3 py-2.5 text-left transition"
                            :class="person?.id === p.id
                                ? 'border-brand-500 bg-gray-25 dark:bg-white/[0.04]'
                                : 'border-transparent hover:bg-gray-25 dark:hover:bg-white/[0.025]'"
                        >
                            <x-driver-check.avatar size="sm" x-text="(p.name || '#').trim().slice(0, 2)" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[13px] font-medium text-gray-900 dark:text-white" x-text="p.name || ('#' + p.id)"></span>
                                <span class="block truncate text-[11px] text-gray-400 dark:text-gray-500">
                                    <span x-text="translations.roles[p.role]"></span>
                                    <span x-show="p.telegram_username" x-text="' · @' + p.telegram_username"></span>
                                </span>
                            </span>
                            <span
                                x-show="counts(p)"
                                class="shrink-0 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-gray-600 dark:bg-white/[0.06] dark:text-gray-300"
                                x-text="counts(p)"
                            ></span>
                        </button>
                    </template>

                    <p
                        x-show="!loading && filtered().length === 0"
                        x-cloak
                        class="py-6 text-center text-[12px] text-gray-400"
                    >{{ __('telegram.personal_answers.people.none') }}</p>
                </div>
            </x-driver-check.surface>

            {{-- ============================================================
                 The person's answers
            ============================================================= --}}
            <div class="flex min-w-0 flex-col gap-4">
                <x-driver-check.surface x-show="!person" class="px-6 py-16 text-center">
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('telegram.personal_answers.empty.title') }}</p>
                    <p class="mx-auto mt-1 max-w-md text-[13px] text-gray-500 dark:text-gray-400">{{ __('telegram.personal_answers.empty.description') }}</p>
                </x-driver-check.surface>

                <template x-if="person">
                    <div class="flex flex-col gap-4">
                        <x-driver-check.surface class="overflow-hidden">
                            <div class="flex flex-wrap items-start justify-between gap-3 p-4">
                                <div class="flex min-w-0 items-center gap-3">
                                    <x-driver-check.avatar x-text="(person.name || '#').trim().slice(0, 2)" />
                                    <div class="min-w-0">
                                        <h2 class="dc-break text-base font-semibold text-gray-950 dark:text-white" x-text="person.name"></h2>
                                        <p class="mt-0.5 text-[12px] text-gray-500 dark:text-gray-400">
                                            <span x-text="translations.roles[person.role]"></span>
                                            · <span x-text="translations.languages[person.language]"></span>
                                            · <span x-text="person.respectful ? translations.person.respectful : translations.person.plain"></span>
                                            <template x-if="person.address">
                                                <span> · <span x-text="translations.person.address.replace(':address', person.address)"></span></span>
                                            </template>
                                        </p>
                                    </div>
                                </div>
                                <span x-show="loadingPerson" x-cloak class="text-[12px] text-gray-400">{{ __('telegram.ui.loading') }}</span>
                            </div>

                            <p
                                x-show="!person.dm_enabled"
                                x-cloak
                                class="mx-4 mb-3 rounded-lg bg-warning-50 px-3 py-2 text-[12px] text-warning-700 dark:bg-warning-500/10 dark:text-warning-400"
                            >{{ __('telegram.personal_answers.person.muted') }}</p>

                            <p class="px-4 pb-4 text-[12px] leading-relaxed text-gray-500 dark:text-gray-400">{{ __('telegram.personal_answers.how') }}</p>

                            {{-- Add a situation --}}
                            <div class="flex flex-col gap-2 border-t border-gray-100 bg-gray-25 px-4 py-3 sm:flex-row dark:border-gray-800 dark:bg-white/[0.015]">
                                <select
                                    x-model="adding"
                                    aria-label="{{ __('telegram.personal_answers.add') }}"
                                    class="h-10 min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 outline-none
                                           focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 sm:h-9 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                                >
                                    <option value="">{{ __('telegram.personal_answers.add_placeholder') }}</option>
                                    <template x-for="group in addable()" :key="group.name">
                                        <optgroup :label="group.name">
                                            <template x-for="option in group.options" :key="option.slot">
                                                <option :value="option.slot" x-text="option.label"></option>
                                            </template>
                                        </optgroup>
                                    </template>
                                </select>

                                <x-driver-check.button variant="primary" icon="plus" x-on:click="addSlot()" ::disabled="!adding">
                                    {{ __('telegram.personal_answers.add') }}
                                </x-driver-check.button>
                            </div>
                        </x-driver-check.surface>

                        <x-driver-check.surface x-show="slotKeys().length === 0" class="px-6 py-10 text-center">
                            <p class="text-[13px] text-gray-500 dark:text-gray-400">{{ __('telegram.personal_answers.no_slots') }}</p>
                        </x-driver-check.surface>

                        {{-- One card per situation --}}
                        <template x-for="slot in slotKeys()" :key="slot">
                            <x-driver-check.surface class="overflow-hidden">
                                <div class="flex items-start justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                                    <div class="min-w-0">
                                        <h3
                                            class="dc-break text-[13.5px] font-semibold"
                                            :class="gone(slot) ? 'text-error-600 dark:text-error-400' : 'text-gray-900 dark:text-white'"
                                            x-text="slotTitle(slot)"
                                        ></h3>
                                        <p x-show="gone(slot)" class="mt-0.5 text-[12px] text-error-600 dark:text-error-400">
                                            {{ __('telegram.personal_answers.situations.gone_hint') }}
                                        </p>
                                    </div>

                                    <button
                                        type="button"
                                        x-on:click="removeSlot(slot)"
                                        class="dc-tap -my-1 -mr-1.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-gray-400 transition
                                               hover:bg-error-50 hover:text-error-600 dark:hover:bg-error-500/10 dark:hover:text-error-400"
                                        :title="translations.remove_slot"
                                        :aria-label="translations.remove_slot"
                                    >
                                        <x-driver-check.icon name="close" class="h-4 w-4" />
                                    </button>
                                </div>

                                {{-- Added to the shared answers, or instead of them --}}
                                <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                                    <div class="min-w-0">
                                        <p class="text-[13px] font-medium text-gray-800 dark:text-gray-100">{{ __('telegram.personal_answers.only') }}</p>
                                        <p class="mt-0.5 text-[12px] text-gray-500 dark:text-gray-400"
                                           x-text="slots[slot].only ? translations.only_on : translations.only_off"></p>
                                    </div>
                                    <button
                                        type="button"
                                        role="switch"
                                        :aria-checked="slots[slot].only"
                                        aria-label="{{ __('telegram.personal_answers.only') }}"
                                        x-on:click="slots[slot].only = !slots[slot].only"
                                        :class="slots[slot].only ? 'bg-brand-500' : 'bg-gray-200 dark:bg-white/[0.14]'"
                                        class="dc-tap relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors
                                               outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40"
                                    >
                                        <span
                                            :class="slots[slot].only ? 'translate-x-[18px]' : 'translate-x-0.5'"
                                            class="inline-block h-4 w-4 rounded-full bg-white shadow-sm transition-transform"
                                        ></span>
                                    </button>
                                </div>

                                {{-- Texts, voices, GIFs --}}
                                <div class="flex flex-col gap-2 p-4">
                                    <template x-for="(item, i) in slots[slot].items" :key="item.key">
                                        <div class="flex items-start gap-2 rounded-lg bg-white p-2 ring-1 ring-gray-200 focus-within:ring-brand-500/60 dark:bg-gray-900 dark:ring-gray-700">
                                            <span
                                                class="dc-eyebrow mt-1 w-12 shrink-0 rounded px-1.5 py-0.5 text-center"
                                                :class="{
                                                    'bg-gray-100 text-gray-600 dark:bg-white/[0.08] dark:text-gray-300': true,
                                                }"
                                                x-text="translations.types[item.type]"
                                            ></span>

                                            <div class="min-w-0 flex-1">
                                                <template x-if="item.type === 'text'">
                                                    <div>
                                                        <textarea
                                                            :id="textId(slot, i)"
                                                            x-model="item.text"
                                                            x-on:focus="focusText(slot, i)"
                                                            rows="2"
                                                            maxlength="1000"
                                                            :placeholder="translations.text_placeholder"
                                                            class="w-full resize-y rounded-md border-0 bg-transparent px-1.5 py-1 text-[13px] leading-relaxed
                                                                   text-gray-900 outline-none focus:ring-0 dark:text-white"
                                                        ></textarea>
                                                        <p
                                                            x-show="String(item.text || '').trim() !== ''"
                                                            class="dc-break mt-1 whitespace-pre-wrap border-t border-gray-100 px-1.5 pt-1.5 text-[12px] text-gray-500 dark:border-gray-800 dark:text-gray-400"
                                                            x-html="preview(item.text)"
                                                        ></p>
                                                    </div>
                                                </template>

                                                <template x-if="item.type === 'voice'">
                                                    <div class="flex flex-col gap-1">
                                                        <audio :src="mediaUrl(item.file)" controls preload="none" class="h-9 w-full"></audio>
                                                        <span class="truncate text-[11px] text-gray-400" x-text="item.name"></span>
                                                    </div>
                                                </template>

                                                <template x-if="item.type === 'gif'">
                                                    <div class="flex items-center gap-2">
                                                        <template x-if="item.file.endsWith('.mp4')">
                                                            <video :src="mediaUrl(item.file)" autoplay loop muted playsinline class="h-12 w-16 shrink-0 rounded-md bg-gray-100 object-cover dark:bg-gray-800"></video>
                                                        </template>
                                                        <template x-if="!item.file.endsWith('.mp4')">
                                                            <img :src="mediaUrl(item.file)" alt="" class="h-12 w-16 shrink-0 rounded-md bg-gray-100 object-cover dark:bg-gray-800">
                                                        </template>
                                                        <span class="dc-break min-w-0 flex-1 truncate text-[12px] text-gray-600 dark:text-gray-300" x-text="item.name"></span>
                                                        <span
                                                            class="shrink-0 rounded-md px-1.5 py-0.5 text-[10px] font-semibold"
                                                            :class="item.telegram
                                                                ? 'bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400'
                                                                : 'bg-gray-100 text-gray-500 dark:bg-white/[0.06] dark:text-gray-400'"
                                                            :title="item.telegram ? translations.media.telegram_badge_hint : translations.media.file_badge_hint"
                                                            x-text="item.telegram ? translations.media.telegram_badge : translations.media.file_badge"
                                                        ></span>
                                                    </div>
                                                </template>
                                            </div>

                                            <button
                                                type="button"
                                                x-on:click="removeItem(slot, i)"
                                                class="dc-tap inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-gray-400
                                                       transition hover:bg-error-50 hover:text-error-600 dark:hover:bg-error-500/10 dark:hover:text-error-400"
                                                :title="translations.remove"
                                                :aria-label="translations.remove"
                                            >
                                                <x-driver-check.icon name="close" class="h-3.5 w-3.5" />
                                            </button>
                                        </div>
                                    </template>

                                    <p x-show="slots[slot].items.length === 0" class="text-[12px] text-gray-400 dark:text-gray-500">
                                        {{ __('telegram.personal_answers.no_items') }}
                                    </p>
                                </div>

                                {{-- Add: a text, a voice, a GIF from a file or from Telegram --}}
                                <div class="flex flex-wrap items-center gap-1.5 border-t border-gray-100 bg-gray-25 px-4 py-2.5 dark:border-gray-800 dark:bg-white/[0.015]">
                                    <button
                                        type="button"
                                        x-on:click="addText(slot)"
                                        class="dc-tap inline-flex h-10 items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-2.5 text-[12px] font-medium text-gray-700 transition
                                               hover:border-gray-300 hover:text-gray-950 sm:h-8 dark:border-gray-700 dark:bg-white/[0.04] dark:text-gray-200 dark:hover:text-white"
                                    >
                                        <x-driver-check.icon name="plus" class="h-3.5 w-3.5" />
                                        {{ __('telegram.personal_answers.add_text') }}
                                    </button>

                                    @foreach (['voice' => '.ogg,.oga,.opus,audio/ogg', 'gif' => '.gif,.mp4,image/gif,video/mp4'] as $type => $accept)
                                        <label
                                            class="dc-tap inline-flex h-10 cursor-pointer items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-2.5 text-[12px] font-medium text-gray-700 transition
                                                   hover:border-gray-300 hover:text-gray-950 sm:h-8 dark:border-gray-700 dark:bg-white/[0.04] dark:text-gray-200 dark:hover:text-white"
                                            :class="uploading[uploadKey(slot, '{{ $type }}')] ? 'pointer-events-none opacity-60' : ''"
                                        >
                                            <x-driver-check.icon name="plus" class="h-3.5 w-3.5" />
                                            <span
                                                x-text="uploading[uploadKey(slot, '{{ $type }}')]
                                                    ? translations.media.uploading
                                                    : translations.add_{{ $type }}"
                                            ></span>
                                            <input type="file" class="sr-only" accept="{{ $accept }}" x-on:change="uploadMedia(slot, '{{ $type }}', $event)">
                                        </label>
                                    @endforeach

                                    <button
                                        type="button"
                                        x-on:click="openTelegramGifs(slot)"
                                        class="dc-tap inline-flex h-10 items-center gap-1.5 rounded-lg px-2.5 text-[12px] font-medium text-brand-600 transition
                                               hover:bg-white hover:text-brand-700 sm:h-8 dark:text-brand-400 dark:hover:bg-white/[0.06] dark:hover:text-brand-300"
                                    >
                                        <x-driver-check.icon name="search" class="h-3.5 w-3.5" />
                                        <span x-text="translations.media.add_telegram"></span>
                                    </button>
                                </div>
                            </x-driver-check.surface>
                        </template>

                        {{-- Placeholders: into the text edited last --}}
                        <x-driver-check.surface x-show="slotKeys().length > 0" class="flex flex-col gap-2 p-4">
                            <p class="dc-eyebrow">{{ __('telegram.personal_answers.placeholders_title') }}</p>
                            <div class="flex flex-wrap gap-1.5">
                                <template x-for="placeholder in placeholders" :key="placeholder.name">
                                    <button
                                        type="button"
                                        x-on:mousedown.prevent
                                        x-on:click="insertPlaceholder(placeholder.name)"
                                        :disabled="!target"
                                        :title="placeholder.hint"
                                        class="dc-tap rounded-md bg-gray-100 px-2 py-1.5 font-mono text-[11px] text-gray-700 transition hover:bg-brand-50
                                               hover:text-brand-700 disabled:opacity-50 dark:bg-white/[0.06] dark:text-gray-300"
                                        x-text="'{' + placeholder.name + '}'"
                                    ></button>
                                </template>
                            </div>
                            <p class="text-[11px] text-gray-400 dark:text-gray-500">{{ __('telegram.personal_answers.placeholders_hint') }}</p>
                        </x-driver-check.surface>
                    </div>
                </template>
            </div>
        </div>
    </x-driver-check.shell>

    @include('pages.driver-check.partials.telegram-gif-picker')

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
                    x-text="formError || translations.actions.dirty.replace(':name', person?.name || '')"
                ></span>
            </p>

            <div class="flex gap-2">
                <x-driver-check.button x-on:click="discard()" ::disabled="saving" class="flex-1 sm:flex-none">
                    {{ __('telegram.personal_answers.actions.discard') }}
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
