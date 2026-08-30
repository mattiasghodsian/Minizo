@props([
    'label' => null,
])

@php
    $label ??= __('Actions');
@endphp

{{--
    The per-row "…" menu. Its panel has to escape the row's box, which is what the
    data-table grid exists to allow.

    Items come from the caller, but the permission rule belongs here, in
    x-ui.row-menu.item:

      not granted                -> not rendered at all
      granted but globally off   -> rendered at 35% opacity and inert

    See App\Support\Permissions for why those are different questions.
--}}
<flux:dropdown position="bottom" align="end" offset="4">
    <x-ui.icon-button icon="ellipsis-horizontal" :label="$label" />

    <flux:menu class="bg-surface-raised border-border-strong shadow-dropdown min-w-44 rounded-xl border">
        {{ $slot }}
    </flux:menu>
</flux:dropdown>
