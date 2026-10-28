@extends('layouts.driver-check')

@section('title', __('driver_check.contacts.title'))

@section('content')

<div
    x-data="dcContacts({
        endpoints: {
            index: @js(route('api.telegram.contact-sync.contacts')),
        },
        translations: @js(__('driver_check.contacts')),
        ui: @js(__('telegram.ui')),
    })"
>
    <x-driver-check.shell>

        <x-driver-check.page-header
            :title="__('driver_check.contacts.title')"
            :description="__('driver_check.contacts.description')"
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

                <x-driver-check.button href="{{ route('driver-check.contact-sync') }}" variant="primary" icon="sync">
                    {{ __('driver_check.contacts.open_sync') }}
                </x-driver-check.button>
            </x-slot:actions>
        </x-driver-check.page-header>

        {{-- What became of the people found --}}
        <section class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            <x-driver-check.stat-card :label="__('driver_check.contacts.stats.people')" icon="users">
                <span x-text="number(stats.people)">0</span>
            </x-driver-check.stat-card>

            <x-driver-check.stat-card :label="__('driver_check.contacts.stats.added')" icon="check-circle">
                <span x-text="number(stats.added)">0</span>
            </x-driver-check.stat-card>

            <x-driver-check.stat-card :label="__('driver_check.contacts.stats.today')" icon="calendar">
                <span x-text="number(stats.today)">0</span>
            </x-driver-check.stat-card>

            <x-driver-check.stat-card :label="__('driver_check.contacts.stats.waiting')" icon="clock">
                <span x-text="number(waiting())">0</span>
                <x-slot:hint>
                    <span x-text="@js(__('driver_check.contacts.stats.waiting_hint')).replace(':failed', number(stats.failed)).replace(':by_link', number(stats.by_link))"></span>
                </x-slot:hint>
            </x-driver-check.stat-card>
        </section>

        <x-driver-check.error-alert :title="__('driver_check.contacts.error')" />

        <x-driver-check.surface class="overflow-hidden">
            <x-driver-check.filters
                embedded
                :search-placeholder="__('driver_check.contacts.filters.search')"
                :sort-options="[
                    'created_at' => __('driver_check.contacts.filters.sort_found'),
                    'added_at' => __('driver_check.contacts.filters.sort_added'),
                    'name' => __('driver_check.contacts.filters.sort_name'),
                ]"
            >
                <x-driver-check.field :label="__('driver_check.contacts.filters.status')">
                    <x-driver-check.select x-model="filters.status" x-on:change="applyFilters()">
                        <option value="">{{ __('driver_check.contacts.filters.status_all') }}</option>
                        @foreach (['added', 'pending', 'failed', 'skipped', 'unassigned', 'by_link'] as $status)
                            <option value="{{ $status }}">{{ __('driver_check.contacts.status.' . $status) }}</option>
                        @endforeach
                    </x-driver-check.select>
                </x-driver-check.field>

                <x-driver-check.field :label="__('driver_check.contacts.filters.group')">
                    <x-driver-check.select x-model="filters.group_id" x-on:change="applyFilters()">
                        <option value="">{{ __('driver_check.contacts.filters.group_all') }}</option>
                        <template x-for="group in options.groups" :key="group.id">
                            <option :value="group.id" x-text="group.title"></option>
                        </template>
                    </x-driver-check.select>
                </x-driver-check.field>

                <x-driver-check.field :label="__('driver_check.contacts.filters.account')">
                    <x-driver-check.select x-model="filters.account_id" x-on:change="applyFilters()">
                        <option value="">{{ __('driver_check.contacts.filters.account_all') }}</option>
                        <template x-for="account in options.accounts" :key="account.id">
                            <option :value="account.id" x-text="phone(account.phone)"></option>
                        </template>
                    </x-driver-check.select>
                </x-driver-check.field>

                <x-driver-check.field :label="__('driver_check.contacts.filters.presence')">
                    <x-driver-check.select x-model="filters.presence" x-on:change="applyFilters()">
                        <option value="">{{ __('driver_check.contacts.filters.presence_all') }}</option>
                        <option value="on_account">{{ __('driver_check.contacts.filters.presence_on') }}</option>
                        <option value="not_on_account">{{ __('driver_check.contacts.filters.presence_off') }}</option>
                    </x-driver-check.select>
                </x-driver-check.field>

                <label class="flex cursor-pointer items-center gap-2.5 self-end pb-2 text-[13px] font-medium text-gray-700 dark:text-gray-200">
                    <input type="checkbox" x-model="filters.bots" x-on:change="applyFilters()" class="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                    {{ __('driver_check.contacts.filters.bots') }}
                </label>
            </x-driver-check.filters>

            {{-- Table (xl and up) --}}
            <div class="hidden xl:block">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1000px] table-fixed text-left">
                        <colgroup>
                            <col class="w-[22%]">
                            <col class="w-[12%]">
                            <col class="w-[17%]">
                            <col class="w-[13%]">
                            <col class="w-[12%]">
                            <col class="w-[14%]">
                            <col class="w-[10%]">
                        </colgroup>

                        <thead class="dc-thead border-b border-gray-200 bg-gray-25 dark:border-gray-800 dark:bg-transparent">
                            <tr class="text-[10.5px] uppercase tracking-[0.08em] text-gray-500 dark:text-gray-400">
                                <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('driver_check.contacts.table.person') }}</th>
                                <th scope="col" class="px-3 py-2.5 font-semibold">{{ __('driver_check.contacts.table.phone') }}</th>
                                <th scope="col" class="px-3 py-2.5 font-semibold">{{ __('driver_check.contacts.table.groups') }}</th>
                                <th scope="col" class="px-3 py-2.5 font-semibold">{{ __('driver_check.contacts.table.account') }}</th>
                                <th scope="col" class="px-3 py-2.5 font-semibold">{{ __('driver_check.contacts.table.status') }}</th>
                                <th scope="col" class="px-3 py-2.5 font-semibold">{{ __('driver_check.contacts.where.title') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-semibold">{{ __('driver_check.contacts.table.saved') }}</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <x-driver-check.skeleton-rows :cols="7" />

                            <template x-for="row in rows" :key="row.id">
                                <tr class="dc-row group align-middle transition-colors hover:bg-gray-25 dark:hover:bg-white/[0.025]">
                                    {{-- Person --}}
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-3">
                                            <x-driver-check.avatar size="sm" x-text="initials(displayName(row))" />

                                            <div class="min-w-0">
                                                <p class="flex items-center gap-1.5 truncate text-sm font-medium text-gray-900 dark:text-white">
                                                    <span class="truncate" x-text="displayName(row)"></span>
                                                    <span x-show="row.is_bot" x-cloak class="rounded bg-gray-100 px-1 text-[10px] font-bold uppercase text-gray-500 dark:bg-white/[0.07]">{{ __('driver_check.contacts.table.bot') }}</span>
                                                </p>

                                                <div class="flex items-center gap-1">
                                                    <a
                                                        x-show="row.username"
                                                        x-cloak
                                                        :href="telegramUrl(row.username)"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        class="truncate text-[12px] text-brand-600 hover:underline dark:text-brand-400"
                                                        x-text="handle(row.username)"
                                                    ></a>
                                                    <span x-show="!row.username" class="truncate text-[12px] text-gray-400">{{ __('driver_check.contacts.table.no_username') }}</span>

                                                    <template x-if="row.username">
                                                        <x-driver-check.copy-button value="row.username" />
                                                    </template>
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    {{-- Phone, when Telegram shows it --}}
                                    <td class="px-3 py-3 text-[13px] tabular-nums">
                                        <span x-show="row.phone" x-text="phone(row.phone)" class="text-gray-800 dark:text-gray-200"></span>
                                        <span x-show="!row.phone" class="text-gray-400">{{ __('driver_check.contacts.table.no_phone') }}</span>
                                        <p class="text-[11px] tabular-nums text-gray-400" x-text="row.telegram_user_id"></p>
                                    </td>

                                    <td class="px-3 py-3 text-[13px] text-gray-600 dark:text-gray-300">
                                        <p class="line-clamp-2" :title="(row.groups || []).map((g) => g.title).join(', ')" x-text="groupsLine(row)"></p>
                                    </td>

                                    <td class="px-3 py-3 text-[13px] tabular-nums text-gray-700 dark:text-gray-200">
                                        <span x-show="row.account" x-text="row.account && phone(row.account.phone)"></span>
                                        <span x-show="!row.account" class="text-gray-300 dark:text-gray-600" x-text="dash"></span>
                                    </td>

                                    <td class="px-3 py-3">
                                        <x-driver-check.badge ::class="statusClass(row.status)" ::title="row.error || ''">
                                            <span class="h-1.5 w-1.5 rounded-full" :class="statusDot(row.status)"></span>
                                            <span x-text="statusLabel(row.status)"></span>
                                        </x-driver-check.badge>
                                    </td>

                                    {{-- Where it is: in the database always, on the account yes / no / not known --}}
                                    <td class="px-3 py-3">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <span class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-1.5 py-0.5 text-[11px] font-semibold text-gray-700 dark:bg-white/[0.07] dark:text-gray-200" title="{{ __('driver_check.contacts.where.database') }}">
                                                <span class="h-1.5 w-1.5 rounded-full bg-success-500"></span>
                                                {{ __('driver_check.contacts.where.database') }}
                                            </span>

                                            <span
                                                class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-1.5 py-0.5 text-[11px] font-semibold text-gray-700 dark:bg-white/[0.07] dark:text-gray-200"
                                                :class="whereAccount(row).state === 'no' && '!bg-error-50 !text-error-700 dark:!bg-error-500/10 dark:!text-error-400'"
                                                :title="whereAccount(row).title + ((row.holders || []).length ? ' (' + row.holders.map((h) => phone(h.phone)).join(', ') + ')' : '')"
                                            >
                                                <span class="h-1.5 w-1.5 rounded-full" :class="whereAccount(row).dot"></span>
                                                <span x-text="whereAccount(row).label"></span>
                                            </span>
                                        </div>
                                    </td>

                                    <td class="px-4 py-3 text-right text-[12.5px] tabular-nums text-gray-600 dark:text-gray-300">
                                        <span x-show="row.added_at" :title="date(row.added_at)" x-text="relative(row.added_at)"></span>
                                        <span x-show="!row.added_at" class="text-gray-300 dark:text-gray-600" x-text="dash"></span>
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
                    <div class="flex flex-col gap-3 p-3.5">
                        <div class="flex items-center gap-3">
                            <x-driver-check.avatar x-text="initials(displayName(row))" />

                            <div class="min-w-0 flex-1">
                                <p class="dc-break text-sm font-semibold text-gray-900 dark:text-white" x-text="displayName(row)"></p>
                                <p class="truncate text-[12px] text-gray-500 dark:text-gray-400" x-text="row.username ? handle(row.username) : @js(__('driver_check.contacts.table.no_username'))"></p>
                            </div>

                            <x-driver-check.badge ::class="statusClass(row.status)">
                                <span class="h-1.5 w-1.5 rounded-full" :class="statusDot(row.status)"></span>
                                <span x-text="statusLabel(row.status)"></span>
                            </x-driver-check.badge>
                        </div>

                        <dl class="grid grid-cols-2 gap-2 rounded-xl bg-gray-50 p-3 dark:bg-white/[0.02]">
                            <x-driver-check.kv :label="__('driver_check.contacts.table.groups')" class="col-span-2">
                                <span x-text="groupsLine(row)"></span>
                            </x-driver-check.kv>

                            <x-driver-check.kv :label="__('driver_check.contacts.table.account')">
                                <span class="tabular-nums" x-text="row.account ? phone(row.account.phone) : dash"></span>
                            </x-driver-check.kv>

                            <x-driver-check.kv :label="__('driver_check.contacts.where.title')" class="col-span-2">
                                <span class="inline-flex flex-wrap items-center gap-1.5">
                                    <span class="inline-flex items-center gap-1 text-[12px]"><span class="h-1.5 w-1.5 rounded-full bg-success-500"></span>{{ __('driver_check.contacts.where.database') }}</span>
                                    <span class="inline-flex items-center gap-1 text-[12px]" :title="whereAccount(row).title"><span class="h-1.5 w-1.5 rounded-full" :class="whereAccount(row).dot"></span><span x-text="whereAccount(row).label"></span></span>
                                </span>
                            </x-driver-check.kv>

                            <x-driver-check.kv :label="__('driver_check.contacts.table.saved')">
                                <span class="tabular-nums" x-text="row.added_at ? relative(row.added_at) : dash"></span>
                            </x-driver-check.kv>
                        </dl>
                    </div>
                </template>
            </div>

            <x-driver-check.empty-state
                icon="book"
                :title="__('driver_check.contacts.empty.title')"
                :description="__('driver_check.contacts.empty.description')"
            />

            <x-driver-check.pagination />
        </x-driver-check.surface>
    </x-driver-check.shell>
</div>

@endsection
