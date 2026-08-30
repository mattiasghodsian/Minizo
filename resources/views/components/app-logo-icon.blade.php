@props([
    // 34 in the app shell and on auth screens, 28 on the public share page's top bar.
    'size' => 34,
])

@php
    $size = (int) $size;
@endphp

{{--
    The Minizo mark: a ringed M, carried back from the pre-rework app.

    currentColor rather than a hex, defaulted to text-brand. Both themes declare
    --color-brand, so the mark flips by itself and never needs a dark: variant.

    Sized with an inline style, not a class. A class built by interpolation is
    invisible to Tailwind's scanner, so no such utility is ever generated.

    viewBox is 0 0 182 182 because that is exactly what the circle spans: r=87 plus
    half of the 8-wide stroke, either side of a centre at 91. The Figma export was
    185x186, the extra being slack for a drop shadow that is not drawn here - it
    only pushed the mark off-centre in its own box.

    No shape-rendering="crispEdges" either. It came off the same export and disables
    antialiasing, which leaves the circle visibly jagged at 34px.
--}}
<svg
    viewBox="0 0 182 182"
    fill="none"
    {{ $attributes->class('inline-block shrink-0') }}
    style="width: {{ $size }}px; height: {{ $size }}px; min-width: {{ $size }}px;"
    aria-hidden="true"
>
    <circle cx="91" cy="91" r="87" stroke="currentColor" stroke-width="8" />
    <path
        d="M42.9725 138V39.952H43.1005L95.4525 114.192L87.5165 112.4L139.741 39.952H139.997V138H121.437V81.808L122.589 91.408L90.7165 136.72H90.4605L57.6925 91.408L60.8925 82.576V138H42.9725Z"
        fill="currentColor"
    />
    <line x1="43" y1="135.5" x2="140" y2="135.5" stroke="currentColor" stroke-width="5" />
</svg>
