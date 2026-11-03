@extends('layouts.driver-check')

@section('title', $part === 'log' ? __('driver_check.tree.pages.file_log') : __('driver_check.tree.pages.file_routes'))

@section('content')

<div
    x-data="dcFileRoutes({
        endpoints: {
            index: @js(route('api.telegram.file-routes.index')),
            store: @js(route('api.telegram.file-routes.store')),
            base: @js(url('/api/telegram/file-routes')),
        },
        translations: @js(__('telegram.file_routes')),
        ui: @js(__('telegram.ui')),
    })"
>
    <x-driver-check.shell>

        {{-- Header --}}
        <x-driver-check.page-header
            :title="$part === 'log' ? __('driver_check.tree.pages.file_log') : __('driver_check.tree.pages.file_routes')"
            :description="$part === 'log' ? __('telegram.file_routes.recent.description') : __('telegram.file_routes.description')"
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

                @if ($part === 'kinds')
                <x-driver-check.button variant="primary" icon="plus" x-on:click="openCreate()">
                    {{ __('telegram.file_routes.create') }}
                </x-driver-check.button>
                @endif
            </x-slot:actions>
        </x-driver-check.page-header>

        @if ($part === 'kinds')
        {{-- How a file finds its groups --}}
        <div
            class="flex items-start gap-2.5 rounded-2xl border border-gray-200 bg-gray-25 px-3.5 py-2.5 text-[13px] leading-relaxed text-gray-600 dark:border-gray-800 dark:bg-white/[0.02] dark:text-gray-300"
        >
            <x-driver-check.icon name="send" class="mt-0.5 h-4 w-4 shrink-0 text-gray-400" />
            <div class="min-w-0">
                <p>{{ __('telegram.file_routes.notice') }}</p>
                <p class="mt-1 text-[12px]">
                    <span class="font-semibold">{{ __('telegram.file_routes.source') }}:</span>
                    <span class="tabular-nums" x-text="sources.join(', ') || '—'"></span>
                </p>
            </div>
        </div>

        @endif

        <x-driver-check.error-alert :title="__('telegram.file_routes.errors.title')" />

        @if ($part === 'kinds')
        {{-- Kinds --}}
        <x-driver-check.surface class="overflow-hidden">
            {{-- Table (lg and up) --}}
            <div class="hidden lg:block">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[960px] table-fixed text-left">
                        <colgroup>
                            <col class="w-[20%]">
                            <col class="w-[22%]">
                            <col class="w-[22%]">
                            <col class="w-[10%]">
                            <col class="w-[12%]">
                            <col class="w-[14%]">
                        </colgroup>

                        <thead class="dc-thead border-b border-gray-200 bg-gray-50/70 dark:border-gray-800 dark:bg-white/[0.02]">
                            <tr class="text-[10.5px] uppercase tracking-[0.08em] text-gray-500 dark:text-gray-400">
                                <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('telegram.file_routes.table.name') }}</th>
                                <th scope="col" class="px-3 py-2.5 font-semibold">{{ __('telegram.file_routes.table.keywords') }}</th>
                                <th scope="col" class="px-3 py-2.5 font-semibold">{{ __('telegram.file_routes.table.targets') }}</th>
                                <th scope="col" class="px-3 py-2.5 text-right font-semibold">{{ __('telegram.file_routes.table.sent') }}</th>
                                <th scope="col" class="px-3 py-2.5 font-semibold">{{ __('telegram.file_routes.table.last_sent') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-semibold">{{ __('telegram.file_routes.table.actions') }}</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <x-driver-check.skeleton-rows :cols="6" />

                            <template x-for="row in rows" :key="row.id">
                                <tr class="align-middle transition-colors hover:bg-gray-25 dark:hover:bg-white/[0.025]">
                                    <td class="px-4 py-3">
                                        <p class="truncate text-sm font-medium text-gray-900 dark:text-white" :title="row.name" x-text="row.name"></p>
                                        <span class="mt-0.5 inline-flex items-center gap-1.5 text-[11px] text-gray-500 dark:text-gray-400">
                                            <span class="h-1.5 w-1.5 rounded-full" :class="row.is_active ? 'bg-success-500' : 'bg-gray-400'"></span>
                                            <span x-text="row.is_active ? translations.status.active : translations.status.paused"></span>
                                        </span>
                                    </td>

                                    <td class="px-3 py-3">
                                        <div class="flex flex-wrap gap-1">
                                            <template x-for="keyword in keywordList(row)" :key="keyword">
                                                <span
                                                    class="rounded-md bg-gray-100 px-1.5 py-0.5 font-mono text-[11px] text-gray-700
                                                           dark:bg-white/[0.06] dark:text-gray-300"
                                                    x-text="keyword"
                                                ></span>
                                            </template>
                                        </div>
                                    </td>

                                    <td class="px-3 py-3">
                                        <div class="flex flex-wrap gap-1">
                                            <template x-for="id in row.target_chat_ids" :key="id">
                                                <span
                                                    class="rounded-md bg-gray-100 px-1.5 py-0.5 text-[11px] tabular-nums text-gray-700
                                                           dark:bg-white/[0.06] dark:text-gray-300"
                                                    x-text="id"
                                                ></span>
                                            </template>

                                            <span
                                                x-show="row.target_chat_ids.length === 0"
                                                class="text-[12px] text-warning-600 dark:text-warning-400"
                                                x-text="translations.table.no_targets"
                                            ></span>
                                        </div>
                                    </td>

                                    <td
                                        class="px-3 py-3 text-right text-sm tabular-nums text-gray-700 dark:text-gray-300"
                                    >
                                        <span x-text="number(row.sent_count)"></span>
                                        <p
                                            class="mt-0.5 truncate text-[11px] font-normal text-gray-500 dark:text-gray-400"
                                            :title="translations.table.sender + ': ' + senderName(row)"
                                            x-text="senderName(row)"
                                        ></p>
                                    </td>

                                    <td class="px-3 py-3">
                                        <p
                                            class="text-[13px] text-gray-700 dark:text-gray-300"
                                            :title="row.last_sent_at ? date(row.last_sent_at) : ''"
                                            x-text="row.last_sent_at ? relative(row.last_sent_at) : translations.table.never"
                                        ></p>
                                    </td>

                                    <td class="px-4 py-3 text-right">
                                        <x-driver-check.row-action icon="pencil" :label="__('telegram.file_routes.table.edit')" x-on:click="openEdit(row)" />
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Cards (below lg) --}}
            <div class="divide-y divide-gray-100 lg:hidden dark:divide-gray-800">
                <x-driver-check.skeleton-cards />

                <template x-for="row in rows" :key="'card-' + row.id">
                    <article class="flex flex-col gap-3 p-3.5">
                        <div class="flex items-start justify-between gap-3">
                            <p class="dc-break text-sm font-semibold text-gray-900 dark:text-white" x-text="row.name"></p>

                            <span class="inline-flex shrink-0 items-center gap-1.5 text-[12.5px] font-medium text-gray-700 dark:text-gray-200">
                                <span class="h-1.5 w-1.5 rounded-full" :class="row.is_active ? 'bg-success-500' : 'bg-gray-400'"></span>
                                <span x-text="row.is_active ? translations.status.active : translations.status.paused"></span>
                            </span>
                        </div>

                        <dl class="grid grid-cols-2 gap-x-3 gap-y-2.5 rounded-xl bg-gray-25 p-3 dark:bg-white/[0.02]">
                            <x-driver-check.kv :label="__('telegram.file_routes.table.keywords')" class="col-span-2">
                                <span class="dc-break font-mono text-[12px]" x-text="keywordList(row).join(', ')"></span>
                            </x-driver-check.kv>

                            <x-driver-check.kv :label="__('telegram.file_routes.table.targets')" class="col-span-2">
                                <span
                                    class="dc-break tabular-nums"
                                    x-text="row.target_chat_ids.length ? row.target_chat_ids.join(', ') : translations.table.no_targets"
                                ></span>
                            </x-driver-check.kv>

                            <x-driver-check.kv :label="__('telegram.file_routes.table.sender')" class="col-span-2">
                                <span class="dc-break" x-text="senderName(row)"></span>
                            </x-driver-check.kv>

                            <x-driver-check.kv :label="__('telegram.file_routes.table.sent')">
                                <span class="tabular-nums" x-text="number(row.sent_count)"></span>
                            </x-driver-check.kv>

                            <x-driver-check.kv :label="__('telegram.file_routes.table.last_sent')">
                                <span x-text="row.last_sent_at ? relative(row.last_sent_at) : translations.table.never"></span>
                            </x-driver-check.kv>
                        </dl>

                        <x-driver-check.button class="w-full" x-on:click="openEdit(row)">
                            {{ __('telegram.file_routes.table.edit') }}
                        </x-driver-check.button>
                    </article>
                </template>
            </div>

            <x-driver-check.empty-state
                icon="send"
                :title="__('telegram.file_routes.empty.title')"
                :description="__('telegram.file_routes.empty.description')"
            />
        </x-driver-check.surface>

        @endif

        @if ($part === 'log')
        {{-- Recent forwards --}}
        <x-driver-check.surface class="overflow-hidden">
            <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('telegram.file_routes.recent.title') }}</p>
                <p class="mt-0.5 text-[12px] text-gray-500 dark:text-gray-400">{{ __('telegram.file_routes.recent.description') }}</p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-left">
                    <thead class="dc-thead border-b border-gray-200 bg-gray-50/70 dark:border-gray-800 dark:bg-white/[0.02]">
                        <tr class="text-[10.5px] uppercase tracking-[0.08em] text-gray-500 dark:text-gray-400">
                            <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('telegram.file_routes.recent.time') }}</th>
                            <th scope="col" class="px-3 py-2.5 font-semibold">{{ __('telegram.file_routes.recent.file') }}</th>
                            <th scope="col" class="px-3 py-2.5 font-semibold">{{ __('telegram.file_routes.recent.route') }}</th>
                            <th scope="col" class="px-3 py-2.5 font-semibold">{{ __('telegram.file_routes.recent.target') }}</th>
                            <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('telegram.file_routes.recent.status') }}</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <template x-for="item in recent" :key="item.id">
                            <tr class="align-top transition-colors hover:bg-gray-25 dark:hover:bg-white/[0.025]">
                                <td
                                    class="whitespace-nowrap px-4 py-2.5 text-[13px] text-gray-700 dark:text-gray-300"
                                    :title="date(item.created_at)"
                                    x-text="relative(item.created_at)"
                                ></td>

                                <td class="px-3 py-2.5">
                                    <p class="dc-break text-[13px] text-gray-900 dark:text-white" x-text="forwardFile(item)"></p>
                                    <p
                                        x-show="item.caption && item.file_name"
                                        class="dc-break text-[11px] text-gray-500 dark:text-gray-400"
                                        x-text="item.file_name"
                                    ></p>
                                </td>

                                <td class="px-3 py-2.5 text-[13px] text-gray-700 dark:text-gray-300" x-text="item.route || translations.recent.no_route"></td>

                                <td class="px-3 py-2.5 text-[13px] tabular-nums text-gray-700 dark:text-gray-300" x-text="item.target_chat_id || '—'"></td>

                                <td class="px-4 py-2.5">
                                    <x-driver-check.badge ::class="forwardStatusClass(item.status)">
                                        <span x-text="translations.forward_status[item.status] || item.status"></span>
                                    </x-driver-check.badge>

                                    <p
                                        x-show="item.error"
                                        class="dc-break mt-1 line-clamp-2 text-[11px] text-error-600 dark:text-error-400"
                                        :title="item.error"
                                        x-text="item.error"
                                    ></p>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <p
                x-show="!loading && recent.length === 0"
                x-cloak
                class="px-4 py-8 text-center text-[13px] text-gray-500 dark:text-gray-400"
            >{{ __('telegram.file_routes.recent.empty') }}</p>
        </x-driver-check.surface>
        @endif
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

        <form id="file-route-form" x-on:submit.prevent="save()" class="flex flex-col gap-4">

            <x-driver-check.field
                :label="__('telegram.file_routes.form.name')"
                :hint="__('telegram.file_routes.form.name_hint')"
                for="file-route-name"
            >
                <x-driver-check.input
                    id="file-route-name"
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
                :label="__('telegram.file_routes.form.keywords')"
                :hint="__('telegram.file_routes.form.keywords_hint')"
                for="file-route-keywords"
            >
                <x-driver-check.input
                    id="file-route-keywords"
                    x-model="form.keywords"
                    required
                    autocomplete="off"
                    spellcheck="false"
                    placeholder="benyakoni"
                    ::data-invalid="!!fieldError('keywords')"
                />

                <x-slot:error>
                    <p x-show="fieldError('keywords')" x-cloak class="mt-1 text-[11px] font-medium text-error-600 dark:text-error-400" x-text="fieldError('keywords')"></p>
                </x-slot:error>
            </x-driver-check.field>

            <x-driver-check.field
                :label="__('telegram.file_routes.form.targets')"
                :hint="__('telegram.file_routes.form.targets_hint')"
                for="file-route-targets"
            >
                <textarea
                    id="file-route-targets"
                    x-model="form.targets"
                    rows="3"
                    spellcheck="false"
                    placeholder="-1004431008937"
                    class="w-full resize-y rounded-lg border bg-white px-3 py-2 font-mono text-[13px] leading-relaxed
                           text-gray-900 outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10
                           dark:bg-gray-900 dark:text-white"
                    :class="fieldError('target_chat_ids')
                        ? 'border-error-400 dark:border-error-500/60'
                        : 'border-gray-300 dark:border-gray-700'"
                ></textarea>

                <x-slot:error>
                    <p x-show="fieldError('target_chat_ids')" x-cloak class="mt-1 text-[11px] font-medium text-error-600 dark:text-error-400" x-text="fieldError('target_chat_ids')"></p>
                </x-slot:error>
            </x-driver-check.field>

            <x-driver-check.field
                :label="__('telegram.file_routes.form.sender')"
                :hint="__('telegram.file_routes.form.sender_hint')"
                for="file-route-sender"
            >
                <select
                    id="file-route-sender"
                    x-model="form.listener_key"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-[14px] text-gray-900 outline-none transition
                           focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                >
                    <option value="">{{ __('telegram.file_routes.form.sender_default') }}</option>
                    <template x-for="account in accounts" :key="account.key">
                        <option :value="account.key" :selected="form.listener_key === account.key" x-text="account.name"></option>
                    </template>
                </select>

                <x-slot:error>
                    <p x-show="fieldError('listener_key')" x-cloak class="mt-1 text-[11px] font-medium text-error-600 dark:text-error-400" x-text="fieldError('listener_key')"></p>
                </x-slot:error>
            </x-driver-check.field>

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
                    <span class="block text-sm font-medium text-gray-900 dark:text-white">
                        {{ __('telegram.file_routes.form.is_active') }}
                    </span>
                    <span class="mt-0.5 block text-[11px] leading-snug text-gray-500 dark:text-gray-400">
                        {{ __('telegram.file_routes.form.is_active_hint') }}
                    </span>
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
                <span class="text-error-600 dark:text-error-400">
                    {{ __('telegram.file_routes.form.delete') }}
                </span>
            </x-driver-check.button>

            <x-driver-check.button x-on:click="closeForm()" ::disabled="saving">
                {{ __('telegram.file_routes.form.cancel') }}
            </x-driver-check.button>

            <x-driver-check.button
                variant="primary"
                type="submit"
                form="file-route-form"
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

    {{-- Delete confirmation --}}
    <x-driver-check.modal
        open="confirmOpen"
        close="closeConfirm()"
        size="sm:max-w-md"
        :title="__('telegram.file_routes.confirm.delete_title')"
    >
        <p class="text-[13px] leading-relaxed text-gray-600 dark:text-gray-300">
            {{ __('telegram.file_routes.confirm.delete_text') }}
        </p>

        <p
            class="dc-break mt-3 rounded-xl bg-gray-50 px-3 py-2 text-[13px] font-medium text-gray-700
                   dark:bg-white/[0.03] dark:text-gray-200"
            x-text="form.name"
        ></p>

        <p
            x-show="confirmError"
            x-cloak
            class="dc-break mt-3 rounded-xl bg-error-50 px-3 py-2.5 text-xs text-error-600
                   dark:bg-error-500/10 dark:text-error-400"
            x-text="confirmError"
        ></p>

        <x-slot:footer>
            <x-driver-check.button x-on:click="closeConfirm()" ::disabled="deleting">
                {{ __('telegram.file_routes.confirm.cancel') }}
            </x-driver-check.button>

            <x-driver-check.button
                variant="danger"
                x-on:click="destroy()"
                ::disabled="deleting"
            >
                <x-driver-check.icon
                    name="refresh"
                    class="h-4 w-4 animate-spin"
                    x-show="deleting"
                    x-cloak
                />
                {{ __('telegram.file_routes.confirm.delete') }}
            </x-driver-check.button>
        </x-slot:footer>
    </x-driver-check.modal>
</div>

@endsection
