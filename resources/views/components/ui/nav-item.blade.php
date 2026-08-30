@props([
    'href' => '#',
    'icon' => null,
    'current' => false,

    // 'nav'    - the main sections: Download, Feed, Share links, Users
    // 'folder' - a library folder: smaller, lighter, dimmed icon
    'variant' => 'nav',
])

@php
    // Wraps flux:sidebar.item rather than hand-rolling a link: labels hide on
    // collapse, a tooltip replaces them, and the mobile variant gets a taller target.
    //
    // The `!` on the active classes is required. The stub sets its own
    // data-current: background and border at normal specificity, so without the
    // important flags the design's accent-soft pill loses to a white one.
    $active = 'data-current:bg-brand-soft! data-current:text-brand-text! data-current:border-transparent!';

    $base = 'text-ink-muted hover:bg-row-hover-strong hover:text-ink '.$active;

    $classes = match ($variant) {
        // 9px/11px padding, 600 weight, 17px icon.
        'nav' => $base.' h-9! px-2.75! font-semibold',
        // 7px/11px padding, 500 weight; the folder glyph sits back at 70% opacity.
        'folder' => $base.' h-8! px-2.75! font-medium [&_svg]:opacity-70',
    };
@endphp

<flux:sidebar.item
    :href="$href"
    :icon="$icon"
    :current="$current"
    :class="$classes"
    {{ $attributes }}
>
    {{ $slot }}
</flux:sidebar.item>
