@props([
    // plain  - bare mono text in muted ink (sizes, dates, counts)
    // chip   - the boxed grey badge used for formats and folder names
    // accent - the boxed brand-tinted badge (file counts, active-link counts)
    // strong - bare mono in secondary ink, for values that carry weight
    'variant' => 'plain',

    '3xs' => false,
    'size' => null,
])

@php
    // Machine values are mono throughout the design - sizes, durations,
    // timestamps, file counts, format badges, URLs, tokens, release IDs,
    // percentages, recovery codes. This component exists so that rule is
    // greppable rather than scattered as loose `font-mono` classes.
    $size ??= $variant === 'chip' || $variant === 'accent' ? 'text-3xs' : 'text-2xs';

    $variants = [
        'plain' => 'text-ink-muted',
        'strong' => 'text-ink-secondary font-semibold',
        // No uppercase: the chip carries format badges and folder names, and the
        // design leaves both as written. The value decides its own casing.
        'chip' => 'bg-line/10 text-ink-muted rounded-xs px-1.5 py-0.5 font-semibold',
        'accent' => 'bg-brand-soft text-brand-text rounded-xs px-1.5 py-0.5 font-semibold',
    ];
@endphp

<span {{ $attributes->class([
    'font-mono tabular-nums',
    $size,
    $variants[$variant] ?? $variants['plain'],
    'inline-flex items-center' => in_array($variant, ['chip', 'accent'], true),
]) }}>{{ $slot }}</span>
