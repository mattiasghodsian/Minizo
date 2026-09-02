<?php

use App\Enums\Permission;
use App\Exceptions\LibraryException;
use App\Services\Library\FileService;
use App\Services\Library\FolderService;
use App\Services\Metadata\FlacCommentReader;
use App\Support\FileTags;
use App\Support\LibraryFile;
use App\Support\LibraryFolder;
use Flux\Flux;
use Illuminate\Support\Js;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    /** The folder name from the URL. */
    public string $directory = '';

    /*
     * Filter and sort live in the query string, so a filtered view is linkable and
     * survives a refresh. #[Url] also means the browser's back button works through
     * sort changes, which it would not if this were plain component state.
     */
    #[Url(as: 'q', except: '')]
    public string $filter = '';

    #[Url(except: 'name')]
    public string $sort = 'name';

    #[Url(except: false)]
    public bool $descending = false;

    public function mount(string $directory): void
    {
        // Authorize the resolved folder, never the raw URL segment: find() returns
        // the on-disk spelling and null for anything that is not a real folder, so a
        // crafted "../" can never reach a filesystem call.
        $folder = app(FolderService::class)->find($directory);

        abort_if($folder === null, 404);
        $this->authorize('view', $folder);

        $this->directory = $folder->name;
    }

    #[Computed]
    public function folder(): LibraryFolder
    {
        return new LibraryFolder($this->directory);
    }

    #[Computed]
    public function files()
    {
        return app(FileService::class)->paginate(
            folder: $this->folder,
            filter: $this->filter,
            sort: $this->sort,
            descending: $this->descending,
            perPage: auth()->user()->paginationSize(),
        );
    }

    #[Computed]
    public function totalFiles(): int
    {
        return app(FileService::class)->count($this->folder);
    }

    /**
     * The tags shown for every row on this page, keyed by filename.
     *
     * @return array<string, FileTags>
     */
    #[Computed]
    public function tags(): array
    {
        $comments = app(FlacCommentReader::class)->fieldsFor(
            $this->files->items(),
            ['GENRE', 'MUSICBRAINZ_TRACKID', 'MUSICBRAINZ_ALBUMID'],
        );

        return array_map(FileTags::fromComments(...), $comments);
    }

    /** The tags for one row, or an empty set for a file with none. */
    public function tagsFor(LibraryFile $file): FileTags
    {
        return $this->tags[$file->filename] ?? new FileTags;
    }

    /**
     * Which rows on this page have embedded artwork, keyed by filename.
     *
     * The same block-chain walk the tags above already pay for, so it costs seeks rather
     * than a second read of every file.
     *
     * @return array<string, bool>
     */
    #[Computed]
    public function covers(): array
    {
        return app(FlacCommentReader::class)->picturesFor($this->files->items());
    }

    /** Sorting by the active column flips direction; a new column starts ascending. */
    public function sortBy(string $column): void
    {
        if (! in_array($column, FileService::SORTABLE, true)) {
            return;
        }

        if ($this->sort === $column) {
            $this->descending = ! $this->descending;
        } else {
            $this->sort = $column;
            $this->descending = false;
        }

        $this->resetPage();
    }

    /** Typing a filter must return to page 1, or a filtered result set can land on a page that no longer exists. */
    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    // ------------------------------------------------------------- file actions
    //
    // Only the filename is held in state, never a LibraryFile. Livewire serialises
    // public properties into the payload, so a value object there would make the
    // client the source of truth for a filesystem path. Re-resolved on every action.

    /** Filename targeted by the move or delete modal. */
    public string $selected = '';

    public string $moveTo = '';

    public function openMove(string $filename): void
    {
        $file = $this->file($filename);

        $this->authorize('move', [$file, $this->folder]);

        $this->selected = $file->filename;
        $this->moveTo = '';

        Flux::modal('file-move')->show();
    }

    public function openDelete(string $filename): void
    {
        $file = $this->file($filename);

        $this->authorize('delete', $file);

        $this->selected = $file->filename;

        Flux::modal('file-delete')->show();
    }

    /**
     * Folders this file can be moved into: ones the user can see, minus its own.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function moveTargets(): array
    {
        return array_values(array_filter(
            array_map(
                fn ($folder): string => $folder->name,
                app(FolderService::class)->visibleTo(auth()->user()),
            ),
            fn (string $name): bool => ! $this->folder->is($name),
        ));
    }

    public function move(FileService $files, FolderService $folders): void
    {
        $file = $this->file($this->selected);
        $destination = $folders->find($this->moveTo);

        if ($destination === null) {
            $this->addError('moveTo', __('Choose a destination folder.'));

            return;
        }

        // Both ends are in the ability signature, so access to the destination
        // cannot be forgotten.
        $this->authorize('move', [$file, $destination]);

        try {
            $files->move($file, $destination);
        } catch (LibraryException $e) {
            $this->addError('moveTo', $e->getMessage());

            return;
        }

        $this->done(__('Moved ":file" to :folder.', [
            'file' => $file->filename,
            'folder' => $destination->name,
        ]));
    }

    public function delete(FileService $files): void
    {
        $file = $this->file($this->selected);

        $this->authorize('delete', $file);

        try {
            $files->delete($file);
        } catch (LibraryException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
            $this->done();

            return;
        }

        $this->done(__('Deleted ":file".', ['file' => $file->filename]));
    }

    /** A tag write changed a file's size, and possibly its name. */
    #[On('library-updated')]
    public function refreshListing(): void
    {
        // genres and artwork too: a tag write is exactly the thing that changes them, and a
        // memo held over would show the old value on the row that was just edited.
        unset($this->files, $this->totalFiles, $this->tags, $this->covers);
    }

    /** Re-resolve a filename against the folder's real contents. */
    private function file(string $filename): \App\Support\LibraryFile
    {
        $file = app(FileService::class)->find($this->folder, $filename);

        abort_if($file === null, 404);

        return $file;
    }

    private function done(?string $message = null): void
    {
        $this->reset('selected', 'moveTo');

        Flux::modals()->close();

        // The listing changed, so drop the memos behind the computed properties.
        unset($this->files, $this->totalFiles, $this->tags, $this->covers);

        if ($message !== null) {
            Flux::toast(variant: 'success', text: $message);
        }
    }
}; ?>

