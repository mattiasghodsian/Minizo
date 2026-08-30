@props([
    'percent' => 0,

    // Either pass a DownloadStatus (preferred - it owns the tone mapping) or a
    // raw tone token name.
    'status' => null,
    'tone' => null,
])

@php
    $percent = max(0, min(100, (int) $percent));

    // DownloadStatus::tone() is the single source of truth for which colour a
    // status renders in, so the enum and the bar can never disagree.
    $tone ??= $status?->tone() ?? 'brand';
    $active = $status?->isActive() ?? false;

    $fills = [
        'brand' => 'bg-brand',
        'success' => 'bg-success',
        'danger' => 'bg-danger',
        'progress-queued' => 'bg-progress-queued',
        'ink-faint' => 'bg-ink-faint',
    ];
@endphp

{{-- 5px track, radius 4 - hand-rolled because flux:progress has one fixed look. --}}
<div
    {{ $attributes->class('bg-progress-track h-[5px] w-full overflow-hidden rounded-[4px]') }}
    role="progressbar"
    aria-valuenow="{{ $percent }}"
    aria-valuemin="0"
    aria-valuemax="100"
>
    <div
        class="{{ $fills[$tone] ?? $fills['brand'] }} h-full rounded-[4px] transition-[width] duration-300 @if ($active) animate-barpulse @endif"
        style="width: {{ $percent }}%"
    ></div>
</div>
