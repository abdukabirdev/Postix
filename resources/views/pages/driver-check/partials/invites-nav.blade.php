{{--
    The pages of "Group invites" side by side, under the page header: the
    section reads as one product, each page doing one thing.
--}}

@php
    $invitePages = [
        'driver-check.group-invites' => ['menu' => 'group_invites', 'icon' => 'pulse'],
        'driver-check.group-invites.groups' => ['menu' => 'group_invites_groups', 'icon' => 'send'],
        'driver-check.group-invites.people' => ['menu' => 'group_invites_people', 'icon' => 'users'],
        'driver-check.group-invites.accounts' => ['menu' => 'group_invites_accounts', 'icon' => 'phone'],
        'driver-check.group-invites.log' => ['menu' => 'group_invites_log', 'icon' => 'layers'],
        'driver-check.group-invites.analysis' => ['menu' => 'group_invites_analysis', 'icon' => 'chart'],
        'driver-check.group-invites.settings' => ['menu' => 'group_invites_settings', 'icon' => 'sliders-h'],
    ];
@endphp

<nav class="-mt-1 overflow-x-auto" aria-label="{{ __('driver_check.sections.invites') }}">
    <ul class="flex min-w-max gap-1 border-b border-gray-200 dark:border-gray-800">
        @foreach ($invitePages as $routeName => $page)
            @php $active = request()->routeIs($routeName); @endphp
            <li>
                <a
                    href="{{ route($routeName) }}"
                    @class([
                        'relative -mb-px inline-flex h-10 items-center gap-2 border-b-2 px-3 text-[13px] font-medium transition',
                        'border-gray-950 text-gray-950 dark:border-white dark:text-white' => $active,
                        'border-transparent text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white' => ! $active,
                    ])
                    @if ($active) aria-current="page" @endif
                >
                    <x-driver-check.icon :name="$page['icon']" class="h-4 w-4 opacity-70" />
                    {{ __('driver_check.menu.' . $page['menu']) }}
                </a>
            </li>
        @endforeach
    </ul>
</nav>
