@props([
    // The Livewire action(s) this waits on, as wire:target expects them.
    'target' => null,

    'message' => null,

    // Cover the parent rather than sit beside it. Right for a wait that replaces
    // something; a wait that accompanies something which stays put wants the inline
    // form instead. The parent must be `relative`.
    'overlay' => false,
])

{{--
    "Something is happening" for an action with no visible result until it finishes.

    wire:loading.FLEX, not a bare wire:loading: Livewire sets display inline when it
    shows an element, and its inline-block default beats a `flex` class in the bag,
    dropping the label onto its own line.
--}}
<div
    wire:loading.flex
    wire:target="{{ $target }}"
    {{ $attributes->class([
        'items-center gap-2 text-xs font-semibold',
        'text-ink-muted' => ! $overlay,
        // A scrim rather than a bare label: it mutes the stale table underneath, so the
        // overlay reads as "this is being replaced" rather than as a caption.
        'bg-surface/75 text-ink-secondary absolute inset-0 z-10 justify-center rounded-2xl' => $overlay,
    ]) }}
    role="status"
    aria-live="polite"
>
    <flux:icon.loading class="size-4 shrink-0" />
    <span>{{ $message ?? __('Working…') }}</span>
</div>
