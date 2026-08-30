@props([
    // A count rendered as a mono badge beside the label (ACCOUNTS · 4).
    'count' => null,

    // 'card'  12/800 ls-1.2  - labels inside a section card
    // 'table' 10.5/800 ls-1  - column headers and the sidebar's LIBRARY row
    'variant' => 'card',

    // faint  #5b6478 - the default, and what column headers use
    // muted  #8a93a6 - standalone section labels above a card (QUEUE, RECENT ACTIVITY)
    // accent          - a label INSIDE its own card (NEW DOWNLOAD, SHARED FOLDER)
    'tone' => 'faint',
])

@php
    // The all-caps, wide-tracked label is one of the design's strongest motifs:
    // QUEUE, RECENT ACTIVITY, NEW DOWNLOAD, ACCOUNTS, LINKS BY, FEED FOR, LIBRARY.
    $variants = [
        'card' => 'text-xs tracking-[1.2px]',
        'table' => 'text-3xs tracking-[1px]',
    ];

    // Written out as literal classes: Tailwind only emits a utility it can see in
    // the source, so "text-{$tone}" assembled in PHP would compile to nothing.
    $tones = [
        'faint' => 'text-ink-faint',
        'muted' => 'text-ink-muted',
        'accent' => 'text-brand-text',
    ];
@endphp

<div {{ $attributes->class('flex items-center gap-2.5') }}>
    <span class="{{ $variants[$variant] ?? $variants['card'] }} {{ $tones[$tone] ?? $tones['faint'] }} font-extrabold uppercase">
        {{ $slot }}
    </span>

    @if (! is_null($count))
        <x-ui.mono variant="accent">{{ $count }}</x-ui.mono>
    @endif

    {{-- Right-aligned actions: the "+" new-folder button, "Add User", filters. --}}
    @isset($actions)
        <span class="ms-auto flex items-center gap-2">{{ $actions }}</span>
    @endisset
</div>
