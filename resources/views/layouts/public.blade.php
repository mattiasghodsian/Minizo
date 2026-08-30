@props([
    'title' => null,
])

{{--
    The layout for the public share page. No app chrome and no authenticated state.

    Its own layout rather than a variant of layouts::app, which reads auth()->user()
    throughout: the sidebar folder list, the user row, every permission check. Sharing
    it would mean a null-user branch in a dozen places, or a page that renders
    differently depending on whether the visitor happens to hold a session.

    Forced dark: there is no appearance preference to read for someone with no
    account, and the design specifies one look for this page.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />

        {{-- A revocable link must not be indexed: a search result outliving it would be
                     worse than useless. --}}
        <meta name="robots" content="noindex, nofollow, noarchive" />

        {{-- Nor should the token leak to whatever the visitor clicks through to. --}}
        <meta name="referrer" content="no-referrer" />

        <title>{{ $title ?? config('app.name') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any" />

        @vite(['resources/css/app.css'])
    </head>

    <body class="bg-public text-ink min-h-dvh font-sans">
        {{-- The design's accent glow across the top 420px. Behind everything and
                     pointer-events-none, so it never intercepts a click on the download
                     button underneath. --}}
        <div class="share-glow pointer-events-none absolute inset-x-0 top-0 h-[420px]" aria-hidden="true"></div>

        <header class="border-hairline relative flex items-center gap-3 border-b px-7 py-5">
            <x-app-logo-icon :size="28" />

            <span class="text-[15px] font-extrabold tracking-[.2px]">{{ config('app.name') }}</span>

            {{-- The bare URL, as the design shows it: scheme stripped, mono, quiet. --}}
            <x-ui.mono size="text-3xs" class="pt-0.5">{{ $url ?? '' }}</x-ui.mono>
        </header>

        <main class="relative mx-auto w-full max-w-[760px] px-7 pt-13 pb-16">
            {{ $slot }}
        </main>
    </body>
</html>
