<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

{{-- css only: resources/js/app.js is empty, and Livewire and Flux ship their own
     bundles through @livewireScripts / @fluxScripts. Listing it cost a script tag
     and a request on every authenticated page for a 0-byte file. --}}
@vite(['resources/css/app.css'])
@fluxAppearance
