@props([
    // The uppercase label rendered inside the card's top-left.
    'label' => null,
    'count' => null,

    // A label inside its own card is accent in the design (NEW DOWNLOAD); the
    // muted tones are for labels that sit above a card. See x-ui.section-label.
    'labelTone' => 'accent',
])

{{-- radius 14 / padding 22 / surface + border - the design's standard content card. --}}
<div {{ $attributes->class('bg-surface border-border rounded-2xl border p-5.5') }}>
    @if ($label || isset($actions))
        <x-ui.section-label :count="$count" :tone="$labelTone" class="mb-4">
            {{ $label }}

            @isset($actions)
                <x-slot:actions>{{ $actions }}</x-slot:actions>
            @endisset
        </x-ui.section-label>
    @endif

    {{ $slot }}
</div>
