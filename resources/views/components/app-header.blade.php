@props([
    'heading' => null,
    'subheading' => null,
])

@php
    // An explicit :heading wins, then the config('minizo.pages') route map, then
    // nothing. PageHeading is shared with partials.head so the browser tab agrees.
    [$mappedHeading, $mappedSubheading] = \App\Support\PageHeading::current();

    $heading ??= $mappedHeading;
    $subheading ??= $mappedSubheading;
@endphp

<div class="min-w-0 flex-1">
    <h1 class="text-ink truncate text-lg font-extrabold">{{ $heading }}</h1>

    @if ($subheading)
        {{-- x-text lets a page replace the subtitle at runtime via a `page-subheading`
             event. Only the Feed uses it, for the admin preview line. --}}
        <p
            class="text-ink-muted truncate text-xs"
            x-data
            x-on:page-subheading.window="$el.textContent = $event.detail.text"
        >{{ $subheading }}</p>
    @endif
</div>
