<?php

use App\Services\Library\FileService;
use App\Services\Library\FolderService;
use App\Support\LibraryFile;
use App\Support\PaletteDestination;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The Ctrl+K palette: go anywhere, or find a track in any folder.
 *
 * Mounted in the layout rather than on a page, so it is on every screen - the same shape
 * as pages::folders.manager.
 *
 * It opens on Flux's own `modal-show` DOM event rather than through a Livewire action.
 * Going via the server cost a round trip before anything appeared, and the re-render that
 * followed morphed the input and took focus back off it - so the dialog now opens, and
 * the caret lands, without touching the network.
 */
new class extends Component
{
    /** Shortest query that searches the library. */
    private const MIN_QUERY = 2;

    /** Songs shown at once. A palette is for finding one thing, not for browsing. */
    private const SONG_LIMIT = 8;

    /**
     * The search text.
     *
     * Deliberately not cleared when the palette reopens: the last search is still on
     * screen, and the input selects itself on open so typing replaces it. That is one
     * keystroke to search again and zero to repeat the last one.
     */
    public string $query = '';

    /**
     * The screens this user may reach.
     *
     * @return array<int, PaletteDestination>
     */
    #[Computed]
    public function destinations(): array
    {
        return array_values(array_filter(
            PaletteDestination::forUser(auth()->user()),
            fn (PaletteDestination $destination): bool => $destination->matches($this->query),
        ));
    }

    /**
     * Folders this user may see, matching the query.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function folders(): array
    {
        $query = mb_strtolower(trim($this->query));

        return array_values(array_filter(
            array_map(
                fn ($folder): string => $folder->name,
                app(FolderService::class)->visibleTo(auth()->user()),
            ),
            fn (string $name): bool => $query === '' || str_contains(mb_strtolower($name), $query),
        ));
    }

    /**
     * Tracks whose name matches, across every folder this user can see.
     *
     * Matched on basename(), not filename(). The Files screen's own filter matches the
     * full name including the extension, which is right for a per-folder box and wrong
     * here - "flac" would return the entire library. basename is strictly narrower, so
     * everything listed here still appears when the deep link applies ?q= on arrival.
     *
     * Filenames carry artist and title already: both the downloader's output template and
     * the metadata editor's rename produce "Artist - Title.ext". That is what makes a
     * filename search worth having without a tag index.
     *
     * @return array<int, LibraryFile>
     */
    #[Computed]
    public function songs(): array
    {
        $query = mb_strtolower(trim($this->query));

        // One character across every folder is noise, not a search.
        if (mb_strlen($query) < self::MIN_QUERY) {
            return [];
        }

        $files = app(FileService::class);
        $matches = [];

        foreach (app(FolderService::class)->visibleTo(auth()->user()) as $folder) {
            foreach ($files->all($folder) as $file) {
                $name = mb_strtolower($file->basename());
                $position = mb_strpos($name, $query);

                if ($position === false) {
                    continue;
                }

                // Sorted on the match position, so "emi" puts "Emilia" above "Bad Bunny,
                // Emilia". Ties keep the listing's natural order.
                $matches[] = ['position' => $position, 'file' => $file];
            }
        }

        usort($matches, fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return array_map(
            fn (array $match): LibraryFile => $match['file'],
            array_slice($matches, 0, self::SONG_LIMIT),
        );
    }

    /**
     * Every result in one flat list, which is what the arrow keys move through.
     *
     * @return array<int, array{kind: string, label: string, sublabel: ?string, url: string}>
     */
    #[Computed]
    public function results(): array
    {
        $results = [];

        foreach ($this->destinations as $destination) {
            $results[] = [
                'kind' => 'destination',
                'label' => $destination->label,
                'sublabel' => null,
                'url' => $destination->url(),
            ];
        }

        foreach ($this->folders as $name) {
            $results[] = [
                'kind' => 'folder',
                'label' => $name,
                'sublabel' => null,
                'url' => route('files', $name),
            ];
        }

        foreach ($this->songs as $file) {
            $results[] = [
                'kind' => 'song',
                'label' => $file->basename(),
                'sublabel' => $file->folder->name,

                // The Files screen's filter is #[Url(as: 'q')], so the row arrives on
                // screen already isolated, with its row menu one keystroke away.
                'url' => route('files', ['directory' => $file->folder->name, 'q' => $file->basename()]),
            ];
        }

        return $results;
    }

    /**
     * Follow one result.
     *
     * The index comes from the client, because the highlight is Alpine state - so it is
     * bounds-checked rather than trusted, and every URL it can produce was built here from
     * a folder this user was already allowed to see.
     */
    public function go(int $index = 0): void
    {
        $target = $this->results[$index] ?? null;

        if ($target === null) {
            return;
        }

        Flux::modals()->close();

        $this->redirect($target['url'], navigate: true);
    }
}; ?>

<div>
    <flux:modal name="palette" variant="bare" class="w-full max-w-xl">
        {{--
            The highlight lives in Alpine, not in a Livewire property.

            Assigning to $wire.<prop> sends an update, so mirroring it would fire a request
            per arrow press - and holding the key down would fire a stream of them. Only
            Enter crosses to the server, carrying the index as an argument.

            The count is read from the DOM rather than baked in at render, because Livewire
            patches the list in place when the query changes and does not re-run x-data.
        --}}
        <div
            class="bg-surface border-border shadow-modal overflow-hidden rounded-2xl border"
            x-data="{
                index: 0,
                options() {
                    return Array.from($el.querySelectorAll('[role=option]'));
                },
                move(step) {
                    let count = this.options().length;
                    if (count === 0) return;
                    this.index = (this.index + step + count) % count;
                    this.paint();
                },
                paint() {
                    this.options().forEach((option, i) => {
                        let on = i === this.index;
                        option.dataset.selected = on ? 'true' : 'false';
                        option.setAttribute('aria-selected', on ? 'true' : 'false');
                        option.classList.toggle('bg-row-hover', on);
                        if (on) option.scrollIntoView({ block: 'nearest' });
                    });
                },
            }"
            x-on:keydown.down.prevent="move(1)"
            x-on:keydown.up.prevent="move(-1)"
            x-on:keydown.enter.prevent="$wire.go(index)"
            {{--
                Focus on OPEN, not on init.

                This component is mounted in the layout, so x-init runs once at page load,
                while the dialog is still closed - focusing there does nothing and never
                happens again. Flux opens on a document `modal-show` event, so that is the
                moment to take the caret.

                Its own listener runs first (the dialog is an ancestor and initialises
                earlier), so showModal() has already happened by the time this fires.
            --}}
            x-on:modal-show.document="
                if ($event.detail?.name !== 'palette') return;
                index = 0;
                $nextTick(() => {
                    $refs.search?.focus();
                    $refs.search?.select();
                });
            "
        >
            <div class="border-border flex items-center gap-2.5 border-b px-4 py-3">
                <flux:icon.magnifying-glass class="text-ink-faint size-4 shrink-0" />

                {{-- The house idiom for focus, rather than introducing $focus as a new pattern. --}}
                {{-- Plain attributes, not :bound ones. On a raw HTML element Blade leaves
                     a leading colon alone and Alpine claims it as x-bind, which would try
                     to evaluate __('…') as JavaScript. --}}
                <input
                    type="text"
                    wire:model.live.debounce.200ms="query"
                    x-ref="search"
                    autofocus
                    {{-- Alpine's index survives a Livewire patch, but the list under it does
                         not, so typing puts the highlight back on the first result. --}}
                    x-on:input="index = 0"
                    placeholder="{{ __('Search tracks, folders and screens…') }}"
                    aria-label="{{ __('Search') }}"
                    class="text-ink placeholder:text-ink-faint w-full border-0 bg-transparent p-0 text-sm focus:outline-none"
                    autocomplete="off"
                    spellcheck="false"
                />

                <x-ui.mono variant="chip" class="shrink-0">esc</x-ui.mono>
            </div>

            <div class="max-h-[380px] overflow-y-auto py-1.5" role="listbox" aria-label="{{ __('Results') }}">
                @forelse ($this->results as $index => $result)
                    {{-- One click handler, in Alpine: it sets the highlight and follows it in
                         the same gesture, so a mouse click and Enter take the same path. --}}
                    <button
                        type="button"
                        wire:key="palette-{{ $result['kind'] }}-{{ $index }}"
                        x-on:mouseenter="index = {{ $index }}; paint()"
                        x-on:click="$wire.go({{ $index }})"
                        role="option"
                        aria-selected="{{ $index === 0 ? 'true' : 'false' }}"
                        data-selected="{{ $index === 0 ? 'true' : 'false' }}"
                        @class([
                            'flex w-full items-center gap-3 px-4 py-2 text-left',
                            'bg-row-hover' => $index === 0,
                        ])
                    >
                        @if ($result['kind'] === 'song')
                            <x-ui.tile :name="$result['label']" size="sm" class="shrink-0" />
                        @else
                            <span class="bg-line/10 flex size-6 shrink-0 items-center justify-center rounded-md">
                                <flux:icon
                                    :icon="$result['kind'] === 'folder' ? 'folder' : 'arrow-right'"
                                    variant="micro"
                                    class="text-ink-muted size-3.5"
                                />
                            </span>
                        @endif

                        <span class="min-w-0 flex-1 truncate text-xs font-semibold">{{ $result['label'] }}</span>

                        @if ($result['sublabel'])
                            <x-ui.mono variant="chip" class="shrink-0">{{ $result['sublabel'] }}</x-ui.mono>
                        @endif
                    </button>
                @empty
                    {{-- Computed before the tag, not inside it. Blade's component-tag parser
                         is regex-based, and a ternary in attribute position breaks it on the
                         colon. Same rule as x-ui.row-menu.item documents. --}}
                    @php
                        $emptyMessage = filled($this->query)
                            ? __('Nothing matches ":query".', ['query' => $this->query])
                            : __('Type to search your library.');
                    @endphp

                    <x-ui.empty-state icon="magnifying-glass" :message="$emptyMessage" />
                @endforelse
            </div>
        </div>
    </flux:modal>
</div>
