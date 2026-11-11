@extends('layouts.driver-check')

@section('title', __('driver_check.menu.group_invites_groups'))

@section('content')

<div
    x-data="dcGroupInvitesGroups({
        endpoints: {
            index: @js(route('api.telegram.group-invites.groups')),
        },
        links: {
            people: @js(route('driver-check.group-invites.people')),
        },
        translations: @js(__('driver_check.invites')),
    })"
>
    <x-driver-check.shell>

        <x-driver-check.page-header
            :title="__('driver_check.menu.group_invites_groups')"
            :description="__('driver_check.invites.groups.description')"
        >
            <x-slot:actions>
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

        {{-- Add a group --}}
        <x-driver-check.block>
            <div class="p-3.5">
                <form class="flex flex-col gap-2 sm:flex-row" x-on:submit.prevent="add()">
                    <x-driver-check.input class="min-w-0 flex-1" x-model="link" placeholder="{{ __('driver_check.invites.groups.placeholder') }}" />
                    <x-driver-check.button type="submit" variant="primary" icon="plus" ::disabled="busy || link.trim() === ''">
                        {{ __('driver_check.invites.groups.add') }}
                    </x-driver-check.button>
                </form>
                <p class="mt-2 text-[12px] leading-snug text-gray-400">{{ __('driver_check.invites.groups.add_hint') }}</p>
            </div>
        </x-driver-check.block>

        {{-- The groups: one row each, the current one first --}}
        <x-driver-check.block :title="__('driver_check.invites.groups.table.list_title')" count="live().length">
            <x-slot:actions>
                <span x-show="stages.queue.loading || stages.numbers.loading" x-cloak class="inline-flex items-center gap-1.5 text-[12px] text-gray-400">
                    <span class="dc-live"></span>{{ __('driver_check.invites.groups.table.counting') }}
                </span>
            </x-slot:actions>

            <div x-show="!loaded && loading" class="dc-block__wait" aria-hidden="true">
                @foreach ([72, 54, 86, 40] as $width)
                    <div class="dc-skeleton h-3 rounded bg-gray-100 dark:bg-white/[0.06]" style="width: {{ $width }}%"></div>
                @endforeach
            </div>

            <div x-show="loaded && live().length === 0" x-cloak class="dc-none">
                <p class="font-semibold text-gray-900 dark:text-white">{{ __('driver_check.invites.groups.empty_title') }}</p>
                <p class="mt-1">{{ __('driver_check.invites.groups.empty') }}</p>
            </div>

            <div x-show="loaded && live().length > 0" x-cloak>
                <x-driver-check.table min="860px">
                    <thead>
                        <tr>
                            <th>{{ __('driver_check.invites.groups.table.cols.group') }}</th>
                            <th class="dc-num">{{ __('driver_check.invites.groups.cells.added') }}</th>
                            <th class="dc-num">{{ __('driver_check.invites.groups.cells.left') }}</th>
                            <th class="dc-num">{{ __('driver_check.invites.groups.cells.not_added') }}</th>
                            <th class="dc-num">{{ __('driver_check.invites.groups.cells.failed') }}</th>
                            <th class="dc-num" title="{{ __('driver_check.invites.groups.table.cols.queue_hint') }}">{{ __('driver_check.invites.groups.table.cols.queue') }}</th>
                            <th class="dc-num">{{ __('driver_check.invites.groups.table.cols.members') }}</th>
                            <th class="dc-acts"></th>
                        </tr>
                    </thead>

                    <template x-for="group in live()" :key="group.id">
                        <tbody>
                            <tr :data-on="isCurrent(group) || null">
                                <td>
                                    <div class="flex items-center gap-2">
                                        <span class="dc-tag" :data-tone="isCurrent(group) ? 'ok' : ''">
                                            <span class="dc-dot" :class="isCurrent(group) ? 'text-success-500' : 'text-gray-400'"></span>
                                            <span x-text="isCurrent(group) ? t.groups.table.current : t.groups.table.waiting"></span>
                                        </span>
                                        <button type="button" class="min-w-0 text-left" x-on:click="open(group)">
                                            <span class="dc-main block max-w-[150px] truncate" x-text="group.title || group.link"></span>
                                            <span class="dc-sub2 truncate" x-text="group.link"></span>
                                        </button>
                                    </div>
                                    <p x-show="group.last_error" x-cloak class="mt-1 max-w-[230px] truncate text-[12px] text-error-600 dark:text-error-400" x-text="errorLabel(group.last_error, true)" :title="group.last_error"></p>
                                </td>

                                <template x-for="cell in cells(group).slice(0, 4)" :key="cell.key">
                                    <td class="dc-num">
                                        <a :href="peopleLink(group, cell.status)" class="hover:text-brand-700 hover:underline dark:hover:text-brand-300" :class="cell.key === 'added' && 'dc-main'" :title="t.groups.cells[cell.key]">
                                            <span x-show="stages.numbers.loaded" x-text="number(cell.value)"></span>
                                            <span x-show="!stages.numbers.loaded" class="text-gray-300">…</span>
                                        </a>
                                        <span x-show="cell.key === 'left' && stages.numbers.loaded && group.added > 0" x-cloak class="dc-sub2" x-text="percent(group.left, group.added) + '%'"></span>
                                    </td>
                                </template>

                                <td class="dc-num">
                                    <span x-show="stages.queue.loaded && queueTotal(group) !== null" class="dc-main" x-text="number(queueTotal(group))" :title="queueLine(group)"></span>
                                    <span x-show="stages.queue.loaded && queueTotal(group) === null" class="text-gray-300">—</span>
                                    <span x-show="!stages.queue.loaded" class="text-gray-300">…</span>
                                </td>

                                <td class="dc-num">
                                    <span x-show="group.members_before !== null || group.members_after !== null" :title="t.groups.members_hint">
                                        <span x-text="group.members_after !== null ? number(group.members_after) : '…'"></span>
                                        <span x-show="membersGrew(group) !== null" class="dc-sub2" :class="membersGrew(group) > 0 && '!text-success-600 dark:!text-success-400'" x-text="(membersGrew(group) > 0 ? '+' : '') + number(membersGrew(group))"></span>
                                    </span>
                                    <span x-show="group.members_before === null && group.members_after === null" class="text-gray-300">—</span>
                                </td>

                                <td class="dc-acts">
                                    <div class="flex items-center justify-end gap-1">
                                        <x-driver-check.button size="sm" variant="primary" icon="play" x-show="!isCurrent(group)" x-on:click="makeCurrent(group)" ::disabled="busy">
                                            {{ __('driver_check.invites.groups.make_current') }}
                                        </x-driver-check.button>
                                        <x-driver-check.button size="sm" icon="stop" x-show="isCurrent(group)" x-cloak x-on:click="switchOff(group)" ::disabled="busy">
                                            {{ __('driver_check.invites.groups.turn_off') }}
                                        </x-driver-check.button>
                                        <a x-bind:href="peopleLink(group)" class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-gray-400 transition hover:border-gray-200 hover:bg-white hover:text-brand-600 dark:hover:border-gray-700 dark:hover:bg-white/[0.05]" title="{{ __('driver_check.invites.groups.people') }}" aria-label="{{ __('driver_check.invites.groups.people') }}">
                                            <x-driver-check.icon name="users" class="h-4 w-4" />
                                        </a>
                                        <x-driver-check.row-action icon="chevron-down" :label="__('driver_check.invites.accounts.table.details')" x-on:click="open(group)" ::class="opened === group.id && 'rotate-180'" />
                                    </div>
                                </td>
                            </tr>

                            {{-- The rest: what the run did here, and what can be done with the people left out --}}
                            <tr x-show="opened === group.id" x-cloak>
                                <td colspan="8" class="!bg-gray-25 !py-3 dark:!bg-white/[0.015]">
                                    <div class="grid gap-x-8 gap-y-3 text-[12.5px] md:grid-cols-3">
                                        <div class="space-y-1">
                                            <p class="dc-eyebrow">{{ __('driver_check.invites.groups.table.state') }}</p>
                                            <p x-show="queueLine(group)" class="font-medium text-gray-700 dark:text-gray-200" x-text="queueLine(group)"></p>
                                            <p x-show="group.members_before !== null || group.members_after !== null" class="text-gray-500" x-text="membersLine(group)"></p>
                                            <p x-show="group.last_added_at" class="text-gray-500" x-text="t.groups.last_added.replace(':time', time(group.last_added_at))"></p>
                                            <p class="text-gray-500" x-text="group.left_checked_at ? t.groups.left_checked.replace(':time', time(group.left_checked_at)) : (stages.checks.loaded ? t.groups.left_never : '…')"></p>
                                            <p x-show="group.added > 0 && stages.numbers.loaded" class="text-gray-500" x-text="t.groups.left_share.replace(':n', percent(group.left, group.added)).replace(':still', number(stillIn(group)))"></p>
                                        </div>

                                        <div class="space-y-1">
                                            <p class="dc-eyebrow">{{ __('driver_check.invites.groups.table.numbers') }}</p>
                                            <p class="flex justify-between gap-3"><span class="text-gray-500">{{ __('driver_check.invites.groups.cells.already') }}</span><span class="tabular-nums" x-text="number(group.already)"></span></p>
                                            <p class="flex justify-between gap-3"><span class="text-gray-500">{{ __('driver_check.invites.groups.cells.joined') }}</span><span class="tabular-nums" x-text="number(group.joined)"></span></p>
                                            <p class="flex justify-between gap-3"><span class="text-gray-500">{{ __('driver_check.invites.groups.table.waiting_check') }}</span><span class="tabular-nums" x-text="number(group.sent)"></span></p>
                                            <p class="flex justify-between gap-3"><span class="text-gray-500">{{ __('driver_check.invites.groups.table.invited_in') }}</span><span class="tabular-nums" x-text="number(stillIn(group))"></span></p>
                                        </div>

                                        <div class="flex flex-col items-start gap-1.5">
                                            <p class="dc-eyebrow">{{ __('driver_check.invites.groups.table.actions') }}</p>
                                            <x-driver-check.button size="sm" icon="refresh" x-show="group.not_added > 0" x-on:click="retry(group, 'not_added')" ::disabled="busy" :title="__('driver_check.invites.groups.table.retry_not_added_hint')">
                                                <span x-text="t.groups.table.retry_not_added.replace(':n', number(group.not_added))"></span>
                                            </x-driver-check.button>
                                            <x-driver-check.button size="sm" icon="refresh" x-show="group.failed > 0" x-on:click="retry(group, 'failed')" ::disabled="busy" :title="__('driver_check.invites.groups.table.retry_failed_hint')">
                                                <span x-text="t.groups.table.retry_failed.replace(':n', number(group.failed))"></span>
                                            </x-driver-check.button>
                                            <x-driver-check.button size="sm" icon="trash" x-on:click="archive(group)" ::disabled="busy" class="hover:!text-error-600">
                                                {{ __('driver_check.invites.groups.archive') }}
                                            </x-driver-check.button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </template>
                </x-driver-check.table>
            </div>

            <x-slot:foot>
                <span x-show="live().length > 1" x-cloak>{{ __('driver_check.invites.groups.one_at_a_time') }}</span>
            </x-slot:foot>
        </x-driver-check.block>

        {{-- The archive: folded away, history intact --}}
        <x-driver-check.block x-show="archived().length > 0" x-cloak>
            <x-slot:actions>
                <button type="button" class="flex h-8 items-center gap-2 rounded-lg px-2 text-[13px] font-semibold text-gray-600 hover:bg-gray-100 hover:text-gray-950 dark:text-gray-300 dark:hover:bg-white/[0.06] dark:hover:text-white" x-on:click="showArchive = !showArchive">
                    <x-driver-check.icon name="chevron-right" class="h-4 w-4 transition" ::class="showArchive && 'rotate-90'" />
                    <span x-text="t.groups.archive_title.replace(':n', archived().length)"></span>
                </button>
            </x-slot:actions>

            <ul x-show="showArchive" x-cloak class="divide-y divide-gray-100 dark:divide-gray-800">
                <template x-for="group in archived()" :key="group.id">
                    <li class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="truncate text-[13.5px] font-semibold text-gray-700 dark:text-gray-200" x-text="group.title || group.link"></p>
                            <p class="text-[12px] tabular-nums text-gray-400" x-text="t.groups.archived_line
                                .replace(':time', day(group.archived_at))
                                .replace(':added', number(group.added))
                                .replace(':left', number(group.left))
                                .replace(':failed', number(group.failed))"></p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <x-driver-check.button size="sm" href="#" x-bind:href="peopleLink(group)" icon="users">
                                {{ __('driver_check.invites.groups.people') }}
                            </x-driver-check.button>
                            <x-driver-check.button size="sm" x-on:click="restore(group)" ::disabled="busy" icon="arrow-up">
                                {{ __('driver_check.invites.groups.restore') }}
                            </x-driver-check.button>
                        </div>
                    </li>
                </template>
            </ul>
        </x-driver-check.block>
    </x-driver-check.shell>
</div>

@endsection
