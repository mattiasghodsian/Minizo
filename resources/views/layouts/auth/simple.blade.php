<!DOCTYPE html>
{{-- No class="dark" here: @fluxAppearance sets it on <html> from the stored
     preference before first paint. Hardcoding it forces a dark first frame and
     makes the appearance switcher unwinnable. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>

    {{-- bg-canvas rather than the starter kit's gradient. The share-glow behind the
            card is borrowed from the design's public page, so the sign-in screen reads
            as part of the same product. --}}
    <body class="bg-canvas text-ink min-h-dvh antialiased">
        <div class="relative flex min-h-dvh flex-col items-center justify-center gap-5 p-6 md:p-10">
            <div class="share-glow pointer-events-none absolute inset-x-0 top-0 h-[380px]" aria-hidden="true"></div>

            <a
                href="{{ route('home') }}"
                class="relative flex flex-col items-center gap-2.5"
                wire:navigate
            >
                <x-app-logo-icon :size="92" aria-label="{{ config('app.name', 'Minizo') }}" />
            </a>

            <div class="relative flex w-full flex-col items-center gap-5">
                {{ $slot }}
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
