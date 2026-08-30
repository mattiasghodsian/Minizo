@props([
    'message' => null,
])

{{--
    The locked state of a sensitive Settings card, shown in place of its controls.

    A button rather than a link: the action stores url.intended first, so Fortify
    returns here instead of the Download screen.
--}}
<div {{ $attributes->class('border-field-border bg-sunken flex flex-wrap items-center gap-3 rounded-xl border px-4 py-3.5') }}>
    <flux:icon.lock-closed class="text-ink-faint size-4 shrink-0" />

    <p class="text-ink-secondary flex-1 text-2xs font-semibold">{{ $message }}</p>

    <flux:button size="sm" variant="primary" wire:click="confirmPassword">
        {{ __('Confirm password') }}
    </flux:button>
</div>
