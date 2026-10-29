<!DOCTYPE html>
@php
    use App\Helpers\MenuHelper;

    /*
     * The driver-check layout: its own shell, apart from layouts/app (postix).
     * Pages `@extends('layouts.driver-check')` and fill `content`.
     */
    $dcUser = auth()->user();

    $dcIcons = [
        '/driver-check/operation-users' => 'users',
        '/driver-check/drivers' => 'truck',
        '/driver-check/resolved-phones' => 'phone',
        '/driver-check/penalties' => 'flag',
        '/driver-check/operators' => 'operator',
        '/driver-check/sales' => 'trending',
        '/driver-check/penalties/settings' => 'sliders-h',
        '/driver-check/auto-replies' => 'bot',
        '/driver-check/personal-answers' => 'message',
        '/driver-check/scheduled-messages' => 'clock',
        '/driver-check/listeners' => 'bot',
        '/driver-check/chats' => 'send',
        '/driver-check/file-routes' => 'route',
        '/driver-check/sessions' => 'key',
        '/driver-check/watchdog' => 'pulse',
        '/driver-check/queue' => 'layers',
        '/driver-check/contact-sync' => 'sync',
        '/driver-check/contacts' => 'book',
        '/driver-check/contact-report' => 'chart',
        '/driver-check/contact-settings' => 'sliders-h',
        '/driver-check/contact-accounts' => 'phone',
        '/driver-check/group-invites' => 'users',
        '/driver-check/group-join' => 'pulse',
        '/driver-check/group-join/joined' => 'check-circle',
        '/driver-check/group-join/queue' => 'clock',
        '/driver-check/group-join/people' => 'users',
        '/driver-check/group-join/settings' => 'sliders-h',
    ];

    $dcPath = '/' . ltrim(request()->path(), '/');
    $dcCurrent = null;
    $dcGroups = [];
    $dcPalette = [];

    foreach (MenuHelper::getDriverCheckGroups() as $groupIndex => $group) {
        $items = [];

        foreach ($group['items'] as $item) {
            $item['dc'] = $dcIcons[$item['path']] ?? 'hash';
            $item['group_index'] = $groupIndex;
            $items[] = $item;

            $matches = $dcPath === $item['path'] || str_starts_with($dcPath, $item['path'] . '/');

            if ($matches && ($dcCurrent === null || strlen($item['path']) > strlen($dcCurrent['path']))) {
                $dcCurrent = $item;
            }

            $dcPalette[] = ['name' => $item['name'], 'group' => $group['title'], 'path' => url($item['path']), 'icon' => $item['dc']];
        }

        $dcGroups[] = ['title' => $group['title'], 'items' => $items];
    }

    $dcActive = $dcCurrent['group_index'] ?? null;
    $dcProfilePath = '/driver-check/profile';
    $dcOnProfile = $dcPath === $dcProfilePath;

    $dcPalette[] = ['name' => __('driver_check.profile.title'), 'group' => __('driver_check.nav.account'), 'path' => url($dcProfilePath), 'icon' => 'user'];

    $dcName = $dcUser->name ?? 'User';
    $dcInitials = mb_strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N} ]/u', '', $dcName) ?: 'U', 0, 1));
    $dcRoleName = optional($dcUser?->role)->name;
    $dcLocale = app()->getLocale();
@endphp
<html lang="{{ str_replace('_', '-', $dcLocale) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#11120f">

    <title>@yield('title', 'Driver Check') · Driver Check</title>

    <script>
        (function () {
            try {
                var saved = localStorage.getItem('theme');
                var dark = saved ? saved === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.classList.toggle('dark', dark);
            } catch (e) {}
        })();
    </script>

    @vite(['resources/css/driver-check.css', 'resources/js/driver-check-app.js'])

    @stack('styles')
</head>

