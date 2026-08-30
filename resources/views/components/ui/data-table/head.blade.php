{{-- Column headers: 10px/800 uppercase with 1px tracking, on a hairline. --}}
<div
    {{ $attributes->class('border-hairline text-ink-faint grid items-center gap-3 border-b px-4 py-2.5 text-3xs font-extrabold tracking-[1px] uppercase') }}
    style="grid-template-columns: var(--cols)"
    role="row"
>
    {{ $slot }}
</div>
