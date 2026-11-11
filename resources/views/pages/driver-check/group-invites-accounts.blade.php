@extends('layouts.driver-check')

@section('title', __('driver_check.menu.group_invites_accounts'))

@section('content')

<div
    x-data="dcGroupInvitesAccounts({
        endpoints: {
            index: @js(route('api.telegram.group-invites.accounts')),
        },
        translations: @js(__('driver_check.invites')),
    })"
>
    <x-driver-check.shell>

        <x-driver-check.page-header
            :title="__('driver_check.menu.group_invites_accounts')"
            :description="__('driver_check.invites.accounts.description')"
        >
            <x-slot:actions>
                {{-- Every account writes /start to @SpamBot: limited by Telegram or not, until when --}}
                <x-driver-check.button
                    size="sm"
                    icon="shield"
                    x-on:click="askSpamAll()"
                    ::disabled="busy || spamChecking"
                    :title="__('driver_check.invites.accounts.spam_hint')"
                >
                    <span x-text="spamChecking ? t.accounts.spam_checking : t.accounts.spam_ask_all"></span>
                </x-driver-check.button>
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

        {{-- The readings: each is a number and the filter of the list below --}}
        <div class="dc-stats">
            <template x-for="tile in viewTiles()" :key="tile.value">
                <button type="button" class="dc-stat" :aria-pressed="view === tile.value" x-on:click="setView(tile.value)">
                    <p class="dc-stat__label" x-text="tile.label"></p>
                    <p class="dc-stat__value" :class="tile.value === 'limited' && tile.total > 0 && 'text-error-600 dark:text-error-400'" x-text="loaded ? number(tile.total) : '—'"></p>
                </button>
            </template>
        </div>

        {{-- The list: one row an account, the heavy columns fill in as their stage comes --}}
        <x-driver-check.block :title="__('driver_check.invites.accounts.table.list_title')" count="number(shown().length)">
            <x-slot:actions>
                <p class="hidden text-[12px] tabular-nums text-gray-500 xl:block" x-text="t.accounts.limits_line
                    .replace(':times', number(limits.batches_per_day))
                    .replace(':size', number(limits.batch_size))
                    .replace(':lookups', number(limits.usernames_per_day))"></p>
            </x-slot:actions>

            <div class="dc-toolbar">
                <div class="dc-toolbar__grow">
                    <x-driver-check.search
                        model="filters.search"
                        :placeholder="__('driver_check.invites.accounts.search')"
                        debounce="150ms"
                    />
                </div>

                <x-driver-check.chips items="qualities()" selected="quality" action="setQuality" :title="__('driver_check.invites.accounts.quality_hint')" />

                <div x-show="picked" x-cloak>
                    <x-driver-check.badge class="bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">
                        <span x-text="picked ? t.accounts.stats.only.replace(':what', picked.label) : ''"></span>
                        <button type="button" class="-mr-1 rounded p-0.5 hover:bg-brand-100 dark:hover:bg-brand-500/20" x-on:click="unpick()" aria-label="{{ __('telegram.ui.search_clear') }}">
                            <x-driver-check.icon name="close" class="h-3 w-3" />
                        </button>
                    </x-driver-check.badge>
                </div>

                {{-- What to do with the ticked rows --}}
                <div class="dc-toolbar__end" x-show="selectedCount() > 0" x-cloak>
                    <span class="text-[12.5px] font-medium tabular-nums text-gray-700 dark:text-gray-200" x-text="t.accounts.table.bulk.selected.replace(':n', selectedCount())"></span>
                    <x-driver-check.button size="sm" x-on:click="bulkExclude(false)" ::disabled="!!busy">{{ __('driver_check.invites.accounts.table.bulk.include') }}</x-driver-check.button>
                    <x-driver-check.button size="sm" x-on:click="bulkExclude(true)" ::disabled="!!busy">{{ __('driver_check.invites.accounts.table.bulk.exclude') }}</x-driver-check.button>
                    <x-driver-check.button size="sm" x-show="bulkCanEnable()" x-on:click="bulkEnable()" ::disabled="!!busy">{{ __('driver_check.invites.accounts.enable') }}</x-driver-check.button>
                    <x-driver-check.button size="sm" variant="ghost" x-on:click="clearSelected()">{{ __('driver_check.invites.accounts.table.bulk.clear') }}</x-driver-check.button>
                </div>
            </div>

            <div x-show="!loaded && loading" class="dc-block__wait" aria-hidden="true">
                @foreach ([72, 54, 86, 40, 64, 78] as $width)
                    <div class="dc-skeleton h-3 rounded bg-gray-100 dark:bg-white/[0.06]" style="width: {{ $width }}%"></div>
                @endforeach
            </div>

            <p x-show="loaded && shown().length === 0" x-cloak class="dc-none">{{ __('driver_check.invites.accounts.empty') }}</p>

            <div x-show="loaded && shown().length > 0" x-cloak>
                <x-driver-check.table min="1040px">
                    <thead>
                        <tr>
                            <th class="w-8 !pr-0">
                                <input type="checkbox" class="h-4 w-4 rounded border-gray-300 text-brand-600" :checked="allShownSelected()" x-on:change="tickAll()" aria-label="{{ __('driver_check.invites.accounts.table.select_all') }}" title="{{ __('driver_check.invites.accounts.table.select_all') }}">
                            </th>
                            <th>{{ __('driver_check.invites.accounts.table.cols.account') }}</th>
                            <th><button type="button" class="dc-sort" :data-on="sortOn('state')" x-on:click="sortBy('state')">{{ __('driver_check.invites.accounts.table.cols.state') }}</button></th>
                            <th><button type="button" class="dc-sort" :data-on="sortOn('spam')" x-on:click="sortBy('spam')">@SpamBot</button></th>
                            <th class="dc-num"><button type="button" class="dc-sort" :data-on="sortOn('calls_today')" x-on:click="sortBy('calls_today')">{{ __('driver_check.invites.accounts.calls_today') }}</button></th>
                            <th class="dc-num"><button type="button" class="dc-sort" :data-on="sortOn('added_today')" x-on:click="sortBy('added_today')">{{ __('driver_check.invites.accounts.table.cols.added_today') }}</button></th>
                            <th class="dc-num"><button type="button" class="dc-sort" :data-on="sortOn('added_recent')" x-on:click="sortBy('added_recent')" x-text="t.accounts.table.cols.recent.replace(':days', recentDays)" :title="t.accounts.table.cols.recent_hint.replace(':days', recentDays)"></button></th>
                            <th class="dc-num"><button type="button" class="dc-sort" :data-on="sortOn('added')" x-on:click="sortBy('added')">{{ __('driver_check.invites.accounts.added') }}</button></th>
                            <th class="dc-num"><button type="button" class="dc-sort" :data-on="sortOn('contacts')" x-on:click="sortBy('contacts')">{{ __('driver_check.invites.accounts.table.cols.contacts') }}</button></th>
                            <th class="dc-acts">{{ __('driver_check.invites.accounts.in') }}</th>
                        </tr>
                    </thead>

                    <template x-for="account in shown()" :key="account.id">
                        <tbody>
                            <tr :data-on="isSelected(account) || null" :data-off="account.excluded || null">
                                <td class="!pr-0">
                                    <input type="checkbox" class="h-4 w-4 rounded border-gray-300 text-brand-600" :checked="isSelected(account)" :disabled="account.listener" x-on:change="tick(account)">
                                </td>

                                <td>
                                    <button type="button" class="block max-w-[190px] text-left" x-on:click="open(account)" :title="originLine(account)">
                                        <span class="dc-main block truncate tabular-nums" x-text="phone(account.phone) || ('#' + account.id)"></span>
                                        <span class="dc-sub2 truncate" x-text="accountSub(account)"></span>
                                    </button>
                                </td>

                                <td>
                                    <span class="dc-tag" :data-tone="stateTone(account)" x-text="stateShort(account)" :title="stateLine(account)"></span>
                                    <span x-show="stateSub(account)" class="dc-sub2" x-text="stateSub(account)"></span>
                                    <span x-show="account.cap_lowered" x-cloak class="dc-sub2 !text-warning-600 dark:!text-warning-400" x-text="t.accounts.table.cap_lowered"></span>
                                    <span x-show="account.warm_day" x-cloak class="dc-sub2" x-text="t.accounts.table.warm.replace(':day', account.warm_day)"></span>
                                </td>

                                {{-- What Telegram's own @SpamBot says about it --}}
                                <td>
                                    <span class="dc-tag" :data-tone="spamTag(account)" x-text="spamShort(account)" :title="account.spam_check?.text || account.spam_check?.error || ''"></span>
                                    <span x-show="account.spam_check" class="dc-sub2" x-text="spamSub(account)"></span>
                                </td>

                                {{-- Its day: calls of its cap --}}
                                <td class="dc-num">
                                    <span class="font-semibold text-gray-950 dark:text-white" x-text="number(account.calls_today)"></span><span class="text-gray-400" x-text="' / ' + number(account.cap)"></span>
                                    <span class="mt-1 block h-1 w-14 overflow-hidden rounded-full bg-gray-100 ml-auto dark:bg-white/10">
                                        <span class="block h-full rounded-full" :class="account.cap_lowered ? 'bg-warning-500' : 'bg-brand-500'" :style="`width: ${callsShare(account)}%`"></span>
                                    </span>
                                </td>

                                {{-- What it got: these come with the second stage --}}
                                <td class="dc-num">
                                    <span x-show="stages.numbers.loaded" class="font-semibold text-gray-950 dark:text-white" x-text="number(account.added_today)"></span>
                                    <span x-show="!stages.numbers.loaded" class="text-gray-300">…</span>
                                </td>

                                <td class="dc-num">
                                    <span x-show="stages.numbers.loaded">
                                        <span class="font-semibold text-gray-950 dark:text-white" x-text="number(account.recent.added)"></span><span class="text-gray-400" x-text="' / ' + number(account.recent.tried)"></span>
                                        <span x-show="account.recent.tried > 0" class="dc-tag ml-1.5" :class="qualityTone(account)" :title="t.accounts.quality[qualityOf(account)]" x-text="recentRate(account)"></span>
                                    </span>
                                    <span x-show="!stages.numbers.loaded" class="text-gray-300">…</span>
                                </td>

                                <td class="dc-num">
                                    <span x-show="stages.numbers.loaded">
                                        <span x-text="number(account.added)"></span>
                                        <span x-show="account.left > 0" class="dc-sub2" x-text="t.accounts.of_them_left.replace(':n', number(account.left))"></span>
                                    </span>
                                    <span x-show="!stages.numbers.loaded" class="text-gray-300">…</span>
                                </td>

                                {{-- Its contacts: the third stage --}}
                                <td class="dc-num">
                                    <span x-show="stages.contacts.loaded">
                                        <span x-text="number(account.contacts)"></span>
                                        <span class="dc-sub2">
                                            <span class="text-success-700 dark:text-success-400" x-text="number(account.contacts_can)" title="{{ __('driver_check.invites.accounts.contacts_can') }}"></span>
                                            ·
                                            <span class="text-error-600 dark:text-error-400" x-text="number(account.contacts_cannot)" title="{{ __('driver_check.invites.accounts.contacts_cannot') }}"></span>
                                        </span>
                                    </span>
                                    <span x-show="!stages.contacts.loaded" class="text-gray-300">…</span>
                                </td>

                                <td class="dc-acts">
                                    <div class="flex items-center justify-end gap-1">
                                        <x-driver-check.row-action icon="play" :label="__('driver_check.invites.accounts.enable')" class="!text-warning-600 dark:!text-warning-400" x-show="canEnable(account)" x-on:click="enable(account)" ::disabled="!!busy" />
                                        <x-driver-check.row-action icon="shield" :label="__('driver_check.invites.accounts.spam_ask')" x-on:click="askSpam(account)" ::disabled="!!busy || spamChecking" />
                                        <label class="relative mx-1 inline-flex h-5 w-9 shrink-0 items-center" :class="account.listener ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'" :title="account.listener ? t.accounts.listener : (account.excluded ? t.accounts.out : t.accounts.in)">
                                            <input type="checkbox" class="peer sr-only" :checked="!account.excluded" :disabled="account.listener || !!busy" x-on:change="toggle(account)">
                                            <span class="absolute inset-0 rounded-full bg-gray-300 transition peer-checked:bg-brand-500 dark:bg-gray-700"></span>
                                            <span class="absolute left-0.5 h-4 w-4 rounded-full bg-white shadow transition peer-checked:translate-x-4"></span>
                                        </label>
                                        <x-driver-check.row-action icon="chevron-down" :label="__('driver_check.invites.accounts.table.details')" x-on:click="open(account)" ::class="opened === account.id && 'rotate-180'" />
                                    </div>
                                </td>
                            </tr>

                            {{-- The rest of what is known about it --}}
                            <tr x-show="opened === account.id" x-cloak>
                                <td colspan="10" class="!bg-gray-25 !py-3 dark:!bg-white/[0.015]">
                                    <div class="grid gap-x-8 gap-y-3 text-[12.5px] md:grid-cols-3">
                                        <div class="space-y-1">
                                            <p class="dc-eyebrow">{{ __('driver_check.invites.accounts.table.cols.state') }}</p>
                                            <p :class="account.held ? 'text-error-600 dark:text-error-400' : (account.rest_until ? 'text-warning-600 dark:text-warning-400' : '')" x-text="stateLine(account)"></p>
                                            <p x-show="account.cap_lowered" class="text-warning-600 dark:text-warning-400" x-text="t.accounts.cap_lowered.replace(':n', account.peer_floods)"></p>
                                            <p x-show="account.warm_day" class="text-brand-600 dark:text-brand-400" x-text="t.accounts.warm_up.replace(':day', account.warm_day).replace(':percent', account.warm_percent)"></p>
                                            <p class="text-gray-500" x-text="lastStopLine(account) || t.accounts.no_stops"></p>
                                        </div>

                                        <dl class="space-y-1">
                                            <p class="dc-eyebrow">{{ __('driver_check.invites.accounts.table.numbers') }}</p>
                                            <div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('driver_check.invites.accounts.not_added') }}</dt><dd class="tabular-nums" x-text="number(account.not_added)"></dd></div>
                                            <div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('driver_check.invites.accounts.failed') }}</dt><dd class="tabular-nums" x-text="number(account.failed)"></dd></div>
                                            <div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('driver_check.invites.accounts.sent') }}</dt><dd class="tabular-nums" x-text="number(account.sent)"></dd></div>
                                            <div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('driver_check.invites.accounts.lookups_today') }}</dt><dd class="tabular-nums" x-text="number(account.lookups_today) + ' / ' + number(limits.usernames_per_day)"></dd></div>
                                        </dl>

                                        <div class="space-y-1">
                                            <p class="dc-eyebrow">@SpamBot</p>
                                            <p :class="spamTone(account)" x-text="spamLine(account)"></p>
                                            <p x-show="account.spam_check?.text || account.spam_check?.error" class="dc-break whitespace-pre-line text-gray-500" x-text="account.spam_check?.text || account.spam_check?.error"></p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </template>
                </x-driver-check.table>
            </div>

            <x-slot:foot>
                <span x-text="qualityHint()"></span>
            </x-slot:foot>
        </x-driver-check.block>

        {{-- How well they add, by the number's country or by the account's age; a row narrows the list to it --}}
        <x-driver-check.block :title="__('driver_check.invites.accounts.stats.title')" stage="numbers">
            <x-slot:actions>
                <x-driver-check.chips items="statsTabs()" selected="statsBy" action="setStatsBy" />
            </x-slot:actions>

            <x-driver-check.table min="640px">
                <thead>
                    <tr>
                        <th x-text="t.accounts.stats.cols[statsBy]"></th>
                        <th class="dc-num">{{ __('driver_check.invites.accounts.stats.cols.accounts') }}</th>
                        <th class="dc-num">{{ __('driver_check.invites.accounts.stats.cols.quiet') }}</th>
                        <th class="dc-num" x-text="t.accounts.stats.cols.tried.replace(':days', recentDays)"></th>
                        <th class="dc-num" x-text="t.accounts.stats.cols.added.replace(':days', recentDays)"></th>
                        <th class="dc-num">%</th>
                        <th class="dc-num">{{ __('driver_check.invites.accounts.stats.cols.total') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="row in statRows()" :key="statsBy + '-' + row.key">
                        <tr data-click :data-on="isPicked(row) || null" x-on:click="pick(row)" :title="t.accounts.stats.pick">
                            <td>
                                <span class="dc-main" x-text="row.label"></span>
                                <span x-show="row.ids" class="dc-sub2" x-text="row.ids"></span>
                            </td>
                            <td class="dc-num">
                                <span x-text="number(row.accounts)"></span>
                                <span x-show="row.taking !== row.accounts" class="dc-sub2" x-text="t.accounts.stats.taking_part.replace(':n', number(row.taking))"></span>
                            </td>
                            <td class="dc-num" :class="row.quiet > 0 ? '!text-error-600 dark:!text-error-400' : '!text-gray-400'" x-text="number(row.quiet)"></td>
                            <td class="dc-num" x-text="number(row.tried)"></td>
                            <td class="dc-num dc-main" x-text="number(row.added)"></td>
                            <td class="dc-num font-semibold" :class="rowTone(row)" x-text="rowRate(row)"></td>
                            <td class="dc-num" x-text="number(row.total)"></td>
                        </tr>
                    </template>
                </tbody>
            </x-driver-check.table>

            <x-slot:foot>
                <span x-text="t.accounts.stats.hints[statsBy]"></span>
            </x-slot:foot>
        </x-driver-check.block>
    </x-driver-check.shell>
</div>

@endsection
