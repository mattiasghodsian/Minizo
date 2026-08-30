@props([
    // A CSS grid-template-columns value, e.g. "1fr 80px 90px 150px 76px".
    'cols' => '1fr',
])

{{--
    A CSS-grid table rather than flux:table, for three reasons:

    1. Every table in the design is an exact px grid (`1fr 80px 90px 150px 76px`).
    2. flux:table wraps rows in an overflow-auto area that clips the row dropdown.
    3. The design's hairlines are a per-row border-bottom, not a per-cell border-top.

    This wrapper clips. Flux renders an open menu as a `popover` in the browser's top
    layer, so nothing clips it, and clipping here lets the row artwork bleed to the
    left edge without poking a square corner through the card's rounded one.

    A grid of divs has no table semantics, so the ARIA roles below are what screen
    readers use. Any new row or cell markup must carry them.

    `--cols` is an inline style because the value is an arbitrary per-table string and
    a class assembled in PHP is never seen by Tailwind's scanner. The consequence: a
    responsive override from a caller (`lg:[--cols:…]`) must carry `!` to outrank it.
--}}
<div
    {{ $attributes->class('bg-surface border-border overflow-hidden rounded-2xl border') }}
    style="--cols: {{ $cols }}"
    role="table"
>
    {{ $slot }}
</div>
