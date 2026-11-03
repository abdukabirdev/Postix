@extends('layouts.driver-check')

@section('title', __('driver_check.tree.home'))

{{--
    The home page: the map of the whole panel, the same one the left menu is
    drawn from (App\Helpers\DriverCheckNav). Zone by zone, every section is a
    card with what it is for, its subsections and their pages, so one look
    says where a thing lives. Each card is an anchor (#key) the page trail
    leads back to.
--}}

@section('content')
    <x-driver-check.shell>

        <x-driver-check.page-header
            :title="__('driver_check.tree.home')"
            :description="__('driver_check.tree.home_text')"
        >
            <x-slot:actions>
                <x-driver-check.button variant="primary" icon="play" :href="route('driver-check.control')">
                    {{ __('driver_check.tree.pages.control') }}
                </x-driver-check.button>
            </x-slot:actions>
        </x-driver-check.page-header>

        @foreach ($zones as $zone)
            <section class="flex flex-col gap-3">
                <h2 class="dc-eyebrow pt-2">{{ $zone['title'] }}</h2>

                <div class="grid items-start gap-4 lg:grid-cols-2 2xl:grid-cols-3">
                    @foreach ($zone['sections'] as $section)
                        <x-driver-check.surface id="{{ $section['key'] }}" class="flex scroll-mt-20 flex-col overflow-hidden">
                            <div class="flex items-start gap-3 border-b border-gray-100 px-4 py-3.5 dark:border-gray-800">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">
                                    <x-driver-check.icon :name="$section['icon']" class="h-[18px] w-[18px]" />
                                </span>
                                <div class="min-w-0">
                                    <h3 class="text-[15px] font-semibold leading-6 text-gray-950 dark:text-white">
                                        <a href="{{ url($section['first']) }}" class="hover:underline">{{ $section['title'] }}</a>
                                    </h3>
                                    <p class="mt-0.5 text-[12.5px] leading-snug text-gray-500 dark:text-gray-400">{{ $section['about'] }}</p>
                                </div>
                            </div>

                            <div class="grid gap-x-6 gap-y-4 p-4 sm:grid-cols-2">
                                @foreach ($section['groups'] as $group)
                                    <div class="min-w-0">
                                        <p class="mb-1 text-[11px] font-semibold uppercase tracking-[0.08em] text-gray-400">{{ $group['title'] }}</p>

                                        <ul class="flex flex-col">
                                            @foreach ($group['items'] as $item)
                                                <li>
                                                    <a
                                                        href="{{ url($item['path']) }}"
                                                        class="group -mx-2 flex items-center gap-2 rounded-md px-2 py-1.5 text-[13px] text-gray-700 transition hover:bg-gray-50 hover:text-gray-950 dark:text-gray-300 dark:hover:bg-white/[0.04] dark:hover:text-white"
                                                    >
                                                        <x-driver-check.icon :name="$item['icon']" class="h-4 w-4 shrink-0 text-gray-400 group-hover:text-brand-600 dark:group-hover:text-brand-400" />
                                                        <span class="min-w-0 flex-1 truncate">{{ $item['name'] }}</span>
                                                        @if ($item['also'])
                                                            <x-driver-check.icon name="external" class="h-3 w-3 shrink-0 opacity-50" />
                                                        @else
                                                            <x-driver-check.icon name="chevron-right" class="h-3.5 w-3.5 shrink-0 opacity-0 transition group-hover:opacity-60" />
                                                        @endif
                                                    </a>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endforeach
                            </div>
                        </x-driver-check.surface>
                    @endforeach
                </div>
            </section>
        @endforeach

    </x-driver-check.shell>
@endsection
