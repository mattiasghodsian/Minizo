@props([
    'current' => false,
])

@php
    $user = auth()->user();
@endphp

{{--
    The current-user row in the sidebar footer, which doubles as the Settings link.

    The design replaces the starter kit's dropdown with this row and Logout beneath
    it. Uses x-ui.tile so the user's identity colour matches the Users table, share
    owner columns and feed pills.
--}}
<a
    href="{{ route('settings.edit') }}"
    wire:navigate
    @if ($current) data-current @endif
    {{ $attributes->class([
        'group flex h-9 items-center gap-2.5 rounded-lg px-2.75 transition-colors',
        'text-ink-muted hover:bg-row-hover-strong hover:text-ink',
        'data-current:bg-brand-soft data-current:text-brand-text',
    ]) }}
>
    <x-ui.tile :name="$user->name" size="xs" round class="shrink-0" />

    {{-- Flux hides labels itself on its own components; a plain anchor has to opt
             in to the collapsed-sidebar behaviour. --}}
    <span class="in-data-flux-sidebar-collapsed-desktop:hidden truncate text-sm font-semibold">
        {{ $user->name }}
    </span>
</a>
