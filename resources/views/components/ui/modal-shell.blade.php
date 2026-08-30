@props([
    'name',
    'title' => null,
    'subtitle' => null,

    // The design's widths: 740 (metadata), 560 (manage user), 480 (share),
    // 460 (move), 440 (delete / folder form).
    'width' => 440,

    'tone' => 'default',   // default | danger
])

@php
    // Inline style, not a class: "w-[{$width}px]" is assembled in PHP and Tailwind
    // only sees literal source text, so every modal would fall back to max-w-xl.
    $style = "width: {$width}px; max-width: 100%;";

    $borderClass = $tone === 'danger' ? 'border-danger-line!' : 'border-border!';
    $titleClass = $tone === 'danger' ? 'text-danger' : 'text-ink';
@endphp

{{-- Wraps flux:modal with the design's chrome: radius 16, 26px padding, the
     surface colour and a per-tone border. The scrim is set globally in app.css,
     since Flux ships a much lighter one. --}}
<flux:modal
    :name="$name"
    {{ $attributes->class(['bg-surface! shadow-modal! rounded-3xl! border! p-6.5!', $borderClass]) }}
    :style="$style"
>
    @if ($title)
        <div class="mb-5">
            <flux:heading class="{{ $titleClass }} text-lg! font-extrabold!">{{ $title }}</flux:heading>

            @if ($subtitle)
                <p class="text-ink-muted mt-1 text-xs">{{ $subtitle }}</p>
            @endif
        </div>
    @endif

    {{ $slot }}
</flux:modal>
