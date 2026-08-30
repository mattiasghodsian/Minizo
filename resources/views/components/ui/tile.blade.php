@props([
    // The string the colour and letter are derived from: a folder name, an
    // artist, a username, a filename.
    'name' => '',

    // xs 19 · sm 24 · md 28 · lg 30 · xl 38 · 2xl 42 · cover 136  (px, from the design)
    'size' => 'md',

    // Round for people (avatars), rounded-rect for things (folders, files).
    'round' => false,

    // 'tile' = the 135deg gradient used everywhere; 'cover' = the richer 150deg
    // gradient used only for the big public-share-page artwork.
    'variant' => 'tile',

    // A URL that MIGHT serve artwork: an <img> layered over the gradient, shown only
    // once it loads. Deciding server-side would mean parsing every FLAC in a listing
    // before any HTML could be sent, so instead the endpoint 404s, the image never
    // loads, and the tile underneath stays visible.
    'cover' => null,

    // Alt text for the artwork. Decorative by default, because in a listing the filename
    // is already right next to it.
    'coverAlt' => null,

    // Set when the caller already knows artwork exists: the preview modal, a
    // single-track share page, the Feed. A known cover renders visible immediately
    // and needs no JavaScript, which is what makes it work on the public share page
    // with scripting off.
    'coverKnown' => false,

    // Whether to fetch up front rather than on scroll. Defaults to $coverKnown but is
    // a separate question: the Feed knows every cover exists and still wants them
    // lazy, since most of forty results are below the fold.
    'eager' => null,
])

@php
    $hue = \App\Support\GeneratedArt::hue($name);
    $initial = \App\Support\GeneratedArt::initial($name);

    // Size => [box classes, initial's type scale]. Values are the prototype's.
    $sizes = [
        'xs' => ['size-[19px]', 'text-[9px]'],
        'sm' => ['size-6', 'text-[10px]'],
        'md' => ['size-7', 'text-2xs'],
        'lg' => ['size-[30px]', 'text-xs'],
        'xl' => ['size-[38px]', 'text-sm'],
        '2xl' => ['size-[42px]', 'text-md'],
        'cover' => ['size-[136px]', 'text-6xl'],
    ];

    [$box, $type] = $sizes[$size] ?? $sizes['md'];

    // Radius scales with the box, per the design (28px tiles use 7px, 136px uses 16px).
    $radius = $round ? 'rounded-full' : match ($size) {
        'xs', 'sm' => 'rounded-sm',
        'md' => 'rounded-md',
        'lg', 'xl' => 'rounded-lg',
        '2xl' => 'rounded-xl',
        'cover' => 'rounded-3xl',
    };

    $gradient = $variant === 'cover' ? 'tile-cover' : 'tile';
@endphp

<span
    {{ $attributes->class([
        $gradient,
        $box,
        $type,
        $radius,
        'inline-flex shrink-0 items-center justify-center font-extrabold select-none',
        'text-white/90',
        $variant === 'cover' ? 'shadow-cover' : '',
        // Only needed when artwork may be layered on top.
        'relative overflow-hidden' => filled($cover),
    ]) }}
    style="--tile-h: {{ $hue }}"
    @if ($initial !== '' && blank($cover)) aria-hidden="true" @endif
>{{ $initial }}@if (filled($cover))
        {{--
                    A speculative cover starts hidden and reveals itself on load; a known one
                    is visible from the start. Needed because a bare <img> that 404s shows the
                    browser's broken-image glyph, which no CSS can style away. onerror removes
                    the element so the gradient underneath remains.
        
                    Inline handlers rather than Alpine: this renders on the public share page,
                    which loads no JavaScript framework at all.
                --}}
        <img
            src="{{ $cover }}"
            alt="{{ $coverAlt ?? '' }}"
            loading="{{ ($eager ?? $coverKnown) ? 'eager' : 'lazy' }}"
            decoding="async"
            @class([
                'absolute inset-0 size-full object-cover',
                'opacity-0 transition-opacity duration-150' => ! $coverKnown,
            ])
            @if (! $coverKnown) onload="this.classList.remove('opacity-0')" @endif
            onerror="this.remove()"
        />
    @endif</span>
