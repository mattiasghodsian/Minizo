@props([
    // An App\Support\ExpiryLabel, which owns both the text and the tone.
    'expiry',
])

{{--
    The countdown badge on the Share links table: green "in 21h 40m", amber under an
    hour, red "Expired" / "Revoked".

    Squarer and more mono than x-ui.pill: it is a machine value in a table column, so
    the design gives it radius 6 and JetBrains Mono. The tone comes from ExpiryLabel,
    so the colour and the words cannot disagree.
--}}
@php
    $tones = [
        'success' => 'bg-success-soft text-success',
        'warning' => 'bg-warning-soft text-warning',
        'danger' => 'bg-danger-soft text-danger',
    ];
@endphp

<span {{ $attributes->class([
    'inline-flex items-center rounded-md px-2 py-[3px] font-mono text-3xs font-semibold tabular-nums whitespace-nowrap',
    $tones[$expiry->tone] ?? $tones['danger'],
]) }}>{{ $expiry->text }}</span>
