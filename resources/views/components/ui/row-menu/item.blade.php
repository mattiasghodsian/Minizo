@props([
    'icon' => null,

    // The permission this action needs. Omit for an action that needs none.
    'permission' => null,

    // Pass a Permissions object when gating; usually auth()->user()->permissions().
    'permissions' => null,

    'tone' => 'default',

    // Set for an action that is possible but not right now - e.g. tagging a file
    // whose format has no tag writer. Renders inert like the dimmed state, but for a
    // per-row reason rather than an instance-wide one.
    'unavailable' => false,
    'unavailableReason' => null,
])

@php
    // The design's three-state rule, in one place:
    //
    //   not granted            -> render nothing
    //   granted, globally off  -> dimmed and inert, so the action is visibly disabled
    //   granted and effective  -> live
    $showItem = true;
    $inert = $unavailable;

    if ($permission !== null) {
        // Resolved here rather than above, so an item that gates on nothing does no
        // work at all. This renders once per menu item, and a Files page holds
        // several hundred of them.
        $permissions ??= auth()->user()?->permissions();

        if ($permissions !== null) {
            $showItem = $permissions->granted($permission);
            $inert = $inert || $permissions->dimmed($permission);
        }
    }

    // Computed here rather than inline in the component tag below. Blade's
    // component-tag parser is regex-based: an @if in attribute position is a
    // ParseError, and a multi-line array containing `//` compiles to an empty
    // component with no error at all. Keep tag attributes to simple expressions.
    $title = $inert ? $unavailableReason : null;

    $itemClasses = $attributes->get('class', '')
        .($tone === 'danger' ? ' text-danger!' : '')
        // 35% and inert, per the design's convention for an action you hold but
        // cannot currently use.
        .($inert ? ' pointer-events-none opacity-35' : ' cursor-pointer');
@endphp

@if ($showItem)
    <flux:menu.item :icon="$icon" :title="$title" :class="$itemClasses" {{ $attributes->except('class') }}>
        {{ $slot }}
    </flux:menu.item>
@endif
