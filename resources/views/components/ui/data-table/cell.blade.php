@props([
    'align' => 'start',   // start | end | center
    'truncate' => true,
])

@php
    $alignment = match ($align) {
        'end' => 'justify-end text-end',
        'center' => 'justify-center text-center',
        default => 'justify-start text-start',
    };
@endphp

<div
    {{ $attributes->class([
        'flex min-w-0 items-center gap-2.5 text-sm',
        $alignment,
        // min-w-0 above plus truncate here is what stops a long filename from
        // blowing out the grid's 1fr column.
        'truncate' => $truncate,
    ]) }}
    role="cell"
>
    {{ $slot }}
</div>
