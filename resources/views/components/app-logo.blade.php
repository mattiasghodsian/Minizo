@props([
    'sidebar' => false,

    // 34px in the app shell, smaller on the public share page's top bar.
    'size' => 34,
])

{{--
    The brand lockup: gradient mark plus wordmark.

    The SLOT is sized, not just the mark: flux:sidebar.brand renders it inside a
    `[:where(&)]:h-6 ... overflow-hidden` box that clips a 34px mark to 24px.

    Sized with an inline style, not a class. A class built by interpolation is
    invisible to Tailwind's scanner, so no such utility is ever generated.
--}}
@php
    $slotStyle = "height: {$size}px; width: {$size}px; min-width: {$size}px; overflow: visible;";
@endphp

@if ($sidebar)
    <flux:sidebar.brand :name="config('app.name', 'Minizo')" {{ $attributes }}>
        <x-slot:logo :style="$slotStyle">
            <x-app-logo-icon :size="$size" />
        </x-slot:logo>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name', 'Minizo')" {{ $attributes }}>
        <x-slot:logo :style="$slotStyle">
            <x-app-logo-icon :size="$size" />
        </x-slot:logo>
    </flux:brand>
@endif
