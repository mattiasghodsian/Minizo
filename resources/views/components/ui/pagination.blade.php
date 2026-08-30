@props([
    'paginator',

    // Livewire method invoked with the page number. Falls back to links when the
    // component is rendered outside Livewire.
    'wireMethod' => 'gotoPage',
])

@php
    /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator */
    $current = $paginator->currentPage();
    $last = $paginator->lastPage();

    // A window of pages around the current one, always including the first and
    // last. Avoids rendering 40 buttons for a 40-page folder.
    $window = 1;
    $pages = collect(range(1, $last))
        ->filter(fn (int $page): bool => $page === 1
            || $page === $last
            || abs($page - $current) <= $window)
        ->values()
        ->all();
@endphp

{{-- Hand-rolled rather than flux:pagination, whose boxed button-group markup and
     zinc palette are a different design, and which has no "Showing x to y of z"
     line. --}}
<div {{ $attributes->class('border-hairline flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3') }}>
    <x-ui.mono>
        {{ __('Showing :from to :to of :total results', [
            'from' => $paginator->total() === 0 ? 0 : $paginator->firstItem(),
            'to' => $paginator->lastItem() ?? 0,
            'total' => $paginator->total(),
        ]) }}
    </x-ui.mono>

    @if ($last > 1)
        <div class="flex items-center gap-1">
            <x-ui.icon-button
                icon="chevron-left"
                size="sm"
                :label="__('Previous page')"
                :disabled="$paginator->onFirstPage()"
                class="disabled:pointer-events-none disabled:opacity-35"
                wire:click="previousPage"
            />

            @php $previous = 0; @endphp

            @foreach ($pages as $page)
                @if ($previous && $page - $previous > 1)
                    {{-- A gap in the window, e.g. 1 … 4 5 6 … 20 --}}
                    <span class="text-ink-faint px-1 text-2xs" aria-hidden="true">&hellip;</span>
                @endif

                <button
                    type="button"
                    wire:click="{{ $wireMethod }}({{ $page }})"
                    @if ($page === $current) aria-current="page" @endif
                    class="{{ $page === $current
                        ? 'bg-brand text-brand-ink'
                        : 'text-ink-muted hover:bg-row-hover-strong hover:text-ink' }} min-w-7 cursor-pointer rounded-md px-2 py-1 font-mono text-2xs font-semibold tabular-nums transition-colors"
                >{{ $page }}</button>

                @php $previous = $page; @endphp
            @endforeach

            <x-ui.icon-button
                icon="chevron-right"
                size="sm"
                :label="__('Next page')"
                :disabled="! $paginator->hasMorePages()"
                class="disabled:pointer-events-none disabled:opacity-35"
                wire:click="nextPage"
            />
        </div>
    @endif
</div>
