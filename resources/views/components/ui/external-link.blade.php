@props([
    'href' => '#',

    // Machine-ish links (a share URL, a Tidal link) render mono.
    'mono' => false,
])

{{--
    The "GitHub ↗" treatment: label plus a small north-east arrow.

    rel="noopener" matters here: several of these point at URLs that came from
    third-party API responses.
--}}
<a
    href="{{ $href }}"
    target="_blank"
    rel="noopener noreferrer"
    {{ $attributes->class([
        'text-ink-muted hover:text-brand-text inline-flex items-center gap-1 transition-colors',
        $mono ? 'font-mono text-2xs' : 'text-xs font-semibold',
    ]) }}
>
    <span class="truncate">{{ $slot }}</span>

    <svg viewBox="0 0 16 16" fill="none" class="size-3 shrink-0" aria-hidden="true">
        <path d="M6 3.5h6.5V10" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" />
        <path d="M12.5 3.5L4 12" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
    </svg>
</a>
