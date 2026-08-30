{{--
    Light / dark / system, as three icons.

    Lives in the header rather than on the Settings screen: it is a preference you
    change while looking at whatever you wanted to look at, and it applies instantly,
    so making someone navigate away to reach it was the wrong trade.

    No server state. $flux.appearance writes to localStorage and toggles .dark on
    <html> before first paint - which is why the layouts must not hardcode class="dark".

    Icon-only, so each control carries its own accessible name; the segmented item
    renders its label as visible text when the slot is empty, so the names go through
    aria-label instead. title gives the same words to a pointer as a tooltip.
--}}
<flux:radio.group
    x-data
    variant="segmented"
    size="sm"
    x-model="$flux.appearance"
    :aria-label="__('Appearance')"
    {{ $attributes->class('shrink-0') }}
>
    <flux:radio
        value="light"
        icon="sun"
        class="px-2!"
        :aria-label="__('Light')"
        :title="__('Light')"
    />
    <flux:radio
        value="dark"
        icon="moon"
        class="px-2!"
        :aria-label="__('Dark')"
        :title="__('Dark')"
    />
    <flux:radio
        value="system"
        icon="computer-desktop"
        class="px-2!"
        :aria-label="__('System')"
        :title="__('System')"
    />
</flux:radio.group>
