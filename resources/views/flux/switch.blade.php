@blaze(fold: true, unsafe: [
    // flux:with-inline-field props
    'name', 'label', 'description',
])

@props([
    'name' => null,
    'align' => 'right',
    'checked' => null,
])

{{--
    Published override of flux:switch. Three of the design's differences are not
    expressible as props:

    1. 36x21, not 32x20, with a 15px knob. Hardcoded utilities in the stub.
    2. A solid off-state. Flux renders the unchecked track as a transparent box with a
       `white/20` border; the design fills it, so an off switch looks off, not absent.
    3. A permanently white knob. Flux swaps it to --color-accent-foreground when
       checked, which with our tokens is near-black and vanishes into the track.

    Everything else is carried over unchanged. flux/navlist/group.blade.php is the
    precedent for publishing an override this way.
--}}
@php
// We only want to show the name attribute it has been set manually
// but not if it has been set from the `wire:model` attribute...
$showName = isset($name);
if (! isset($name)) {
    $name = $attributes->whereStartsWith('wire:model')->first();
}

$classes = Flux::classes()
    ->add('group relative inline-flex items-center outline-offset-2')
    // 36×21, from the design.
    ->add('h-[21px] w-9 min-w-9')
    ->add('rounded-full')
    ->add('transition-colors duration-150')
    // The solid off-state. No border in either appearance: the fill IS the affordance.
    ->add('bg-toggle-off border-0 [&[disabled]]:opacity-50')
    ->add('[print-color-adjust:exact]');

/*
 * The checked state has no utility class here. Every form of `data-checked:bg-*` was
 * generated, matched the element and outranked the off-state utility, and none took
 * effect. It is set by two plain rules on [data-flux-switch][data-checked] in app.css.
 */

$indicatorClasses = Flux::classes()
    ->add('size-[15px]')
    ->add('rounded-full')
    /*
     * `transition`, not `transition-transform`. Tailwind v4's translate-x-* sets the
     * standalone `translate` property rather than `transform`, so transition-transform
     * animates nothing and the knob jumps. `transition` covers both.
     */
    ->add('transition duration-150 translate-x-[3px] rtl:-translate-x-[3px]')
    // White in BOTH states - not --color-accent-foreground, which is our near-black
    // brand ink and would disappear against the accent track.
    // Checked-state travel is in app.css - see the note above the track classes.
    ->add('bg-white shadow-sm');
@endphp

<?php if ($align === 'left' || $align === 'start'): ?>
    <flux:with-inline-field :$attributes>
        <ui-switch {{ $attributes->class($classes) }} @if($showName) name="{{ $name }}" @endif @if($checked) checked data-checked @endif data-flux-control data-flux-switch>
            <span class="{{ \Illuminate\Support\Arr::toCssClasses($indicatorClasses) }}"></span>
        </ui-switch>
    </flux:with-inline-field>
<?php else: ?>
    <flux:with-reversed-inline-field :$attributes>
        <ui-switch {{ $attributes->class($classes) }} @if($showName) name="{{ $name }}" @endif @if($checked) checked data-checked @endif data-flux-control data-flux-switch>
            <span class="{{ \Illuminate\Support\Arr::toCssClasses($indicatorClasses) }}"></span>
        </ui-switch>
    </flux:with-reversed-inline-field>
<?php endif; ?>
