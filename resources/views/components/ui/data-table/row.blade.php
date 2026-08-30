@props([
    'last' => false,
])

{{--
    overflow-visible so the row-menu dropdown can escape the row's box; the row has
    nothing of its own to clip.

    `relative` anchors the artwork and creates no stacking context. See the
    positioning note in row-artwork.blade.php.
--}}
<div
    {{ $attributes->class([
        'hover:bg-row-hover relative grid items-center gap-3 overflow-visible px-4 py-2.5 transition-colors',
        'border-hairline border-b' => ! $last,
    ]) }}
    style="grid-template-columns: var(--cols)"
    role="row"
>
    {{ $slot }}
</div>
