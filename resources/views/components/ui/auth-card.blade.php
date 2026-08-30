@props([
    'title' => null,
    'description' => null,
])

{{--
    The card every auth screen sits in.

    The design handoff starts at the signed-in shell and covers no auth screens, so
    this borrows the section-card and modal language rather than matching a spec.
    Width is 400px, under the smallest designed modal at 440px.
--}}
<div {{ $attributes->class('bg-surface border-border w-full max-w-[400px] rounded-3xl border p-6.5 shadow-modal') }}>
    @if ($title)
        <x-auth-header :title="$title" :description="$description" class="mb-6" />
    @endif

    {{ $slot }}
</div>
