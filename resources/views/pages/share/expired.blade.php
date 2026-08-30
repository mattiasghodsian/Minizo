{{--
    The dead-link state: expired, revoked, or a token that never existed.

    All three render this same page. Distinguishing them would tell a stranger probing
    for tokens which ones were once valid, which is the one thing guessing could
    teach them. So it says only that the link no longer works, plus what to do about
    it, which is the only actionable thing on the page.
--}}
<x-layouts::public :title="__('Link unavailable')">
    <div class="flex flex-col items-center gap-5 py-16 text-center">
        <div class="border-border bg-surface flex size-16 items-center justify-center rounded-3xl border">
            <flux:icon name="link-slash" class="text-ink-faint size-7" />
        </div>

        <div class="flex flex-col gap-2.5">
            <h1 class="text-2xl font-extrabold tracking-[-.3px]">
                {{ __('This link no longer works') }}
            </h1>

            <p class="text-ink-muted max-w-md text-sm leading-relaxed">
                {{ __('Share links from a Minizo library expire on their own, and can be revoked at any time by whoever created them.') }}
            </p>
        </div>

        <p class="text-ink-faint text-xs leading-relaxed">
            {{ __('Ask them for a fresh link if you still need the files.') }}
        </p>
    </div>

    <p class="text-ink-faint/80 mt-4 text-center text-xs leading-relaxed">
        {{ __('Shared from a Minizo library.') }}
    </p>
</x-layouts::public>
