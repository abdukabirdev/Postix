{{--
    The map of the panel in the left menu (and in the phone drawer): the home
    page, then every zone with its sections. A section opens into its
    subsections and their pages; only one section is open at a time, the one
    of the open page by default. A page listed again in a second section
    (`also`) carries an arrow: it opens in its own section.

    Expects $dcZones (DriverCheckNav::zones()) and $dcHere (::locate()).
--}}

@php
    $dcHereSection = $dcHere['section']['key'] ?? null;
    $dcHerePath = $dcHere['item']['path'] ?? null;
    $dcOnHome = '/' . trim(request()->path(), '/') === \App\Helpers\DriverCheckNav::HOME;
@endphp

<nav class="dc-nav" x-data="dcNav(@js($dcHereSection))" aria-label="{{ __('driver_check.nav.sections') }}">
    <a href="{{ url(\App\Helpers\DriverCheckNav::HOME) }}" class="dc-nav__home" @if ($dcOnHome) aria-current="page" @endif>
        <x-driver-check.icon name="command" class="dc-nav__icon" />
        {{ __('driver_check.tree.home') }}
    </a>

    @foreach ($dcZones as $zone)
        <p class="dc-nav__zone">{{ $zone['title'] }}</p>

        @foreach ($zone['sections'] as $section)
            @php $isHere = $dcHereSection === $section['key']; @endphp

            <div>
                <button
                    type="button"
                    class="dc-nav__cat"
                    x-on:click="toggle(@js($section['key']))"
                    :aria-expanded="isOpen(@js($section['key'])) ? 'true' : 'false'"
                    aria-expanded="{{ $isHere ? 'true' : 'false' }}"
                    @if ($isHere) data-current @endif
                >
                    <x-driver-check.icon :name="$section['icon']" class="dc-nav__icon" />
                    <span class="min-w-0 flex-1 truncate">{{ $section['title'] }}</span>
                    <x-driver-check.icon name="chevron-down" class="dc-nav__chev" />
                </button>

                <div class="dc-nav__body" x-show="isOpen(@js($section['key']))" x-collapse @unless ($isHere) x-cloak @endunless>
                    @foreach ($section['groups'] as $group)
                        <p class="dc-nav__group">{{ $group['title'] }}</p>

                        @foreach ($group['items'] as $item)
                            <a
                                href="{{ url($item['path']) }}"
                                class="dc-nav__link"
                                @if (! $item['also'] && $dcHerePath === $item['path']) aria-current="page" @endif
                                @if ($item['also']) title="{{ __('driver_check.tree.also', ['section' => \App\Helpers\DriverCheckNav::locate($item['path'])['section']['title'] ?? '']) }}" @endif
                            >
                                <span class="min-w-0 flex-1">{{ $item['name'] }}</span>
                                @if ($item['also'])
                                    <x-driver-check.icon name="external" class="dc-nav__also" />
                                @endif
                            </a>
                        @endforeach
                    @endforeach
                </div>
            </div>
        @endforeach
    @endforeach
</nav>
