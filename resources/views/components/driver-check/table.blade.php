@props([
    'min' => '720px',
    'sticky' => false,
])

{{--
    The one table of the console. The look is in .dc-table (driver-check.css):
    a quiet head, hairlines between the rows, a row lights up under the
    mouse, numbers are right-aligned in tabular figures (class `dc-num` on
    the th/td), the row's buttons sit in the last cell (class `dc-acts`).

    `min` is the width under which the table scrolls sideways instead of
    squeezing; `sticky` keeps the head in sight inside a tall list.
--}}

<div class="dc-table-wrap">
    <table {{ $attributes->merge(['class' => 'dc-table' . ($sticky ? ' dc-table--sticky' : '')]) }} style="min-width: {{ $min }}">
        {{ $slot }}
    </table>
</div>
