@props([
    'tooltipPosition' => 'right',
    'tooltip' => null,
    'inset' => null,
])

{{--
    Published override of flux:sidebar.collapse. Two changes from the stub:

    1. The icon. Flux draws a "panel" glyph; the design uses a chevron pointing left
       when expanded and right when collapsed. Neither the icon nor its colour is
       exposed as a prop, so the component has to be published to change them.

    2. Visibility. The stub fades out when collapsed and only returns on hover, which
       leaves no visible way back. This one stays put and flips direction.

    Everything else is verbatim, including <ui-sidebar-toggle>, which owns the toggle
    behaviour and the persisted collapsed state.
--}}
@php
    $tooltip ??= __('Toggle sidebar');

    $classes = Flux::classes()
        ->add('size-8 shrink-0 flex items-center justify-center')
        ->add($inset ? Flux::applyInset($inset, top: '-mt-2.5', right: '-me-2.5', bottom: '-mb-2.5', left: '-ms-2.5') : '');

    $buttonClasses = Flux::classes()
        ->add('size-8 relative inline-flex items-center justify-center rounded-md')
        ->add('text-ink-faint hover:text-ink hover:bg-row-hover-strong transition-colors')
        ->add('in-data-flux-sidebar-collapsed-desktop:cursor-e-resize rtl:in-data-flux-sidebar-collapsed-desktop:cursor-w-resize')
        ->add('[&[collapsible="mobile"]]:in-data-flux-sidebar-on-desktop:hidden')
        ->add('rtl:rotate-180');
@endphp

<ui-sidebar-toggle {{ $attributes->class($classes) }} data-flux-sidebar-collapse>
    <flux:tooltip :content="$tooltip" :position="$tooltipPosition">
        <button type="button" class="{{ $buttonClasses }}">
            {{-- Expanded: point left. --}}
            <svg
                class="in-data-flux-sidebar-collapsed-desktop:hidden size-4"
                viewBox="0 0 16 16"
                fill="none"
                aria-hidden="true"
            >
                <path d="M10 3.5L5.5 8L10 12.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
            </svg>

            {{-- Collapsed: point right. --}}
            <svg
                class="hidden in-data-flux-sidebar-collapsed-desktop:block size-4"
                viewBox="0 0 16 16"
                fill="none"
                aria-hidden="true"
            >
                <path d="M6 3.5L10.5 8L6 12.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>
    </flux:tooltip>
</ui-sidebar-toggle>
