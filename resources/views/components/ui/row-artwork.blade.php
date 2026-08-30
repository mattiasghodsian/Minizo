@props([
    // The string the fallback gradient's hue is derived from - a filename, a release title.
    'name' => '',

    // A URL that MIGHT serve real artwork. Same contract as x-ui.tile: nothing has to know
    // in advance whether it exists, because a 404 simply leaves the gradient showing.
    'cover' => null,

    // How far the artwork reaches across the row before the mask has fully faded it.
    // sm 96 · md 140 · lg 176 (px)
    'width' => 'md',
])

@php
    $hue = \App\Support\GeneratedArt::hue($name);

    $widths = [
        'sm' => 'w-24',
        'md' => 'w-35',
        'lg' => 'w-44',
    ];
@endphp

{{--
    Cover art as a bled-in background on the left edge of a row, behind the row's own
    text and fading out across it.

    Positioning contract: the row must be `relative`, and any cell that OVERLAPS the
    artwork must be `relative` too. Both are `z-index: auto`, so they paint in DOM
    order and the artwork, rendered first, stays behind. Not `isolate` plus a negative
    z-index: isolating the row would scope the row-menu's z-index to that row, and a
    dropdown opening downward would paint under the rows below it. Cells that do not
    overlap need nothing.

    Masked rather than covered by a gradient. An overlay has to be painted in the
    row's own colour to blend, and the row has four (rest and hover, light and dark).
    Masking to transparent blends with whatever is actually behind it. Peak alpha is
    below 1 so the artwork reads as a background rather than a picture.

    An <img> rather than background-image. A CSS background is fetched as soon as the
    element renders, so a hundred-row listing would request a hundred covers at once,
    each parsing a 30-40 MB FLAC. `loading="lazy"` keeps that to the rows on screen,
    and `onerror` removes the element for untagged files, which is the common case.
--}}
<span
    aria-hidden="true"
    {{ $attributes->class([
        'tile row-artwork pointer-events-none absolute inset-y-0 start-0 block overflow-hidden',
        $widths[$width] ?? $widths['md'],
    ]) }}
    style="--tile-h: {{ $hue }}"
>
    @if (filled($cover))
        <img
            src="{{ $cover }}"
            alt=""
            loading="lazy"
            decoding="async"
            onerror="this.remove()"
            class="size-full object-cover object-left"
        />
    @endif
</span>
