@extends('layouts.driver-check')

@section('title', $part === 'duties' ? __('driver_check.tree.pages.listener_duties') : __('telegram.listeners.title'))

@section('content')

<div
    x-data="dcListeners({
        endpoints: {
            index: @js(route('api.telegram.listeners.index')),
            store: @js(route('api.telegram.listeners.store')),
            base: @js(url('/api/telegram/listeners')),
            owners: @js(route('api.telegram.listeners.owners')),
        },
        translations: @js(__('telegram.listeners')),
        ui: @js(__('telegram.ui')),
    })"
>
    <x-driver-check.shell>

        {{-- Header --}}
        <x-driver-check.page-header
            :title="$part === 'duties' ? __('driver_check.tree.pages.listener_duties') : __('telegram.listeners.title')"
            :description="$part === 'duties' ? __('telegram.listeners.owners.description') : __('telegram.listeners.description')"
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

                @if ($part === 'list')
                <x-driver-check.button variant="primary" icon="plus" x-on:click="openCreate()">
                    {{ __('telegram.listeners.create') }}
                </x-driver-check.button>
                @endif
            </x-slot:actions>
        </x-driver-check.page-header>

        @if ($part === 'list')
        <div
            class="flex items-start gap-2.5 rounded-2xl border border-gray-200 bg-gray-25 px-3.5 py-2.5 text-[13px] leading-relaxed text-gray-600 dark:border-gray-800 dark:bg-white/[0.02] dark:text-gray-300"
        >
            <x-driver-check.icon name="bot" class="mt-0.5 h-4 w-4 shrink-0 text-gray-400" />
            <p>{{ __('telegram.listeners.notice') }}</p>
        </div>

        @endif

        <x-driver-check.error-alert :title="__('telegram.listeners.errors.title')" />

        @if ($part === 'list')
        <p class="text-[12.5px] text-gray-600 dark:text-gray-300">
            {{ __('telegram.listeners.processes.moved') }}
            <a href="{{ url('/driver-check/watchdog') }}" class="font-medium text-brand-600 hover:underline dark:text-brand-400">{{ __('telegram.listeners.processes.moved_link') }}</a>
        </p>

        @endif

        @if ($part === 'duties')
        {{-- Who does what --}}
        <x-driver-check.surface class="p-4">
            <div class="flex flex-col gap-4">
                <div class="flex flex-col divide-y divide-gray-100 dark:divide-gray-800">
                    {{-- What stays with Driver Check --}}
                    <div class="flex flex-col gap-1 py-3 first:pt-0 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                        <div class="min-w-0">
                            <p class="text-[13px] font-medium text-gray-900 dark:text-white">{{ __('telegram.listeners.owners.fixed.name') }}</p>
                            <p class="text-[11.5px] text-gray-500 dark:text-gray-400">{{ __('telegram.listeners.owners.fixed.hint') }}</p>
                        </div>
                        <span class="shrink-0 rounded-lg bg-gray-100 px-3 py-1.5 text-[13px] font-medium text-gray-700 dark:bg-white/[0.06] dark:text-gray-200">Driver Check</span>
                    </div>

                    <template x-for="item in owners.duties" :key="item.duty">
                        <div class="flex flex-col gap-2 py-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                            <div class="min-w-0">
                                <p class="text-[13px] font-medium text-gray-900 dark:text-white" x-text="translations.owners.duties[item.duty].name"></p>
                                <p class="text-[11.5px] text-gray-500 dark:text-gray-400" x-text="translations.owners.duties[item.duty].hint"></p>

                                <p
                                    x-show="ownerFallback(item)"
                                    x-cloak
                                    class="mt-1 text-[11.5px] text-warning-600 dark:text-warning-400"
                                    x-text="translations.owners.fallback"
                                ></p>

                                <p
                                    x-show="ownerIdle(item.duty)"
                                    x-cloak
                                    class="mt-1 text-[11.5px] text-warning-600 dark:text-warning-400"
                                    x-text="translations.owners.idle"
                                ></p>
                            </div>

                            <select
                                x-model="ownerForm[item.duty]"
                                class="w-full shrink-0 rounded-lg border border-gray-300 bg-white px-3 py-2 text-[14px] text-gray-900 outline-none transition
                                       focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 sm:w-56 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                            >
                                <template x-for="option in owners.options" :key="option.key || 'driver_check'">
                                    <option :value="option.key || ''" :selected="(ownerForm[item.duty] || '') === (option.key || '')" x-text="option.name"></option>
                                </template>
                            </select>
                        </div>
                    </template>

                    {{-- Penalties by role --}}
                    <div class="flex flex-col gap-3 py-3">
                        <div class="min-w-0">
                            <p class="text-[13px] font-medium text-gray-900 dark:text-white">{{ __('telegram.listeners.owners.roles.title') }}</p>
                            <p class="text-[11.5px] text-gray-500 dark:text-gray-400">{{ __('telegram.listeners.owners.roles.hint') }}</p>
                        </div>

                        <template x-for="item in owners.roles" :key="item.role">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                                <div class="min-w-0">
                                    <p class="text-[13px] text-gray-900 dark:text-white" x-text="translations.owners.roles.names[item.role]"></p>
                                    <p class="text-[11.5px] text-gray-500 dark:text-gray-400">
                                        <span x-text="translations.owners.roles.now"></span>
                                        <span class="font-medium" x-text="roleOwnerName(item)"></span>
                                    </p>

                                    <p
                                        x-show="roleFallback(item)"
                                        x-cloak
                                        class="mt-1 text-[11.5px] text-warning-600 dark:text-warning-400"
                                        x-text="translations.owners.fallback"
                                    ></p>

                                    <p
                                        x-show="roleIdle(item.role)"
                                        x-cloak
                                        class="mt-1 text-[11.5px] text-warning-600 dark:text-warning-400"
                                        x-text="translations.owners.idle"
                                    ></p>
                                </div>

                                <select
                                    x-model="roleForm[item.role]"
                                    class="w-full shrink-0 rounded-lg border border-gray-300 bg-white px-3 py-2 text-[14px] text-gray-900 outline-none transition
                                           focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 sm:w-56 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                                >
                                    <template x-for="option in roleOptions()" :key="option.value">
                                        <option :value="option.value" :selected="(roleForm[item.role] || '') === option.value" x-text="option.name"></option>
                                    </template>
                                </select>
                            </div>
                        </template>
                    </div>

                    {{-- Files: each kind picks its own account --}}
                    <div class="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                        <div class="min-w-0">
                            <p class="text-[13px] font-medium text-gray-900 dark:text-white">{{ __('telegram.listeners.owners.files.name') }}</p>
                            <p class="text-[11.5px] text-gray-500 dark:text-gray-400">{{ __('telegram.listeners.owners.files.hint') }}</p>
                        </div>
                        <a
                            href="{{ url('/driver-check/file-routes') }}"
                            class="shrink-0 text-[13px] font-medium text-brand-600 hover:underline dark:text-brand-400"
                        >{{ __('telegram.listeners.owners.files.link') }}</a>
                    </div>

                    <div class="flex flex-col gap-1 py-3 last:pb-0 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                        <div class="min-w-0">
                            <p class="text-[13px] font-medium text-gray-900 dark:text-white">{{ __('telegram.listeners.owners.scheduled.name') }}</p>
                            <p class="text-[11.5px] text-gray-500 dark:text-gray-400">{{ __('telegram.listeners.owners.scheduled.hint') }}</p>
                        </div>
                        <a
                            href="{{ url('/driver-check/scheduled-messages') }}"
                            class="shrink-0 text-[13px] font-medium text-brand-600 hover:underline dark:text-brand-400"
                        >{{ __('telegram.listeners.owners.scheduled.link') }}</a>
                    </div>
                </div>

                <p
                    x-show="ownersError"
                    x-cloak
                    class="dc-break rounded-xl bg-error-50 px-3 py-2.5 text-xs text-error-600 dark:bg-error-500/10 dark:text-error-400"
                    x-text="ownersError"
                ></p>

                <div>
                    <x-driver-check.button variant="primary" x-on:click="saveOwners()" ::disabled="savingOwners || !ownersChanged()">
                        <span x-text="savingOwners ? translations.form.saving : translations.form.save"></span>
                    </x-driver-check.button>
                </div>
            </div>
        </x-driver-check.surface>

        @endif

        @if ($part === 'list')
        {{-- Listeners --}}
        <div class="flex flex-col gap-3">
            <x-driver-check.skeleton-cards />

            <template x-for="row in rows" :key="row.id">
                <x-driver-check.surface class="p-4">
                    <article class="flex flex-col gap-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="dc-break text-sm font-semibold text-gray-900 dark:text-white" x-text="row.name"></p>
                                <p class="mt-0.5 font-mono text-[11.5px] text-gray-500 dark:text-gray-400" x-text="row.key"></p>
                            </div>

                            <x-driver-check.badge ::class="stateClass(stateOf(row))">
                                <span x-text="translations.state[stateOf(row)]"></span>
                            </x-driver-check.badge>
                        </div>

                        <dl class="grid grid-cols-1 gap-x-3 gap-y-2.5 sm:grid-cols-3">
                            <x-driver-check.kv :label="__('telegram.listeners.table.account')">
                                <span class="dc-break text-[13px]" x-text="row.account ? row.account.label : translations.table.no_account"></span>
                            </x-driver-check.kv>

                            <x-driver-check.kv :label="__('telegram.listeners.table.duties')">
                                <span class="flex flex-wrap gap-1">
                                    <template x-for="duty in rowDuties(row)" :key="duty">
                                        <span class="rounded-md bg-gray-100 px-1.5 py-0.5 text-[12px] dark:bg-white/[0.06]" x-text="dutyLabel(duty)"></span>
                                    </template>
                                    <span x-show="rowDuties(row).length === 0" class="text-[13px] text-gray-500" x-text="translations.table.no_duties"></span>
                                </span>
                            </x-driver-check.kv>

                            <x-driver-check.kv :label="__('telegram.listeners.table.seen')">
                                <span
                                    class="text-[13px]"
                                    :title="row.account && row.account.seen_at ? date(row.account.seen_at) : ''"
                                    x-text="row.account && row.account.seen_at ? relative(row.account.seen_at) : translations.table.never"
                                ></span>
                            </x-driver-check.kv>
                        </dl>

                        <div
                            x-show="row.is_enabled && !row.running"
                            x-cloak
                            class="dc-break rounded-xl bg-gray-25 px-3 py-2 text-[12px] text-gray-600 dark:bg-white/[0.03] dark:text-gray-300"
                        >
                            <span x-text="translations.table.run_hint"></span>
                            <a href="{{ url('/driver-check/watchdog') }}" class="ml-1 font-medium text-brand-600 hover:underline dark:text-brand-400">{{ __('telegram.listeners.processes.moved_link') }}</a>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <x-driver-check.button x-on:click="openEdit(row)">
                                {{ __('telegram.listeners.table.edit') }}
                            </x-driver-check.button>

                        </div>
                    </article>
                </x-driver-check.surface>
            </template>
        </div>

        <x-driver-check.empty-state
            icon="bot"
            :title="__('telegram.listeners.empty.title')"
            :description="__('telegram.listeners.empty.description')"
        />
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

        <form id="listener-form" x-on:submit.prevent="save()" class="flex flex-col gap-4">

            <div x-show="!form.id" x-cloak>
                <x-driver-check.field
                    :label="__('telegram.listeners.form.key')"
                    :hint="__('telegram.listeners.form.key_hint')"
                    for="listener-key"
                >
                    <x-driver-check.input
                        id="listener-key"
                        x-model="form.key"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="china_head"
                        ::data-invalid="!!fieldError('key')"
                    />

                    <x-slot:error>
                        <p x-show="fieldError('key')" x-cloak class="mt-1 text-[11px] font-medium text-error-600 dark:text-error-400" x-text="fieldError('key')"></p>
                    </x-slot:error>
                </x-driver-check.field>

            </div>

            <x-driver-check.field :label="__('telegram.listeners.form.name')" for="listener-name">
                <x-driver-check.input
                    id="listener-name"
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
                :label="__('telegram.listeners.form.account')"
                :hint="__('telegram.listeners.form.account_hint')"
                for="listener-account"
            >
                <select
                    id="listener-account"
                    x-model="form.telegram_account_id"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-[14px] text-gray-900 outline-none transition
                           focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                >
                    <option value="">{{ __('telegram.listeners.form.no_account') }}</option>
                    <template x-for="account in freeAccounts()" :key="account.id">
                        <option :value="account.id" x-text="account.label"></option>
                    </template>
                </select>

                <x-slot:error>
                    <p x-show="fieldError('telegram_account_id')" x-cloak class="mt-1 text-[11px] font-medium text-error-600 dark:text-error-400" x-text="fieldError('telegram_account_id')"></p>
                </x-slot:error>
            </x-driver-check.field>

            <div>
                <p class="mb-1.5 text-[12px] font-semibold text-gray-600 dark:text-gray-400">{{ __('telegram.listeners.form.duties') }}</p>

                <div class="flex flex-col gap-2">
                    <template x-for="duty in duties" :key="duty">
                        <label
                            class="dc-tap flex cursor-pointer items-start gap-3 rounded-xl border px-3 py-2.5 transition"
                            :class="hasDuty(duty)
                                ? 'border-brand-500 bg-brand-25 dark:border-brand-500/50 dark:bg-brand-500/[0.07]'
                                : 'border-gray-200 bg-white hover:bg-gray-50 dark:border-gray-800 dark:bg-transparent dark:hover:bg-white/[0.03]'"
                        >
                            <input
                                type="checkbox"
                                :checked="hasDuty(duty)"
                                x-on:change="toggleDuty(duty)"
                                class="mt-0.5 h-4 w-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500/30 dark:border-gray-600 dark:bg-gray-900"
                            >
                            <span class="min-w-0">
                                <span class="block text-[13px] font-medium text-gray-900 dark:text-white" x-text="dutyLabel(duty)"></span>
                                <span class="block text-[11.5px] text-gray-500 dark:text-gray-400" x-text="translations.duty_hints[duty] || ''"></span>
                            </span>
                        </label>
                    </template>
                </div>
            </div>

            <label
                class="dc-tap flex cursor-pointer items-start gap-3 rounded-2xl border p-3.5 transition"
                :class="form.is_enabled
                    ? 'border-brand-500 bg-brand-25 dark:border-brand-500/50 dark:bg-brand-500/[0.07]'
                    : 'border-gray-200 bg-white hover:bg-gray-50 dark:border-gray-800 dark:bg-transparent dark:hover:bg-white/[0.03]'"
            >
                <input
                    type="checkbox"
                    x-model="form.is_enabled"
                    class="mt-0.5 h-5 w-5 shrink-0 rounded-md border-gray-300 text-brand-500
                           focus:ring-brand-500/30 dark:border-gray-600 dark:bg-gray-900"
                >

                <span class="min-w-0">
                    <span class="block text-sm font-medium text-gray-900 dark:text-white">{{ __('telegram.listeners.form.is_enabled') }}</span>
                    <span class="mt-0.5 block text-[11px] leading-snug text-gray-500 dark:text-gray-400">{{ __('telegram.listeners.form.is_enabled_hint') }}</span>
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
                <span class="text-error-600 dark:text-error-400">{{ __('telegram.listeners.form.delete') }}</span>
            </x-driver-check.button>

            <x-driver-check.button x-on:click="closeForm()" ::disabled="saving">
                {{ __('telegram.listeners.form.cancel') }}
            </x-driver-check.button>

            <x-driver-check.button variant="primary" type="submit" form="listener-form" ::disabled="saving">
                <span x-text="saving ? translations.form.saving : translations.form.save"></span>
            </x-driver-check.button>
        </x-slot:footer>
    </x-driver-check.modal>

    {{-- Delete confirmation --}}
    <x-driver-check.modal
        open="confirmOpen"
        close="closeConfirm()"
        size="sm:max-w-md"
        :title="__('telegram.listeners.confirm.delete_title')"
    >
        <p class="text-[13px] leading-relaxed text-gray-600 dark:text-gray-300">
            {{ __('telegram.listeners.confirm.delete_text') }}
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
                {{ __('telegram.listeners.confirm.cancel') }}
            </x-driver-check.button>

            <x-driver-check.button variant="danger" x-on:click="destroy()" ::disabled="deleting">
                {{ __('telegram.listeners.confirm.delete') }}
            </x-driver-check.button>
        </x-slot:footer>
    </x-driver-check.modal>
</div>

@endsection
