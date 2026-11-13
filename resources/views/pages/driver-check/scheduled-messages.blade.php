@extends('layouts.driver-check')

@section('title', __('telegram.scheduled.title'))

@section('content')

<div
    x-data="dcScheduledMessages({
        endpoints: {
            index: @js(route('api.telegram.scheduled-messages.index')),
            store: @js(route('api.telegram.scheduled-messages.store')),
            base: @js(url('/api/telegram/scheduled-messages')),
        },
        translations: @js(__('telegram.scheduled')),
        ui: @js(__('telegram.ui')),
    })"
>
    <x-driver-check.shell>

        {{-- Header --}}
        <x-driver-check.page-header
            icon="clock"
            :title="__('telegram.scheduled.title')"
            :description="__('telegram.scheduled.description')"
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
                    {{ __('telegram.scheduled.create') }}
                </x-driver-check.button>
            </x-slot:actions>
        </x-driver-check.page-header>

        <div
            class="flex items-start gap-2.5 rounded-2xl border border-gray-200 bg-gray-25 px-3.5 py-2.5 text-[13px] leading-relaxed text-gray-600 dark:border-gray-800 dark:bg-white/[0.02] dark:text-gray-300"
        >
            <x-driver-check.icon name="send" class="mt-0.5 h-4 w-4 shrink-0 text-gray-400" />
            <p>{{ __('telegram.scheduled.notice') }}</p>
        </div>

        <x-driver-check.error-alert :title="__('telegram.scheduled.errors.title')" />

        {{-- Messages --}}
        <div class="flex flex-col gap-3">
            <x-driver-check.skeleton-cards />

            <template x-for="row in rows" :key="row.id">
                <x-driver-check.surface class="p-4">
                    <article class="flex flex-col gap-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="dc-break text-sm font-semibold text-gray-900 dark:text-white" x-text="row.name"></p>
                                <p class="mt-0.5 text-[12px] text-gray-500 dark:text-gray-400">
                                    <span class="font-semibold tabular-nums text-gray-700 dark:text-gray-200" x-text="row.send_at"></span>
                                    <span x-show="row.jitter_minutes > 0" x-cloak class="tabular-nums" x-text="'±' + row.jitter_minutes"></span>
                                    <span> · </span>
                                    <span x-text="daysLabel(row.days)"></span>
                                    <span> · </span>
                                    <span x-text="senderLabel(row.listener_key)"></span>
                                </p>
                            </div>

                            <label class="dc-tap flex shrink-0 cursor-pointer items-center gap-2">
                                <input
                                    type="checkbox"
                                    :checked="row.is_active"
                                    x-on:change="toggleActive(row)"
                                    class="h-5 w-5 rounded-md border-gray-300 text-brand-500
                                           focus:ring-brand-500/30 dark:border-gray-600 dark:bg-gray-900"
                                >
                                <span
                                    class="text-[12.5px] font-medium text-gray-700 dark:text-gray-200"
                                    x-text="row.is_active ? translations.status.active : translations.status.paused"
                                ></span>
                            </label>
                        </div>

                        <p
                            x-show="row.text"
                            class="dc-break whitespace-pre-line rounded-xl bg-gray-25 px-3 py-2.5 text-[13px] text-gray-800 dark:bg-white/[0.03] dark:text-gray-200"
                            x-text="row.text"
                        ></p>

                        {{-- Other wordings: each group gets one of them, picked at random. --}}
                        <div x-show="row.variants.length > 0" x-cloak class="flex flex-col gap-1.5">
                            <p class="text-[11.5px] font-semibold text-gray-500 dark:text-gray-400">
                                <span x-text="translations.table.variants"></span>
                                <span class="tabular-nums" x-text="'· ' + row.variants.length"></span>
                            </p>

                            <template x-for="(variant, index) in row.variants" :key="index">
                                <p
                                    class="dc-break whitespace-pre-line rounded-xl bg-gray-25 px-3 py-2 text-[12.5px] text-gray-700 dark:bg-white/[0.03] dark:text-gray-300"
                                    x-text="variant"
                                ></p>
                            </template>
                        </div>

                        {{-- A slot set up in advance: the text is pasted in with "Edit". --}}
                        <button
                            type="button"
                            x-show="!row.has_text"
                            x-cloak
                            x-on:click="openEdit(row)"
                            class="dc-tap rounded-xl border border-dashed border-warning-300 bg-warning-25 px-3 py-2.5 text-left text-[13px]
                                   text-warning-700 transition hover:bg-warning-50 dark:border-warning-500/40 dark:bg-warning-500/[0.06]
                                   dark:text-warning-400 dark:hover:bg-warning-500/10"
                            x-text="translations.table.no_text"
                        ></button>

                        <dl class="grid grid-cols-1 gap-x-3 gap-y-2.5 sm:grid-cols-3">
                            <x-driver-check.kv :label="__('telegram.scheduled.table.groups')">
                                <span class="dc-break tabular-nums text-[12.5px]">
                                    <template x-for="id in row.chat_ids" :key="id">
                                        <span class="mr-1 inline-block rounded-md bg-gray-100 px-1.5 py-0.5 dark:bg-white/[0.06]" x-text="groupLabel(id)"></span>
                                    </template>
                                    <span
                                        x-show="row.chat_ids.length === 0"
                                        class="text-warning-600 dark:text-warning-400"
                                        x-text="translations.table.no_groups"
                                    ></span>
                                </span>
                            </x-driver-check.kv>

                            <x-driver-check.kv :label="__('telegram.scheduled.table.next')">
                                <span class="text-[13px]" x-text="nextLabel(row)"></span>
                            </x-driver-check.kv>

                            <x-driver-check.kv :label="__('telegram.scheduled.table.last')">
                                <span class="flex flex-wrap items-center gap-2 text-[13px]">
                                    <span
                                        x-show="row.queued"
                                        x-cloak
                                        class="text-[12px] text-gray-500 dark:text-gray-400"
                                        x-text="translations.table.queued"
                                    ></span>

                                    <span
                                        x-show="!row.queued && !row.last_run_at"
                                        x-text="translations.table.never"
                                    ></span>

                                    <template x-if="!row.queued && row.last_run_at">
                                        <span class="flex flex-wrap items-center gap-2">
                                            <span :title="date(row.last_run_at)" x-text="relative(row.last_run_at)"></span>
                                            <x-driver-check.badge ::class="statusClass(row.last_status)">
                                                <span x-text="translations.last_status[row.last_status] || row.last_status"></span>
                                            </x-driver-check.badge>
                                        </span>
                                    </template>
                                </span>
                            </x-driver-check.kv>
                        </dl>

                        <p
                            x-show="row.last_error"
                            x-cloak
                            class="dc-break whitespace-pre-line rounded-xl bg-error-50 px-3 py-2 text-[11.5px] text-error-600
                                   dark:bg-error-500/10 dark:text-error-400"
                            x-text="row.last_error"
                        ></p>

                        <div class="flex flex-wrap gap-2">
                            <x-driver-check.button x-on:click="openEdit(row)">
                                {{ __('telegram.scheduled.table.edit') }}
                            </x-driver-check.button>

                            <x-driver-check.button x-on:click="ask('send', row)" ::disabled="row.queued || !row.has_text || row.chat_ids.length === 0">
                                <x-driver-check.icon name="send" class="h-4 w-4" />
                                {{ __('telegram.scheduled.table.send_now') }}
                            </x-driver-check.button>
                        </div>
                    </article>
                </x-driver-check.surface>
            </template>
        </div>

        <x-driver-check.empty-state
            icon="clock"
            :title="__('telegram.scheduled.empty.title')"
            :description="__('telegram.scheduled.empty.description')"
        />
    </x-driver-check.shell>

    {{-- Create / edit dialog --}}
    <x-driver-check.modal
        open="formOpen"
        close="closeForm()"
        size="sm:max-w-lg"
    >
        <x-slot:heading>
            <span x-text="form.id ? translations.form.edit_title : translations.form.create_title"></span>
        </x-slot:heading>

        <form id="scheduled-message-form" x-on:submit.prevent="save()" class="flex flex-col gap-4">

            <x-driver-check.field
                :label="__('telegram.scheduled.form.name')"
                :hint="__('telegram.scheduled.form.name_hint')"
                for="scheduled-name"
            >
                <x-driver-check.input
                    id="scheduled-name"
                    x-ref="firstField"
                    x-model="form.name"
                    required
                    autocomplete="off"
                    ::data-invalid="!!fieldError('name')"
                />

                <x-slot:error>
                    <p x-show="fieldError('name')" x-cloak class="mt-1 text-[11px] font-medium text-error-600 dark:text-error-400" x-text="fieldError('name')"></p>
                </x-slot:error>
            </x-driver-check.field>

            <x-driver-check.field
                :label="__('telegram.scheduled.form.text')"
                :hint="__('telegram.scheduled.form.text_hint')"
                for="scheduled-text"
            >
                <textarea
                    id="scheduled-text"
                    x-model="form.text"
                    rows="3"
                    maxlength="4000"
                    class="w-full resize-y rounded-lg border bg-white px-3 py-2 text-[14px] leading-relaxed
                           text-gray-900 outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10
                           dark:bg-gray-900 dark:text-white"
                    :class="fieldError('text')
                        ? 'border-error-400 dark:border-error-500/60'
                        : 'border-gray-300 dark:border-gray-700'"
                ></textarea>

                <x-slot:error>
                    <p x-show="fieldError('text')" x-cloak class="mt-1 text-[11px] font-medium text-error-600 dark:text-error-400" x-text="fieldError('text')"></p>
                </x-slot:error>
            </x-driver-check.field>

            {{-- Variants: other wordings of the same message. --}}
            <div>
                <p class="text-[12px] font-semibold text-gray-600 dark:text-gray-400">{{ __('telegram.scheduled.form.variants') }}</p>
                <p class="mt-0.5 mb-2 text-[11.5px] leading-snug text-gray-400 dark:text-gray-500">{{ __('telegram.scheduled.form.variants_hint') }}</p>

                <div class="flex flex-col gap-2">
                    <template x-for="(variant, index) in form.variants" :key="index">
                        <div class="flex items-start gap-2">
                            <textarea
                                data-variant
                                x-model="form.variants[index]"
                                rows="2"
                                maxlength="4000"
                                :placeholder="translations.form.variant_placeholder"
                                class="w-full resize-y rounded-lg border border-gray-300 bg-white px-3 py-2 text-[14px] leading-relaxed
                                       text-gray-900 outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10
                                       dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                            ></textarea>

                            <button
                                type="button"
                                x-on:click="removeVariant(index)"
                                :aria-label="translations.form.remove_variant"
                                :title="translations.form.remove_variant"
                                class="dc-tap flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-lg leading-none text-gray-400
                                       transition hover:bg-gray-100 hover:text-error-600 dark:hover:bg-white/[0.06] dark:hover:text-error-400"
                            >&times;</button>
                        </div>
                    </template>
                </div>

                <x-driver-check.button type="button" class="mt-2" icon="plus" x-on:click="addVariant()">
                    {{ __('telegram.scheduled.form.add_variant') }}
                </x-driver-check.button>

                <p x-show="fieldError('variants')" x-cloak class="mt-1 text-[11px] font-medium text-error-600 dark:text-error-400" x-text="fieldError('variants')"></p>
            </div>

            <x-driver-check.field
                x-show="listeners.length > 1"
                x-cloak
                :label="__('telegram.scheduled.form.sender')"
                :hint="__('telegram.scheduled.form.sender_hint')"
                for="scheduled-sender"
            >
                <select
                    id="scheduled-sender"
                    x-model="form.listener_key"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-[14px] text-gray-900 outline-none transition
                           focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                >
                    <template x-for="listener in listeners" :key="listener.key || 'driver_check'">
                        <option :value="listener.key || ''" x-text="listener.name"></option>
                    </template>
                </select>
            </x-driver-check.field>

            <div class="flex flex-wrap items-start gap-4">
                <x-driver-check.field :label="__('telegram.scheduled.form.time')" for="scheduled-time" class="sm:w-40">
                    <x-driver-check.input
                        id="scheduled-time"
                        type="time"
                        x-model="form.send_at"
                        required
                        ::data-invalid="!!fieldError('send_at')"
                    />
                </x-driver-check.field>

                <x-driver-check.field
                    :label="__('telegram.scheduled.form.jitter')"
                    :hint="__('telegram.scheduled.form.jitter_hint')"
                    for="scheduled-jitter"
                    class="min-w-0 flex-1 sm:max-w-sm"
                >
                    <x-driver-check.input
                        id="scheduled-jitter"
                        type="number"
                        min="0"
                        max="30"
                        inputmode="numeric"
                        x-model.number="form.jitter_minutes"
                        ::data-invalid="!!fieldError('jitter_minutes')"
                    />
                </x-driver-check.field>
            </div>

            <label
                class="dc-tap flex cursor-pointer items-start gap-3 rounded-2xl border p-3.5 transition"
                :class="form.skip_if_file_today
                    ? 'border-brand-500 bg-brand-25 dark:border-brand-500/50 dark:bg-brand-500/[0.07]'
                    : 'border-gray-200 bg-white hover:bg-gray-50 dark:border-gray-800 dark:bg-transparent dark:hover:bg-white/[0.03]'"
            >
                <input
                    type="checkbox"
                    x-model="form.skip_if_file_today"
                    class="mt-0.5 h-5 w-5 shrink-0 rounded-md border-gray-300 text-brand-500
                           focus:ring-brand-500/30 dark:border-gray-600 dark:bg-gray-900"
                >

                <span class="min-w-0">
                    <span class="block text-sm font-medium text-gray-900 dark:text-white">{{ __('telegram.scheduled.form.skip_file') }}</span>
                    <span class="mt-0.5 block text-[11px] leading-snug text-gray-500 dark:text-gray-400">{{ __('telegram.scheduled.form.skip_file_hint') }}</span>
                </span>
            </label>

            <div>
                <p class="mb-1.5 text-[12px] font-semibold text-gray-600 dark:text-gray-400">{{ __('telegram.scheduled.form.days') }}</p>

                <div class="flex flex-wrap gap-1.5">
                    <template x-for="(label, index) in translations.days.short" :key="index">
                        <button
                            type="button"
                            x-on:click="toggleDay(index + 1)"
                            class="dc-tap h-9 min-w-[2.75rem] rounded-xl border px-3 text-[13px] font-medium transition"
                            :class="hasDay(index + 1)
                                ? 'border-brand-500 bg-brand-25 text-gray-900 dark:border-brand-500/50 dark:bg-brand-500/[0.07] dark:text-white'
                                : 'border-gray-200 bg-white text-gray-500 hover:bg-gray-50 dark:border-gray-800 dark:bg-transparent dark:text-gray-400 dark:hover:bg-white/[0.03]'"
                            x-text="label"
                        ></button>
                    </template>
                </div>
            </div>

            <div>
                <p class="mb-1.5 text-[12px] font-semibold text-gray-600 dark:text-gray-400">{{ __('telegram.scheduled.form.groups') }}</p>

                <div class="flex flex-wrap gap-2">
                    <template x-for="id in formGroups()" :key="id">
                        <label
                            class="dc-tap flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-2 text-[13px] transition"
                            :class="hasGroup(id)
                                ? 'border-brand-500 bg-brand-25 dark:border-brand-500/50 dark:bg-brand-500/[0.07]'
                                : 'border-gray-200 bg-white hover:bg-gray-50 dark:border-gray-800 dark:bg-transparent dark:hover:bg-white/[0.03]'"
                        >
                            <input
                                type="checkbox"
                                :checked="hasGroup(id)"
                                x-on:change="toggleGroup(id)"
                                class="h-4 w-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500/30 dark:border-gray-600 dark:bg-gray-900"
                            >
                            <span class="tabular-nums" x-text="groupLabel(id)"></span>
                        </label>
                    </template>
                </div>

                <div class="mt-2 flex gap-2">
                    <x-driver-check.input
                        x-model="extraGroup"
                        x-on:keydown.enter.prevent="addExtraGroup()"
                        inputmode="numeric"
                        spellcheck="false"
                        autocomplete="off"
                        placeholder="-1004431008937"
                    />
                    <x-driver-check.button type="button" x-on:click="addExtraGroup()">
                        {{ __('telegram.scheduled.form.add_group') }}
                    </x-driver-check.button>
                </div>

                <p class="mt-1.5 text-[11.5px] leading-snug text-gray-400 dark:text-gray-500">{{ __('telegram.scheduled.form.groups_hint') }}</p>

                <p x-show="fieldError('chat_ids')" x-cloak class="mt-1 text-[11px] font-medium text-error-600 dark:text-error-400" x-text="fieldError('chat_ids')"></p>
            </div>

            <label
                class="dc-tap flex cursor-pointer items-start gap-3 rounded-2xl border p-3.5 transition"
                :class="form.is_active
                    ? 'border-brand-500 bg-brand-25 dark:border-brand-500/50 dark:bg-brand-500/[0.07]'
                    : 'border-gray-200 bg-white hover:bg-gray-50 dark:border-gray-800 dark:bg-transparent dark:hover:bg-white/[0.03]'"
            >
                <input
                    type="checkbox"
                    x-model="form.is_active"
                    class="mt-0.5 h-5 w-5 shrink-0 rounded-md border-gray-300 text-brand-500
                           focus:ring-brand-500/30 dark:border-gray-600 dark:bg-gray-900"
                >

                <span class="min-w-0">
                    <span class="block text-sm font-medium text-gray-900 dark:text-white">{{ __('telegram.scheduled.form.is_active') }}</span>
                    <span class="mt-0.5 block text-[11px] leading-snug text-gray-500 dark:text-gray-400">{{ __('telegram.scheduled.form.is_active_hint') }}</span>
                </span>
            </label>

            <p
                x-show="formError"
                x-cloak
                class="dc-break rounded-xl bg-error-50 px-3 py-2.5 text-xs text-error-600
                       dark:bg-error-500/10 dark:text-error-400"
                x-text="formError"
            ></p>
        </form>

        <x-slot:footer>
            <x-driver-check.button
                x-show="form.id"
                x-cloak
                variant="ghost"
                class="sm:mr-auto"
                x-on:click="askDelete()"
                ::disabled="saving"
            >
                <span class="text-error-600 dark:text-error-400">{{ __('telegram.scheduled.form.delete') }}</span>
            </x-driver-check.button>

            <x-driver-check.button x-on:click="closeForm()" ::disabled="saving">
                {{ __('telegram.scheduled.form.cancel') }}
            </x-driver-check.button>

            <x-driver-check.button
                variant="primary"
                type="submit"
                form="scheduled-message-form"
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

    {{-- Send now / delete confirmation --}}
    <x-driver-check.modal
        open="confirm.open"
        close="closeConfirm()"
        size="sm:max-w-md"
    >
        <x-slot:heading>
            <span x-text="confirm.kind === 'delete' ? translations.confirm.delete_title : translations.confirm.send_title"></span>
        </x-slot:heading>

        <p
            class="text-[13px] leading-relaxed text-gray-600 dark:text-gray-300"
            x-text="confirm.kind === 'delete' ? translations.confirm.delete_text : translations.confirm.send_text"
        ></p>

        <p
            class="dc-break mt-3 rounded-xl bg-gray-50 px-3 py-2 text-[13px] font-medium text-gray-700
                   dark:bg-white/[0.03] dark:text-gray-200"
            x-text="confirm.row?.name"
        ></p>

        <p
            x-show="confirm.error"
            x-cloak
            class="dc-break mt-3 rounded-xl bg-error-50 px-3 py-2.5 text-xs text-error-600
                   dark:bg-error-500/10 dark:text-error-400"
            x-text="confirm.error"
        ></p>

        <x-slot:footer>
            <x-driver-check.button x-on:click="closeConfirm()" ::disabled="confirm.busy">
                {{ __('telegram.scheduled.confirm.cancel') }}
            </x-driver-check.button>

            <x-driver-check.button
                x-show="confirm.kind === 'delete'"
                x-cloak
                variant="danger"
                x-on:click="runConfirm()"
                ::disabled="confirm.busy"
            >
                {{ __('telegram.scheduled.confirm.delete') }}
            </x-driver-check.button>

            <x-driver-check.button
                x-show="confirm.kind !== 'delete'"
                x-cloak
                variant="primary"
                x-on:click="runConfirm()"
                ::disabled="confirm.busy"
            >
                {{ __('telegram.scheduled.confirm.send') }}
            </x-driver-check.button>
        </x-slot:footer>
    </x-driver-check.modal>
</div>

@endsection
