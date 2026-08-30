@props([
    'icon' => 'x-mark',
    'tone' => 'muted',   // muted | danger | brand
    'size' => 'md',      // sm (14px glyph) | md (16px) | lg (18px)

    // Required: these buttons are icon-only, so they must carry an accessible name.
    'label' => null,

    // Declared explicitly rather than left to the attribute bag: a bare
    // :disabled="false" still renders a `disabled` attribute on a plain <button>,
    // which would permanently disable every button that passes the prop.
    'disabled' => false,
])

@php
    $tones = [
        'muted' => 'text-ink-faint hover:text-ink hover:bg-row-hover-strong',
        'danger' => 'text-ink-faint hover:text-danger hover:bg-danger-soft',
        'brand' => 'text-ink-faint hover:text-brand-text hover:bg-brand-soft',
    ];

    $glyphs = ['sm' => 'size-3.5', 'md' => 'size-4', 'lg' => 'size-[18px]'];
@endphp

{{-- The bare 5px-padding icon buttons: the queue row's "×", per-row download
     arrows, the modal close, table row actions. flux:button carries a border,
     background and min-height these do not want. --}}
<button
    type="button"
    @disabled($disabled)
    @if ($label)
        aria-label="{{ $label }}"
        title="{{ $label }}"
    @endif
    {{ $attributes->class([
        'inline-flex items-center justify-center rounded-sm p-[5px] transition-colors',
        $tones[$tone] ?? $tones['muted'],
        'cursor-pointer' => ! $disabled,
        'cursor-default opacity-35' => $disabled,
    ]) }}
>
    <flux:icon :name="$icon" class="{{ $glyphs[$size] ?? $glyphs['md'] }}" />
</button>
