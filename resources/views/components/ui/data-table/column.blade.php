@props([
    // Omit to render a plain, non-sortable header.
    'sort' => null,

    // The currently active sort key and direction.
    'active' => null,
    'descending' => false,

    'align' => 'start',   // start | end | center
])

@php
    $isActive = $sort !== null && $sort === $active;

    $alignment = match ($align) {
        'end' => 'justify-end text-end',
        'center' => 'justify-center text-center',
        default => 'justify-start text-start',
    };
@endphp

@if ($sort === null)
    {{-- The bag is merged on this branch as well as the sortable one. It used to be
             dropped, so a responsive class on a plain header did nothing and the header
             stayed visible while its cells disappeared. --}}
    <div
        {{ $attributes->class([$alignment, 'flex items-center gap-1 truncate']) }}
        role="columnheader"
    >
        {{ $slot }}
    </div>
@else
    {{-- A real <button>, so sorting is keyboard-reachable. aria-sort tells a screen
             reader which column orders the table, and which way. --}}
    <button
        type="button"
        role="columnheader"
        aria-sort="{{ $isActive ? ($descending ? 'descending' : 'ascending') : 'none' }}"
        {{ $attributes->class([
            $alignment,
            'hover:text-ink flex cursor-pointer items-center gap-1 truncate uppercase transition-colors',
            'text-brand-text' => $isActive,
        ]) }}
    >
        <span class="truncate">{{ $slot }}</span>

        @if ($isActive)
            {{-- The design shows a filled caret only on the active column. --}}
            <span class="shrink-0 text-[8px] leading-none" aria-hidden="true">{{ $descending ? '▼' : '▲' }}</span>
        @endif
    </button>
@endif
