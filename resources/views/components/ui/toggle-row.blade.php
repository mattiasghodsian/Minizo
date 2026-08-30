@props([
    'label' => null,
    'description' => null,

    // Dimmed and inert - the design's 35% state for a control you can see but cannot
    // currently use.
    'inert' => false,
])

{{--
    A labelled row with its control on the right: every toggle in the design, from the
    permission switches to the global sharing switch.

    Not flux:switch's own `label` / `description` props: those put the switch
    immediately after the text, where the design puts the text left and the control
    hard right, with a 12.5px label over an 11px muted description.
--}}
<div {{ $attributes->class([
    'flex items-start justify-between gap-6',
    'pointer-events-none opacity-35' => $inert,
]) }}>
    <div class="flex min-w-0 flex-col gap-1">
        @if ($label)
            <span class="text-ink text-xs font-bold">{{ $label }}</span>
        @endif

        @if ($description)
            <span class="text-ink-muted text-2xs leading-relaxed">{{ $description }}</span>
        @endif
    </div>

    {{-- The slot IS the control. shrink-0 so a long description never squeezes the
             switch out of shape. --}}
    <div class="shrink-0 pt-0.5">{{ $slot }}</div>
</div>
