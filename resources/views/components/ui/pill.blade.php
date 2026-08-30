@props([
    'tone' => 'neutral',   // neutral | accent | success | warning | danger

    // Filter pills (Download / Feed / Share links) toggle between an unselected
    // outline and a brand-tinted selected state. When true, `tone` is ignored.
    'selected' => false,

    // Render as a button when it is interactive; the default span is decorative.
    'as' => 'span',
])

@php
    // Not flux:badge: the design's pills are fully round, 700-weight, and carry a
    // tone set and a selected state that flux:badge has no notion of. Wrapping it
    // would cost more than these few lines.
    $tones = [
        'neutral' => 'bg-line/12 text-ink-muted border-transparent',
        'accent' => 'bg-brand-soft text-brand-text border-transparent',
        'success' => 'bg-success-soft text-success border-transparent',
        'warning' => 'bg-warning-soft text-warning border-transparent',
        'danger' => 'bg-danger-soft text-danger border-transparent',
    ];

    $classes = $selected
        ? 'bg-brand-soft text-brand-text border-brand'
        : ($tones[$tone] ?? $tones['neutral']);

    $interactive = $as === 'button';
@endphp

<{{ $as }}
    @if ($interactive) type="button" @endif
    {{ $attributes->class([
        'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-2xs font-bold whitespace-nowrap',
        $classes,
        // An unselected filter pill needs a visible resting outline to read as
        // clickable; tone pills are filled and do not.
        'border-field-border text-ink-muted' => $interactive && ! $selected && $tone === 'neutral',
        'transition-colors hover:border-brand/60 cursor-pointer' => $interactive,
    ]) }}
>{{ $slot }}</{{ $as }}>
