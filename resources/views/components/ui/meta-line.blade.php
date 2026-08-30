@props([
    // Ordered list of already-formatted parts, e.g.
    //   ['12 tracks', '41 min', '0.47 GB', 'FLAC']
    // Nulls and empty strings are dropped, so callers can pass optional values
    // inline without building the array conditionally.
    'parts' => [],
])

@php
    $parts = array_values(array_filter(
        is_array($parts) ? $parts : [$parts],
        fn ($part) => filled($part),
    ));
@endphp

{{--
    The interpunct-joined meta line under a title: "12 tracks · 41 min · 0.47 GB".

    Mono throughout, because every part is a machine value. A real interpunct rather
    than a border, so it wraps and copies naturally.
--}}
<div {{ $attributes->class('text-ink-muted flex flex-wrap items-center gap-x-2 gap-y-1 font-mono text-xs tabular-nums') }}>
    @foreach ($parts as $index => $part)
        @if ($index > 0)
            <span class="text-ink-faint" aria-hidden="true">&middot;</span>
        @endif

        <span>{{ $part }}</span>
    @endforeach

    {{ $slot }}
</div>