{{--
    One root element, and no layout wrapper.

    Livewire applies the layout itself (livewire.component_layout = 'layouts::app'),
    and a component with more than one root element throws
    MultipleRootElementsDetectedException. Wrapping this in <x-layouts::app> emits a
    whole <html> document plus the toast @persist block, which is several roots.
--}}
<div class="space-y-5">

        {{-- Folder header ------------------------------------------------- --}}
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.tile :name="$this->folder->name" size="lg" />

            <h2 class="text-xl font-extrabold">{{ $this->folder->name }}</h2>

            <x-ui.mono variant="accent">
                {{ trans_choice(':count file|:count files', $this->totalFiles, ['count' => $this->totalFiles]) }}
            </x-ui.mono>

            <div class="ms-auto flex items-center gap-2">
                {{-- Rendered on granted(), dimmed on the instance switch: the design's
                                     three-state rule. @can consults the policy, which uses
                                     effective(), so the button would vanish rather than dim. --}}
                @php $sharePermissions = auth()->user()->permissions(); @endphp

                @if ($sharePermissions->granted(Permission::Share) && auth()->user()->folderAccess()->allows($this->folder->name))
                    <flux:button
                        size="sm"
                        variant="outline"
                        icon="link"
                        @class(['pointer-events-none opacity-35' => $sharePermissions->dimmed(Permission::Share)])
                        :title="$sharePermissions->dimmed(Permission::Share) ? __('Public sharing is disabled on this instance.') : null"
                        x-on:click="Livewire.dispatch('share-folder', { folder: @js($this->folder->name) })"
                    >{{ __('Share folder') }}</flux:button>
                @endif

                @can('rename', $this->folder)
                    <flux:button
                        size="sm"
                        variant="ghost"
                        icon="pencil-square"
                        x-on:click="Livewire.dispatch('folder-rename', { folder: @js($this->folder->name) })"
                    >{{ __('Rename') }}</flux:button>
                @endcan

                @can('delete', $this->folder)
                    <flux:button
                        size="sm"
                        variant="ghost"
                        icon="trash"
                        class="text-danger!"
                        x-on:click="Livewire.dispatch('folder-delete', { folder: @js($this->folder->name) })"
                    >{{ __('Delete folder') }}</flux:button>
                @endcan

                {{-- The kbd badge is decorative in Flux - it renders the key and nothing
                     more - so the binding that makes it true lives here. Ignored while a
                     field already has focus, or it would swallow every slash typed. --}}
                <flux:input
                    wire:model.live.debounce.300ms="filter"
                    :placeholder="__('Filter files…')"
                    class="w-60!"
                    kbd="/"
                    x-data
                    x-on:keydown.window="
                        if ($event.key !== '/') return;
                        let el = $event.target;
                        if (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName)) return;
                        $event.preventDefault();
                        $el.querySelector('input')?.focus();
                    "
                />
            </div>
        </div>

        {{-- File table ---------------------------------------------------- --}}
        {{-- Genre is desktop-only. `cols` is the narrow layout; the lg: variant
                    overrides the same custom property to insert the extra column. Header cell
                    and row cell both carry `hidden lg:flex`, or every cell after the column
                    shifts one place.
        
                    The trailing `!` is required: x-ui.data-table writes the base value as
                    an inline style, which outranks any class, so the override needs it to
                    land. Without it six cells lay themselves into five columns. --}}
        {{-- Genre and Modified both drop below lg. Modified goes because it is the
                    widest fixed column at 150px: at 900px the fixed columns and gaps left
                    File just 84px, of which the artwork inset claims 80, so the filename was
                    invisible. The artwork and its inset shrink to match, holding the ratio at
                    both sizes. --}}
        <x-ui.data-table
            cols="1fr 44px 80px 90px 44px"
            class="lg:[--cols:1fr_44px_130px_80px_90px_150px_44px]!"
        >
            <x-ui.data-table.head>
                {{-- Matches the ps-20 on the rows' first cell, the clear zone the bled-in
                                     cover art occupies. --}}
                <x-ui.data-table.column
                    sort="name"
                    :active="$sort"
                    :descending="$descending"
                    class="ps-20 max-lg:ps-16"
                    wire:click="sortBy('name')"
                >{{ __('File') }}</x-ui.data-table.column>

                {{-- Kept at every width, unlike Genre: it is 44px, and "which of these
                                     still needs tagging" is a question you scan a narrow screen for
                                     too. --}}
                <x-ui.data-table.column
                    align="center"
                    :title="__('Whether the file carries MusicBrainz ids')"
                >{{ __('MB') }}</x-ui.data-table.column>

                {{-- Not sortable: FileService sorts on facts it has from the directory
                                     listing, and ordering by genre would mean reading every file in
                                     the folder rather than the page. --}}
                <x-ui.data-table.column class="max-lg:hidden">
                    {{ __('Genre') }}
                </x-ui.data-table.column>

                <x-ui.data-table.column
                    sort="format"
                    :active="$sort"
                    :descending="$descending"
                    wire:click="sortBy('format')"
                >{{ __('Format') }}</x-ui.data-table.column>

                <x-ui.data-table.column
                    sort="size"
                    :active="$sort"
                    :descending="$descending"
                    align="end"
                    wire:click="sortBy('size')"
                >{{ __('Size') }}</x-ui.data-table.column>

                <x-ui.data-table.column
                    sort="modified"
                    :active="$sort"
                    :descending="$descending"
                    class="max-lg:hidden"
                    wire:click="sortBy('modified')"
                >{{ __('Modified') }}</x-ui.data-table.column>

                <x-ui.data-table.column align="end">
                    <span class="sr-only">{{ __('Actions') }}</span>
                </x-ui.data-table.column>
            </x-ui.data-table.head>

            @forelse ($this->files as $file)
                <x-ui.data-table.row :last="$loop->last">
                    {{-- The cover URL is emitted only for a file that really has artwork.
                                            $this->covers answers that from the metadata block chain, which
                                            the tag read above already walks, so it costs no extra pass over
                                            the file - and a folder of untagged tracks stops firing one cover
                                            request per row just to collect a 404 for each.

                                            Still speculative rather than certain: the picture can go away
                                            between this render and the fetch, so the <img> keeps its onerror
                                            and the gradient behind it stays.

                                            No rounding of its own: the table wrapper clips, so the last
                                            row's artwork follows the card's radius. --}}
                    <x-ui.row-artwork
                        :name="$file->basename()"
                        :cover="($this->covers[$file->filename] ?? false) ? route('files.cover', [$this->folder->name, $file->filename]) : null"
                        class="max-lg:w-24"
                    />

                    @php($tags = $this->tagsFor($file))
                    @php($mb = $tags->musicBrainz())

                    {{-- ps-20 is the clear zone the cover artwork occupies; the column header
                                             carries the same inset. relative keeps the filename above it. --}}
                    <x-ui.data-table.cell class="relative ps-20 max-lg:ps-16">
                        <span class="truncate font-semibold" title="{{ $file->filename }}">
                            {{ $file->basename() }}
                        </span>
                    </x-ui.data-table.cell>

                    <x-ui.data-table.cell align="center" :title="$mb->label()">
                        <span class="{{ $mb->tone() }} text-sm leading-none" aria-hidden="true">{{ $mb->glyph() }}</span>
                        <span class="sr-only">{{ $mb->label() }}</span>
                    </x-ui.data-table.cell>

                    <x-ui.data-table.cell class="max-lg:hidden">
                        @if ($tags->genre())
                            {{-- Too narrow for a list, so it shows the first and counts the
                                                             rest. The title carries all of them. --}}
                            <x-ui.mono variant="chip" :title="$tags->genreList()">{{ $tags->genre() }}</x-ui.mono>

                            @if ($tags->extraGenreCount() > 0)
                                <x-ui.mono class="text-ink-faint! shrink-0" :title="$tags->genreList()">
                                    +{{ $tags->extraGenreCount() }}
                                </x-ui.mono>
                            @endif
                        @else
                            <x-ui.mono class="text-ink-faint!">—</x-ui.mono>
                        @endif
                    </x-ui.data-table.cell>

                    <x-ui.data-table.cell>
                        <x-ui.mono variant="chip">{{ $file->formatLabel() }}</x-ui.mono>
                    </x-ui.data-table.cell>

                    <x-ui.data-table.cell align="end">
                        <x-ui.mono>{{ $file->sizeLabel() }}</x-ui.mono>
                    </x-ui.data-table.cell>

                    <x-ui.data-table.cell class="max-lg:hidden">
                        <x-ui.mono>{{ $file->modifiedAt?->format('Y-m-d H:i') ?? '—' }}</x-ui.mono>
                    </x-ui.data-table.cell>

                    <x-ui.data-table.cell align="end" :truncate="false">
                        <x-ui.row-menu>
                            {{-- Metadata is offered only for a format we can write. An mp3 is
                                                             listed and moved like anything else, but there is no
                                                             tag writer for it. --}}
                            {{-- Js::from(), NOT @js(). A directive written in the attribute
                                                            of a component that forwards its whole attribute bag
                                                            into another one is never compiled: it reaches the
                                                            browser as literal text and Livewire throws on click.
                                                            A {{ }} echo is compiled in every position. Same
                                                            applies to every wire:click in this menu. --}}
                            {{-- Read-only, so no permission beyond seeing the folder: a file's
                                                             own tags reveal nothing the listing does not already
                                                             show. First in the menu, because looking is what you
                                                             do before deciding to change anything. --}}
                            <x-ui.row-menu.item
                                icon="eye"
                                :unavailable="! $file->isTaggable()"
                                :unavailable-reason="__('Only FLAC files carry tags Minizo can read.')"
                                wire:click="$dispatch('metadata-view', { folder: {{ Js::from($this->folder->name) }}, filename: {{ Js::from($file->filename) }} })"
                            >{{ __('View metadata') }}</x-ui.row-menu.item>

                            <x-ui.row-menu.item
                                icon="pencil-square"
                                :permission="Permission::Edit"
                                :unavailable="! $file->isTaggable()"
                                :unavailable-reason="__('Metadata can only be written to FLAC files.')"
                                wire:click="$dispatch('metadata-edit', { folder: {{ Js::from($this->folder->name) }}, filename: {{ Js::from($file->filename) }} })"
                            >{{ __('Edit metadata') }}</x-ui.row-menu.item>

                            <x-ui.row-menu.item
                                icon="arrows-right-left"
                                :permission="Permission::Move"
                                wire:click="openMove({{ Js::from($file->filename) }})"
                            >{{ __('Move') }}</x-ui.row-menu.item>

                            <x-ui.row-menu.item
                                icon="arrow-down-tray"
                                :permission="Permission::Download"
                            >{{ __('Download') }}</x-ui.row-menu.item>

                            <x-ui.row-menu.item
                                icon="link"
                                :permission="Permission::Share"
                                wire:click="$dispatch('share-file', { folder: {{ Js::from($this->folder->name) }}, filename: {{ Js::from($file->filename) }} })"
                            >{{ __('Share') }}</x-ui.row-menu.item>

                            {{-- Not an API call: this opens YouTube Music in a new tab, which
                                                             is all it has ever been. --}}
                            <x-ui.row-menu.item
                                icon="magnifying-glass"
                                href="https://music.youtube.com/search?q={{ urlencode($file->basename()) }}"
                                target="_blank"
                            >{{ __('Search on YouTube Music') }}</x-ui.row-menu.item>

                            {{-- The ids this file already carries, opened on MusicBrainz.
                            
                                                            Shown as unavailable rather than hidden when there is
                                                            no id: "this track was never identified" is what the
                                                            menu is being opened to find out. Plain links, so they
                                                            are middle-clickable and copyable. --}}
                            <x-ui.row-menu.item
                                icon="identification"
                                :href="$tags->musicBrainzTrackId ? 'https://musicbrainz.org/recording/'.$tags->musicBrainzTrackId : null"
                                target="_blank"
                                :unavailable="! $tags->musicBrainzTrackId"
                                :unavailable-reason="$file->isTaggable()
                                    ? __('This file has no MusicBrainz recording id yet — tag it first.')
                                    : __('Only FLAC files carry tags Minizo can read.')"
                            >{{ __('Open recording on MusicBrainz') }}</x-ui.row-menu.item>

                            <x-ui.row-menu.item
                                icon="rectangle-stack"
                                :href="$tags->musicBrainzAlbumId ? 'https://musicbrainz.org/release/'.$tags->musicBrainzAlbumId : null"
                                target="_blank"
                                :unavailable="! $tags->musicBrainzAlbumId"
                                :unavailable-reason="$file->isTaggable()
                                    ? __('This file has no MusicBrainz release id — a standalone recording has none.')
                                    : __('Only FLAC files carry tags Minizo can read.')"
                            >{{ __('Open release on MusicBrainz') }}</x-ui.row-menu.item>

                            <flux:menu.separator />

                            <x-ui.row-menu.item
                                icon="trash"
                                tone="danger"
                                :permission="Permission::Delete"
                                wire:click="openDelete({{ Js::from($file->filename) }})"
                            >{{ __('Delete') }}</x-ui.row-menu.item>
                        </x-ui.row-menu>
                    </x-ui.data-table.cell>
                </x-ui.data-table.row>
            @empty
                <x-ui.empty-state
                    :message="filled($filter)
                        ? __('No files match “:filter”.', ['filter' => $filter])
                        : __('This folder is empty.')"
                />
            @endforelse

            @if ($this->files->total() > 0)
                <x-ui.pagination :paginator="$this->files" />
            @endif
        </x-ui.data-table>

    {{-- Move file ----------------------------------------------------------- --}}
    <x-ui.modal-shell name="file-move" :title="__('Move track')" :width="460">
        <form wire:submit="move" class="space-y-4">
            <p class="text-ink-muted truncate text-xs">{{ $selected }}</p>

            <flux:select wire:model="moveTo" :label="__('Destination folder')">
                <flux:select.option value="">{{ __('Choose a folder…') }}</flux:select.option>

                @foreach ($this->moveTargets as $target)
                    <flux:select.option :value="$target">{{ $target }}</flux:select.option>
                @endforeach
            </flux:select>

            @error('moveTo')
                <p class="text-danger text-xs">{{ $message }}</p>
            @enderror

            <x-ui.modal-footer>
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button
                    variant="primary"
                    type="submit"
                    :disabled="blank($moveTo)"
                    class="disabled:pointer-events-none disabled:opacity-40"
                >{{ __('Move track') }}</flux:button>
            </x-ui.modal-footer>
        </form>
    </x-ui.modal-shell>

    {{-- Delete file --------------------------------------------------------- --}}
    <x-ui.confirm-modal
        name="file-delete"
        :title="__('Delete file')"
        :body="__('“:file” is deleted from disk. This cannot be undone.', ['file' => $selected ?: '…'])"
        variant="destructive"
        :confirm-label="__('Delete Permanently')"
        confirm="delete"
    />

    {{-- Edit metadata. Its own component because it is a three-step wizard with
            several collections in flight. Opens on the `metadata-edit` event from the row
            menu, and dispatches `library-updated` back when a write lands. --}}
    {{-- View metadata. Its own component rather than a mode of the editor: it
            reports what is on disk, including stream facts MusicBrainz has no opinion
            about, where the editor proposes what to write. Its "Edit metadata" button
            dispatches straight to the editor. --}}
    <livewire:pages::files.metadata-viewer />

    <livewire:pages::files.metadata-editor />

    {{-- Share. Handles both a folder and a track, opened by the `share-folder` /
            `share-file` events above. One component, because the only difference between
            the flows is what the modal names. --}}
    <livewire:pages::files.share-modal />
</div>
