@props([
    'name',
    'title',
    'body' => null,

    // 'destructive' is the solid #e5484d "Delete Permanently" treatment;
    // 'delete-folder' is the design's separate solid red with near-black text.
    'variant' => 'destructive',

    'confirmLabel' => null,
    'cancelLabel' => null,

    // A wire: action string, e.g. "deleteFile".
    'confirm' => null,
])

@php
    $confirmLabel ??= __('Delete');
    $cancelLabel ??= __('Cancel');

    // The design genuinely has two solid reds with different text colours; the token
    // set keeps them distinct rather than collapsing them to one.
    $confirmClasses = $variant === 'delete-folder'
        ? 'bg-delete-folder! text-delete-folder-ink! hover:opacity-90'
        : 'bg-destructive! text-destructive-ink! hover:opacity-90';
@endphp

<x-ui.modal-shell :name="$name" :title="$title" tone="danger" :width="440">
    @if ($body)
        {{-- The tinted callout the design uses to slow a destructive action down. --}}
        <div class="bg-danger-soft border-danger-line text-ink-secondary rounded-xl border p-3.5 text-xs">
            {{ $body }}
        </div>
    @endif

    {{ $slot }}

    <x-ui.modal-footer>
        <flux:modal.close>
            <flux:button variant="ghost">{{ $cancelLabel }}</flux:button>
        </flux:modal.close>

        <flux:button
            :class="$confirmClasses"
            wire:click="{{ $confirm }}"
            wire:loading.attr="disabled"
        >{{ $confirmLabel }}</flux:button>
    </x-ui.modal-footer>
</x-ui.modal-shell>
