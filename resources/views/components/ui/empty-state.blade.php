@props([
    'message' => null,

    // The Feed's empty state uses a dashed outline; table empties are bare.
    'dashed' => false,

    // A flux icon name rendered above the message.
    'icon' => null,
])

<div {{ $attributes->class([
    'flex flex-col items-center justify-center gap-2.5 px-6 py-10 text-center',
    'border-border rounded-2xl border border-dashed' => $dashed,
]) }}>
    @if ($icon)
        <flux:icon :name="$icon" class="text-ink-faint size-6" />
    @endif

    <p class="text-ink-faint max-w-md text-xs">
        {{ $message ?? $slot }}
    </p>

    {{-- Optional call to action, e.g. "Add your first folder". --}}
    @isset($action)
        <div class="mt-1">{{ $action }}</div>
    @endisset
</div>
