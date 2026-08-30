@props([
    'title' => null,
    'heading' => null,
    'subheading' => null,
])

@php
    $user = auth()->user();

    // The sidebar's folder list. visibleTo() is the only listing a component may
    // call, since it applies folder access; all() is named to make an unfiltered call
    // obvious in review. Memoised per request by LibraryCache, so the sidebar, the
    // page body and any policy check share one filesystem read.
    $folders = $user !== null
        ? app(\App\Services\Library\FolderService::class)->visibleTo($user)
        : [];

    $currentFolder = request()->route()?->parameter('directory');
@endphp

<!DOCTYPE html>
{{-- No class="dark" here: @fluxAppearance sets it on <html> from the stored
     preference before first paint. Hardcoding it forces a dark first frame and
     makes the appearance switcher unwinnable. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>

    {{-- h-dvh + overflow-hidden because the shell does not scroll: the sidebar and
             header are fixed and <flux:main> is the scroll container. --}}
    <body class="bg-canvas text-ink h-dvh overflow-hidden">
        {{-- collapsible="true" gives both the desktop collapse and the mobile
                    off-canvas drawer from one element, so no second mirrored sidebar is
                    needed. Flux also emits
                      grid-template-areas: "sidebar header header" / "sidebar main aside"
                    when a header follows a sidebar, which is the design's geometry. --}}
        {{-- The collapsed-width variant has NO `in-` prefix. Flux marks the collapsed
                    sidebar on the element itself, so `data-flux-...:` is the form that
                    matches; `in-...:` compiles to a descendant selector and is for the child
                    rules below. Mixing them up fails silently, and Flux's 56px default wins
                    over the design's 64. --}}
        <flux:sidebar
            sticky
            collapsible="true"
            class="bg-sidebar border-hairline w-sidebar data-flux-sidebar-collapsed-desktop:w-sidebar-collapsed! flex flex-col gap-0! overflow-hidden border-e p-0!"
        >
            {{-- Brand -------------------------------------------------------- --}}
            <div class="border-hairline flex h-16 shrink-0 items-center gap-2.5 border-b px-4">
                <a href="{{ route('download') }}" wire:navigate class="flex min-w-0 items-center gap-2.5">
                    <x-app-logo-icon :size="34" />
                    <span class="in-data-flux-sidebar-collapsed-desktop:hidden truncate text-xl font-extrabold tracking-[0.2px]">
                        {{ config('app.name', 'Minizo') }}
                    </span>
                </a>

                <flux:sidebar.collapse class="in-data-flux-sidebar-collapsed-desktop:ms-0 ms-auto" />
            </div>

            {{-- Main sections ------------------------------------------------ --}}
            <flux:sidebar.nav class="shrink-0 px-2.5 pt-3">
                <x-ui.nav-item
                    icon="arrow-down-tray"
                    :href="route('download')"
                    :current="request()->routeIs('download')"
                    wire:navigate
                >{{ __('Download') }}</x-ui.nav-item>

                <x-ui.nav-item
                    icon="rss"
                    :href="route('feed')"
                    :current="request()->routeIs('feed')"
                    wire:navigate
                >{{ __('Feed') }}</x-ui.nav-item>

                {{-- granted(), not effective(): the audit screen must stay reachable when the
                                     instance switch is off, which is when someone most wants to see
                                     which links are still live. --}}
                @if ($user?->permissions()->granted(\App\Enums\Permission::Share))
                    <x-ui.nav-item
                        icon="link"
                        :href="route('shares')"
                        :current="request()->routeIs('shares')"
                        wire:navigate
                    >{{ __('Share links') }}</x-ui.nav-item>
                @endif
            </flux:sidebar.nav>

            {{-- Library ------------------------------------------------------ --}}
            <div class="in-data-flux-sidebar-collapsed-desktop:hidden mt-5 shrink-0 px-4">
                <x-ui.section-label variant="table" class="tracking-[1.4px]!">
                    {{ __('Library') }}

                    @can('manage-folders')
                        <x-slot:actions>
                            {{-- 22x22 "+", opening the folder manager at the bottom of this
                                                             layout. --}}
                            <button
                                type="button"
                                {{-- Livewire.dispatch, not Alpine's $dispatch: this button sits
                                                                     in the layout, outside any Livewire component, so
                                                                     a DOM event would never reach the #[On] listener. --}}
                                x-on:click="Livewire.dispatch('folder-create')"
                                class="text-ink-faint hover:text-ink hover:bg-row-hover-strong flex size-[22px] cursor-pointer items-center justify-center rounded-sm transition-colors"
                                aria-label="{{ __('New folder') }}"
                                title="{{ __('New folder') }}"
                            >
                                <flux:icon.plus class="size-3.5" />
                            </button>
                        </x-slot:actions>
                    @endcan
                </x-ui.section-label>
            </div>

            {{-- The only scrolling region. min-h-0 lets it shrink inside the flex
                             column instead of pushing the footer off-screen. --}}
            <flux:sidebar.nav class="min-h-0 flex-1 overflow-y-auto px-2.5 py-2">
                @forelse ($folders as $folder)
                    <x-ui.nav-item
                        variant="folder"
                        icon="folder"
                        :href="route('files', $folder->name)"
                        :current="$folder->is((string) $currentFolder)"
                        wire:navigate
                    >{{ $folder->name }}</x-ui.nav-item>
                @empty
                    <p class="text-ink-faint in-data-flux-sidebar-collapsed-desktop:hidden px-2.75 py-2 text-2xs">
                        {{ __('No folders yet.') }}
                    </p>
                @endforelse
            </flux:sidebar.nav>

            {{-- Footer ------------------------------------------------------- --}}
            <div class="border-hairline shrink-0 border-t px-2.5 py-2">
                @can('manage-users')
                    <x-ui.nav-item
                        icon="users"
                        :href="route('users')"
                        :current="request()->routeIs('users')"
                        wire:navigate
                    >{{ __('Users') }}</x-ui.nav-item>
                @endcan

                <x-ui.nav-user-row :current="request()->routeIs('settings.*', 'profile.edit', 'security.edit', 'appearance.edit')" />

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button
                        type="submit"
                        data-test="logout-button"
                        class="text-ink-muted hover:bg-row-hover-strong hover:text-ink flex h-9 w-full cursor-pointer items-center gap-3 rounded-lg px-2.75 text-sm font-semibold transition-colors"
                    >
                        <flux:icon.arrow-right-start-on-rectangle class="size-4 shrink-0" />
                        <span class="in-data-flux-sidebar-collapsed-desktop:hidden">{{ __('Log out') }}</span>
                    </button>
                </form>
            </div>
        </flux:sidebar>

        {{-- Header ---------------------------------------------------------- --}}
        <flux:header
            sticky
            class="border-hairline bg-canvas h-auto! min-h-0! gap-4 border-b px-7 py-3.5"
        >
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <x-app-header :heading="$heading" :subheading="$subheading" />

            <div class="flex items-center gap-4">
                {{-- The links drop on a narrow header; the search and appearance switch do
                     not. They are the only controls up here, and the ones worth the width. --}}
                <div class="hidden items-center gap-4 sm:flex">
                    <x-ui.external-link href="https://github.com/mattiasghodsian/Minizo">GitHub</x-ui.external-link>
                    <x-ui.external-link href="https://hub.docker.com/r/rakma/minizo">Docker</x-ui.external-link>
                </div>

                {{-- A shortcut nobody can see is a shortcut nobody uses, so it gets a real
                     control rather than only a key binding. --}}
                <button
                    type="button"
                    x-data
                    x-on:click="$dispatch('modal-show', { name: 'palette' })"
                    class="border-border bg-sunken text-ink-faint hover:text-ink-muted flex shrink-0 items-center gap-2 rounded-lg border px-2.5 py-1.5 transition-colors"
                    aria-label="{{ __('Search') }}"
                >
                    <flux:icon.magnifying-glass class="size-3.5" />
                    <span class="hidden text-2xs font-semibold md:inline">{{ __('Search') }}</span>
                    <x-ui.mono variant="chip" size="text-3xs" class="hidden md:inline-block">⌘K</x-ui.mono>
                </button>

                <x-ui.appearance-switch />
            </div>
        </flux:header>

        {{ $slot }}

        {{-- The folder modals live here rather than on a page: the "+" that opens them
                     is in the sidebar, so it is on every screen. Rename and delete are opened
                     by event from the Files screen. --}}
        @auth
            @can('manage-folders')
                <livewire:pages::folders.manager />
            @endcan

            {{-- The command palette, mounted here for the same reason as the folder modals:
                 it opens from every screen. Livewire.dispatch, not Alpine's $dispatch - the
                 handler below sits outside any Livewire component. --}}
            <livewire:pages::palette />

            {{--
                Ctrl+K and Cmd+K in one test, so a single build works on every platform,
                with preventDefault so it does not open the browser's own search.

                A plain DOM event, NOT Livewire.dispatch: `modal-show` is what Flux's own
                dialog listens for, so the modal opens on the spot. Routing this through
                the server instead cost a round trip before anything appeared, and the
                re-render that followed morphed the input and took the focus back off it.

                "/" is deliberately NOT bound here. The Files filter already renders a
                kbd="/" badge, so that key belongs to that input; giving one key two
                meanings depending on the screen is worse than either meaning alone.
            --}}
            <div
                x-data
                x-on:keydown.window="
                    if (($event.metaKey || $event.ctrlKey) && $event.key.toLowerCase() === 'k') {
                        $event.preventDefault();
                        $dispatch('modal-show', { name: 'palette' });
                    }
                "
            ></div>
        @endauth

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
