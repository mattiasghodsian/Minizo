@props([
    'title',
    'description' => null,
])

{{--
    16/800 title over a 12px muted line, matching the design's modal headers.

    Plain h1/p rather than flux:heading: those carry a hardcoded dark:text-white/70
    that app.css then has to neutralise, and this gives the page a real <h1>.
--}}
<div {{ $attributes->class('flex w-full flex-col gap-1.5 text-center') }}>
    <h1 class="text-ink text-lg font-extrabold">{{ $title }}</h1>

    @if ($description)
        <p class="text-ink-muted text-xs">{{ $description }}</p>
    @endif
</div>
