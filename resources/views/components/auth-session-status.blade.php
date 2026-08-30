@props([
    'status',
])

@if ($status)
    {{-- text-success rather than text-green-600: the token carries the light/dark
         pair, which also rules out the invalid `!dark:text-green-400` ordering. --}}
    <div {{ $attributes->merge(['class' => 'text-success text-xs font-semibold']) }}>
        {{ $status }}
    </div>
@endif
