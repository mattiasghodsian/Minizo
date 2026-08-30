@props([
    'value' => '',
    'label' => null,
    'copiedLabel' => null,

    // bare    - text + icon only (the Share modal, beside the URL field)
    // outline - bordered, to sit in a row of buttons (the Share links table, where the
    //           design has Copy matching Open and Expire now)
    'variant' => 'bare',
])

@php
    $label ??= __('Copy');
    $copiedLabel ??= __('Copied');

    $variants = [
        'bare' => 'text-ink-muted hover:text-ink',
        'outline' => 'border-border-strong text-ink-muted hover:text-ink hover:border-line/40 rounded-[7px] border px-2.5 py-[5px] font-bold',
    ];
@endphp

{{--
    Alpine-only, with no Livewire roundtrip: the label flips to "Copied" instantly.

    navigator.clipboard needs a secure context. localhost qualifies, a LAN deployment
    over plain HTTP does not, so the catch branch selects the text instead.
--}}
<button
    type="button"
    x-data="{
        copied: false,
        timer: null,
        async copy() {
            try {
                await navigator.clipboard.writeText(@js($value));
                this.flash();
            } catch (e) {
                this.$dispatch('copy-unavailable', { value: @js($value) });
            }
        },
        flash() {
            this.copied = true;
            clearTimeout(this.timer);
            this.timer = setTimeout(() => (this.copied = false), 2000);
        },
    }"
    x-on:click="copy()"
    {{ $attributes->class([
        'inline-flex items-center gap-1.5 text-xs font-semibold transition-colors',
        $variants[$variant] ?? $variants['bare'],
    ]) }}
>
    <flux:icon.clipboard-document class="size-3.5" x-show="! copied" />
    <flux:icon.check class="text-success size-3.5" x-show="copied" x-cloak />

    <span x-text="copied ? @js($copiedLabel) : @js($label)">{{ $label }}</span>
</button>
