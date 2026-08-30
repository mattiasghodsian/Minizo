@props([
    'title' => null,
    'heading' => null,
    'subheading' => null,
])

{{--
    The default layout for every authenticated page.

    <flux:main> is the scroll container; the shell is h-dvh/overflow-hidden, which is
    what holds the sidebar brand row and footer nav still while content scrolls.

    heading/subheading are optional: x-app-header falls back to the
    config('minizo.pages') route map, so most pages pass nothing. The browser <title>
    reads the same map, because #[Title] is a compile-time attribute and cannot carry
    a folder name.
--}}
@php
    $title ??= \App\Support\PageHeading::heading();
@endphp

<x-layouts::app.shell :title="$title" :heading="$heading" :subheading="$subheading">
    <flux:main class="min-h-0 overflow-y-auto p-0!">
        {{-- max-w-content is --container-content, 65rem: the centred column the design
                    wraps all six screens in. --}}
        <div class="max-w-screen-2xl max-w mx-auto w-full px-7 pt-6 pb-10">
            {{ $slot }}
        </div>
    </flux:main>
</x-layouts::app.shell>