<body class="min-h-full" x-data>
    <div class="dc-route-line" data-route-line></div>

    {{-- ================= Toasts ================= --}}
    <div
        x-data="dcToasts()"
        x-init="
            @if (session('success')) $nextTick(() => add({ message: @js(session('success')), success: true })); @endif
            @if (session('error')) $nextTick(() => add({ message: @js(session('error')), success: false })); @endif
        "
        class="pointer-events-none fixed inset-x-3 top-3 z-[100100] flex flex-col items-stretch gap-2 sm:inset-x-auto sm:right-4 sm:top-4 sm:w-[360px]"
    >
        <template x-for="t in toasts" :key="t.id">
            <div
                x-show="t.show"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 -translate-y-2"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-end="opacity-0"
                class="pointer-events-auto flex items-start gap-3 rounded-xl border bg-white p-3.5 shadow-lg dark:bg-gray-dark"
                :class="{
                    'border-success-200 dark:border-success-500/30': tone(t) === 'success',
                    'border-error-200 dark:border-error-500/30': tone(t) === 'error',
                    'border-warning-200 dark:border-warning-500/30': tone(t) === 'warning',
                    'border-blue-light-200 dark:border-blue-light-500/30': tone(t) === 'info',
                }"
                role="status"
            >
                <span
                    class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-white"
                    :class="{
                        'bg-success-500': tone(t) === 'success',
                        'bg-error-500': tone(t) === 'error',
                        'bg-warning-500': tone(t) === 'warning',
                        'bg-blue-light-500': tone(t) === 'info',
                    }"
                >
                    <svg x-show="tone(t) === 'success'" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4 10-10"/></svg>
                    <svg x-show="tone(t) !== 'success'" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2"><path stroke-linecap="round" d="M12 6v7m0 4v.5"/></svg>
                </span>

                <div class="min-w-0 flex-1 text-[13px] leading-snug text-gray-800 dark:text-gray-100" x-html="t.message"></div>

                <button type="button" x-on:click="remove(t.id)" class="-mr-1 -mt-0.5 grid h-6 w-6 place-items-center rounded-md text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/10" aria-label="{{ __('telegram.ui.close') }}">
                    <x-driver-check.icon name="close" class="h-3.5 w-3.5" />
                </button>
            </div>
        </template>
    </div>

    {{-- ================= Top bar ================= --}}
    <header class="dc-bar" x-data="{ userOpen: false }" x-on:keydown.escape.window="userOpen = false">
        <div class="dc-bar__row">
            <a href="{{ url('/driver-check') }}" class="dc-brand" aria-label="Driver Check">
                <span class="dc-brand__mark">
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.6"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12.5 4.2 4.2L19 7"/></svg>
                </span>
                <span class="hidden sm:inline">Driver Check</span>
            </a>

            {{-- Sections (desktop) --}}
            <nav class="hidden items-center gap-1 lg:flex" aria-label="{{ __('driver_check.nav.sections') }}">
                @foreach ($dcGroups as $i => $group)
                    <a
                        href="{{ url($group['items'][0]['path']) }}"
                        class="dc-tab"
                        @if ($dcActive === $i) aria-current="true" @endif
                    >
                        {{ $group['title'] }}
                    </a>
                @endforeach
            </nav>

            <div class="flex-1"></div>

            <button
                type="button"
                class="dc-jump hidden md:inline-flex"
                x-on:click="$dispatch('dc-palette')"
                aria-label="{{ __('driver_check.nav.jump') }}"
            >
                <x-driver-check.icon name="search" class="h-4 w-4" />
                <span class="flex-1 text-left">{{ __('driver_check.nav.jump') }}</span>
                <span class="dc-key">Ctrl</span><span class="dc-key">K</span>
            </button>

            <button type="button" class="dc-iconbtn md:hidden" x-on:click="$dispatch('dc-palette')" aria-label="{{ __('driver_check.nav.jump') }}">
                <x-driver-check.icon name="search" class="h-[18px] w-[18px]" />
            </button>

            <button type="button" class="dc-iconbtn" x-on:click="$store.dcTheme.toggle()" aria-label="{{ __('driver_check.nav.theme') }}">
                <x-driver-check.icon name="moon" class="h-[18px] w-[18px] dark:hidden" />
                <x-driver-check.icon name="sun" class="hidden h-[18px] w-[18px] dark:block" />
            </button>

            {{-- Account --}}
            <div class="relative" x-on:click.outside="userOpen = false">
                <button
                    type="button"
                    x-on:click="userOpen = !userOpen"
                    :aria-expanded="userOpen"
                    class="ml-1 flex h-8 items-center gap-2 rounded-md pl-1 pr-2 text-[13px] font-medium text-[var(--dc-ink-text)] transition hover:bg-white/10"
                >
                    @if ($dcUser?->avatar?->path)
                        <img src="{{ asset('storage/' . $dcUser->avatar->path) }}" alt="" class="h-6 w-6 rounded-md object-cover">
                    @else
                        <span class="grid h-6 w-6 place-items-center rounded-md bg-white/15 text-[11px] font-bold text-white">{{ $dcInitials }}</span>
                    @endif
                    <span class="hidden max-w-[140px] truncate xl:inline">{{ $dcName }}</span>
                    <x-driver-check.icon name="chevron-down" class="hidden h-3.5 w-3.5 opacity-60 xl:block" />
                </button>

                <div
                    x-show="userOpen"
                    x-cloak
                    x-transition.origin.top.right.duration.150ms
                    class="absolute right-0 top-[calc(100%+8px)] z-[100] w-64 overflow-hidden rounded-xl border border-gray-200 bg-white text-gray-800 shadow-xl dark:border-gray-800 dark:bg-gray-dark dark:text-gray-200"
                >
                    <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                        <p class="truncate text-sm font-semibold">{{ $dcName }}</p>
                        <p class="truncate text-xs text-gray-500">{{ $dcUser?->email }}</p>
                    </div>

                    <div class="p-1.5">
                        <a href="{{ url($dcProfilePath) }}" class="flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-[13px] font-medium hover:bg-gray-100 dark:hover:bg-white/[0.06]">
                            <x-driver-check.icon name="user" class="h-4 w-4 text-gray-500" />
                            {{ __('driver_check.profile.title') }}
                        </a>

                        @if ($dcRoleName === 'superadmin')
                            <a href="{{ route('departments.index') }}" class="flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-[13px] font-medium hover:bg-gray-100 dark:hover:bg-white/[0.06]">
                                <x-driver-check.icon name="arrow-left" class="h-4 w-4 text-gray-500" />
                                {{ __('driver_check.nav.to_postix') }}
                            </a>
                        @endif
                    </div>

                    <div class="border-t border-gray-100 p-1.5 dark:border-gray-800">
                        <p class="px-2.5 pb-1 pt-1.5 text-[10px] font-semibold uppercase tracking-[0.1em] text-gray-400">{{ __('driver_check.nav.language') }}</p>
                        <div class="grid grid-cols-3 gap-1 px-1 pb-1">
                            @foreach (['uz' => 'UZ', 'ru' => 'RU', 'en' => 'EN'] as $code => $label)
                                <a href="{{ url('/lang/' . $code) }}" class="rounded-md border px-2 py-1.5 text-center text-xs font-semibold transition {{ $dcLocale === $code ? 'border-gray-900 bg-gray-900 text-white dark:border-white dark:bg-white dark:text-gray-900' : 'border-gray-200 text-gray-600 hover:border-gray-300 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.06]' }}">{{ $label }}</a>
                            @endforeach
                        </div>
                    </div>

                    <form method="POST" action="{{ route('logout') }}" class="border-t border-gray-100 p-1.5 dark:border-gray-800">
                        @csrf
                        <button type="submit" class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-[13px] font-medium text-error-600 hover:bg-error-25 dark:text-error-400 dark:hover:bg-error-500/10">
                            <x-driver-check.icon name="logout" class="h-4 w-4" />
                            {{ __('driver_check.nav.logout') }}
                        </button>
                    </form>
                </div>
            </div>

            {{-- Sections (phone) --}}
            <button type="button" class="dc-iconbtn lg:hidden" x-on:click="$store.dcShell.menuOpen = true" aria-label="{{ __('driver_check.nav.menu') }}">
                <x-driver-check.icon name="menu" class="h-5 w-5" />
            </button>
        </div>
    </header>

    {{-- ================= Second tier: pages of the open section ================= --}}
    <div class="dc-sub sticky z-[89]" style="top: var(--dc-bar-h)">
        <nav class="dc-sub__row" aria-label="{{ __('driver_check.nav.pages') }}">
            @if ($dcActive !== null)
                @foreach ($dcGroups[$dcActive]['items'] as $item)
                    <a
                        href="{{ url($item['path']) }}"
                        class="dc-link"
                        @if ($dcCurrent && $dcCurrent['path'] === $item['path']) aria-current="page" @endif
                    >
                        <x-driver-check.icon :name="$item['dc']" class="h-[15px] w-[15px] opacity-70" />
                        {{ $item['name'] }}
                    </a>
                @endforeach
            @else
                <a href="{{ url('/driver-check') }}" class="dc-link">
                    <x-driver-check.icon name="arrow-left" class="h-[15px] w-[15px] opacity-70" />
                    {{ __('driver_check.nav.back') }}
                </a>
                <span class="dc-link" aria-current="page">{{ $dcOnProfile ? __('driver_check.profile.title') : trim($__env->yieldContent('title', '')) }}</span>
            @endif
        </nav>
    </div>

    {{-- ================= Page ================= --}}
    <main class="dc-page">
        @yield('content')
    </main>

    {{-- ================= Command palette ================= --}}
    <div
        x-data="dcPalette(@js($dcPalette))"
        x-on:dc-palette.window="show()"
        x-show="open"
        x-cloak
        x-on:keydown.escape.window="hide()"
        class="fixed inset-0 z-[100060] flex items-start justify-center px-3 pt-[12vh]"
        role="dialog"
        aria-modal="true"
        aria-label="{{ __('driver_check.nav.jump') }}"
    >
        <div
            x-show="open"
            x-transition.opacity.duration.150ms
            x-on:click="hide()"
            class="absolute inset-0 bg-gray-950/55 backdrop-blur-[2px]"
        ></div>

        <div
            x-show="open"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 -translate-y-2 scale-[0.98]"
            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
            class="dc-palette relative"
        >
            <div class="flex items-center gap-3 border-b border-gray-100 px-4 dark:border-gray-800">
                <x-driver-check.icon name="search" class="h-4 w-4 text-gray-400" />
                <input
                    x-ref="field"
                    x-model="query"
                    x-on:keydown.arrow-down.prevent="move(1)"
                    x-on:keydown.arrow-up.prevent="move(-1)"
                    x-on:keydown.enter.prevent="go()"
                    type="text"
                    autocomplete="off"
                    spellcheck="false"
                    placeholder="{{ __('driver_check.nav.jump_placeholder') }}"
                    class="h-12 flex-1 border-0 bg-transparent text-[15px] text-gray-900 outline-none placeholder:text-gray-400 dark:text-white"
                >
                <span class="rounded border border-gray-200 px-1.5 py-0.5 text-[10px] font-semibold text-gray-400 dark:border-gray-700">ESC</span>
            </div>

            <div x-ref="list" class="max-h-[50vh] overflow-y-auto py-1.5">
                <template x-for="(item, i) in results" :key="item.path">
                    <button
                        type="button"
                        class="dc-palette__item"
                        :data-on="i === index ? '' : null"
                        x-on:mouseenter="index = i"
                        x-on:click="index = i; go()"
                    >
                        <x-driver-check.icon name="chevron-right" class="h-3.5 w-3.5 text-gray-300" />
                        <span class="flex-1 truncate font-medium" x-text="item.name"></span>
                        <span class="truncate text-xs text-gray-400" x-text="item.group"></span>
                    </button>
                </template>

                <p x-show="results.length === 0" class="px-4 py-8 text-center text-sm text-gray-500">{{ __('driver_check.nav.nothing') }}</p>
            </div>
        </div>
    </div>

    {{-- ================= Section menu (phone) ================= --}}
    <div
        x-data
        x-show="$store.dcShell.menuOpen"
        x-cloak
        x-on:keydown.escape.window="$store.dcShell.menuOpen = false"
        class="fixed inset-0 z-[100040] lg:hidden"
        role="dialog"
        aria-modal="true"
    >
        <div x-show="$store.dcShell.menuOpen" x-transition.opacity x-on:click="$store.dcShell.menuOpen = false" class="absolute inset-0 bg-gray-950/60"></div>

        <aside
            x-show="$store.dcShell.menuOpen"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            class="absolute inset-y-0 right-0 flex w-[min(340px,88vw)] flex-col overflow-y-auto bg-[var(--dc-ink)] text-[var(--dc-ink-text)]"
        >
            <div class="flex h-[var(--dc-bar-h)] shrink-0 items-center justify-between border-b border-white/10 px-4">
                <span class="text-sm font-semibold text-white">Driver Check</span>
                <button type="button" class="dc-iconbtn" x-on:click="$store.dcShell.menuOpen = false" aria-label="{{ __('telegram.ui.close') }}">
                    <x-driver-check.icon name="close" class="h-5 w-5" />
                </button>
            </div>

            <div class="flex-1 px-2 py-3">
                @foreach ($dcGroups as $i => $group)
                    <p class="px-3 pb-1.5 pt-3 text-[10px] font-semibold uppercase tracking-[0.12em] text-[var(--dc-ink-dim)]">{{ $group['title'] }}</p>
                    @foreach ($group['items'] as $item)
                        <a
                            href="{{ url($item['path']) }}"
                            class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition {{ $dcCurrent && $dcCurrent['path'] === $item['path'] ? 'bg-white/10 text-white' : 'hover:bg-white/[0.06]' }}"
                        >
                            <x-driver-check.icon :name="$item['dc']" class="h-[18px] w-[18px] opacity-70" />
                            {{ $item['name'] }}
                        </a>
                    @endforeach
                @endforeach
            </div>

            <div class="shrink-0 border-t border-white/10 p-2">
                <a href="{{ url($dcProfilePath) }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium hover:bg-white/[0.06]">
                    <x-driver-check.icon name="user" class="h-[18px] w-[18px] opacity-70" />
                    {{ __('driver_check.profile.title') }}
                </a>
            </div>
        </aside>
    </div>

    @stack('scripts')

</body>
</html>
