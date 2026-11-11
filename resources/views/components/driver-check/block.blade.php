@props([
    'title' => null,
    'hint' => null,
    'stage' => null,
    'lazy' => false,
    'count' => null,
    'lines' => 4,
    'flush' => true,
])

{{--
    One block of a page: a panel with its name, its count and its own
    buttons in the head, and the body under it.

    `stage` ties the block to a stage of the page (resources/js/driver-check/
    stages.js): until the stage has loaded, the body is a placeholder; when
    it failed, the block says so and offers a retry - the other blocks go on.
    `lazy` loads the stage only when the block is scrolled near the screen.

    `count` is an Alpine expression shown as the number next to the title.
    `flush` (the default) leaves the body without padding - for a table.
--}}

<section
    {{ $attributes->merge(['class' => 'dc-block']) }}
    @if ($stage && $lazy) x-init="stageOnSight($el, '{{ $stage }}')" @endif
>
    @if ($title || isset($actions))
        <header class="dc-block__head">
            <div class="flex min-w-0 items-center gap-2.5">
                @if ($title)
                    <h2 class="dc-block__title">{{ $title }}</h2>
                @endif

                @if ($count)
                    <span class="dc-count" x-text="{{ $count }}"></span>
                @endif

                @if ($stage)
                    <span x-show="stages.{{ $stage }}.loading && stages.{{ $stage }}.loaded" x-cloak class="dc-live" title="{{ __('telegram.ui.loading') }}"></span>
                @endif

                @if ($hint)
                    <span class="hidden min-w-0 truncate text-[12px] text-gray-400 md:inline">{{ $hint }}</span>
                @endif
            </div>

            @isset($actions)
                <div class="dc-block__actions">
                    {{ $actions }}
                </div>
            @endisset
        </header>
    @endif

    @if ($stage)
        <div x-show="stages.{{ $stage }}.error" x-cloak class="dc-block__error" role="alert">
            <x-driver-check.icon name="alert" class="h-4 w-4 shrink-0 text-error-500" />
            <p class="dc-break min-w-0 flex-1" x-text="stages.{{ $stage }}.error"></p>
            <x-driver-check.button size="sm" icon="refresh" x-on:click="loadStage('{{ $stage }}')">
                {{ __('telegram.ui.retry') }}
            </x-driver-check.button>
        </div>

        <div x-show="!stages.{{ $stage }}.loaded && !stages.{{ $stage }}.error" class="dc-block__wait" aria-hidden="true">
            @for ($i = 0; $i < $lines; $i++)
                <div class="dc-skeleton h-3 rounded bg-gray-100 dark:bg-white/[0.06]" style="width: {{ [72, 54, 86, 40, 64, 78][$i % 6] }}%"></div>
            @endfor
        </div>

        <div x-show="stages.{{ $stage }}.loaded" x-cloak @class(['dc-block__body', 'dc-block__body--pad' => ! $flush])>
            {{ $slot }}
        </div>
    @else
        <div @class(['dc-block__body', 'dc-block__body--pad' => ! $flush])>
            {{ $slot }}
        </div>
    @endif

    @isset($foot)
        <footer class="dc-block__foot">
            {{ $foot }}
        </footer>
    @endisset
</section>
