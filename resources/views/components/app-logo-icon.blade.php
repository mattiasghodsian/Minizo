@props([
    // 34 in the app shell, 28 on the public share page's top bar.
    'size' => 34,

    // Corner radius. Defaults to ~29% of the box, which gives the design's 10px
    // at 34px and stays proportionate when the mark is scaled down.
    'radius' => null,
])

@php
    $size = (int) $size;
    $radius ??= (int) round($size * 0.29);
@endphp

<span
    {{ $attributes->class('inline-flex aspect-square shrink-0 items-center justify-center font-extrabold select-none') }}
    style="
        width: {{ $size }}px;
        height: {{ $size }}px;
        min-width: {{ $size }}px;
        border-radius: {{ $radius }}px;
        font-size: {{ round($size * 0.47) }}px;
        line-height: 1;
        color: var(--color-brand-ink);
        background-image: linear-gradient(135deg, var(--color-brand), color-mix(in oklab, var(--color-brand) 60%, var(--color-canvas)));
    "
    aria-hidden="true"
>M</span>
