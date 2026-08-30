<?php

use App\Enums\ReleaseType;
use App\Exceptions\LibraryException;
use App\Exceptions\MetadataException;
use App\Services\Library\FileService;
use App\Services\Metadata\MetadataWriter;
use App\Services\MusicBrainz\MetadataLookup;
use App\Support\LibraryFile;
use App\Support\Mbid;
use App\Support\LibraryFolder;
use App\Support\ReleaseCandidate;
use App\Support\TrackCandidate;
use App\Support\TrackMetadata;
use App\Support\TrackTitleNormaliser;
use Flux\Flux;
use Illuminate\Support\Js;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/** The Edit-metadata modal: search MusicBrainz, pick a track, write the tags. */
new class extends Component
{
    /** The file being tagged, as folder name + filename. Re-resolved on every action. */
    public string $folder = '';

    public string $filename = '';

    /** 1 = search · 2 = pick a track · 3 = review and write. */
    public int $step = 1;

    // ------------------------------------------------------------------- step 1

    public string $artist = '';

    public string $title = '';

    /** Rename to "Artist - Title.flac" after writing. */
    public bool $rename = false;

    /** Skip the search: the value in the release-ID field is a MusicBrainz MBID to load directly. */
    public bool $forceReleaseId = false;

    public string $releaseId = '';

    public bool $searched = false;

    /** @var array<int, array<string, mixed>> */
    public array $candidates = [];

    // ------------------------------------------------------------------- step 2

    /** @var array<int, array<string, mixed>> */
    public array $tracks = [];

    /** @var array<string, mixed> */
    public array $picked = [];

    // ------------------------------------------------------------------- step 3

    /** @var array<string, mixed> */
    public array $metadata = [];

    /**
     * User corrections, keyed by field. Only the four fields a human is likely to want to fix; everything else is machine data from MusicBrainz and hand-editing an ISRC or a barcode only introduces errors.
     *
     * @var array<string, string>
     */
    public array $overrides = [];

    // ------------------------------------------------------------------ lifecycle

    #[On('metadata-edit')]
    public function open(string $folder, string $filename): void
    {
        $this->reset();

        $file = $this->resolve($folder, $filename);

        $this->authorize('editMetadata', $file);

        if (! $file->isTaggable()) {
            Flux::toast(variant: 'danger', text: MetadataException::notTaggable($file->filename)->getMessage());

            return;
        }

        $this->folder = $file->folder->name;
        $this->filename = $file->filename;

        // Pre-fill from the filename, which is how the download named it:
        // "Artist - Title.flac". The normaliser strips the YouTube furniture.
        $parsed = TrackTitleNormaliser::fromFilename($file->basename());

        $this->artist = $parsed['artist'];
        $this->title = $parsed['title'];

        Flux::modal('metadata-edit')->show();
    }

    // ------------------------------------------------------------------- step 1

    public function search(MetadataLookup $lookup): void
    {
        $file = $this->file();

        $this->authorize('editMetadata', $file);

        if ($this->forceReleaseId) {
            $this->loadRelease($lookup, trim($this->releaseId));

            return;
        }

        try {
            $candidates = $lookup->candidates(auth()->user(), $this->artist, $this->title);
        } catch (MetadataException $e) {
            $this->addError('title', $e->getMessage());

            return;
        }

        $this->candidates = array_map(
            fn (ReleaseCandidate $candidate): array => $candidate->toArray(),
            $candidates,
        );

        $this->searched = true;

        unset($this->results);
    }

    /**
     * @return array<int, ReleaseCandidate>
     */
    #[Computed]
    public function results(): array
    {
        return array_map(
            fn (array $row): ReleaseCandidate => ReleaseCandidate::fromArray($row),
            $this->candidates,
        );
    }

    /** Pick a candidate from step 1. */
    public function pick(MetadataLookup $lookup, string $id, string $type): void
    {
        $this->authorize('editMetadata', $this->file());

        // Both $id and $type arrive over the wire, so the id is checked here rather than
        // only on the release branch. It is concatenated into a request path and a cache
        // key downstream; the host is pinned either way, but neither should take a string
        // that is not an MBID.
        if (! Mbid::isValid($id)) {
            return;
        }

        if (ReleaseType::tryFrom($type) === ReleaseType::Standalone) {
            $this->loadStandalone($lookup, $id);

            return;
        }

        $this->loadRelease($lookup, $id);
    }

    // ------------------------------------------------------------------- step 2

    /**
     * @return array<int, TrackCandidate>
     */
    #[Computed]
    public function trackRows(): array
    {
        return array_map(
            fn (array $row): TrackCandidate => TrackCandidate::fromArray($row),
            $this->tracks,
        );
    }

    public function pickTrack(MetadataLookup $lookup, int $mediaPosition, int $trackIndex): void
    {
        $this->authorize('editMetadata', $this->file());

        // $releaseId is a public property, so it is whatever the client last sent.
        if (! Mbid::isValid($this->releaseId)) {
            return;
        }

        try {
            $metadata = $lookup->trackMetadata($this->releaseId, $mediaPosition, $trackIndex);
        } catch (MetadataException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->metadata = $metadata->toArray();

        $this->overrides = [];
        // Genre is the one override that is pre-filled rather than left blank behind a
        // placeholder. The others mean "blank = keep what MusicBrainz said"; genre is a
        // list assembled from pills, so it has to be able to end up empty. With a
        // placeholder fallback, unticking the last pill would restore every suggestion.
        $this->overrides['genre'] = $metadata->genreList();
        $this->step = 3;

        unset($this->resolved);
    }

    // ------------------------------------------------------------------- step 3

    #[Computed]
    public function resolved(): TrackMetadata
    {
        return TrackMetadata::fromArray($this->metadata)->withOverrides($this->overrides);
    }

    /**
     * What MusicBrainz suggested, before any override.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function suggestedGenres(): array
    {
        return TrackMetadata::fromArray($this->metadata)->genres;
    }

    /** Add or remove one of MusicBrainz's genre suggestions. */
    public function toggleGenre(string $genre): void
    {
        if (! in_array($genre, $this->suggestedGenres, true)) {
            return;
        }

        $current = TrackMetadata::splitGenres((string) ($this->overrides['genre'] ?? ''));

        $index = array_search(mb_strtolower($genre), array_map('mb_strtolower', $current), true);

        if ($index === false) {
            $current[] = $genre;
        } else {
            unset($current[$index]);
        }

        /*
         * An empty result is stored as an empty string rather than unset. Unsetting would fall
         * back to what MusicBrainz suggested, so removing the last genre would silently put
         * them all back - the opposite of what the click asked for.
         */
        $this->overrides['genre'] = implode(', ', $current);

        unset($this->resolved);
    }

    /** Whether a suggestion is currently in the field, so its pill can show as selected. */
    public function genreSelected(string $genre): bool
    {
        $current = array_map(
            'mb_strtolower',
            TrackMetadata::splitGenres((string) ($this->overrides['genre'] ?? '')),
        );

        return in_array(mb_strtolower($genre), $current, true);
    }

    public function back(): void
    {
        // Step 3 returns to the track picker when there was one, and to the search
        // when there was not - a standalone never had a step 2 to go back to.
        $this->step = $this->step === 3 && $this->tracks !== [] ? 2 : 1;
    }

    public function write(MetadataWriter $writer): void
    {
        $file = $this->file();

        $this->authorize('editMetadata', $file);

        try {
            $result = $writer->write($file, $this->resolved, $this->rename);
        } catch (MetadataException|LibraryException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::modals()->close();

        // Warnings, not errors: the tags are written. A cover that would not embed or a
        // rename that collided is worth saying, but must not read as "nothing happened".
        if ($result->hasWarnings()) {
            Flux::toast(variant: 'warning', text: __('Tags written. ').$result->warningText());
        } else {
            Flux::toast(variant: 'success', text: __('Tags written to :file.', ['file' => $result->file->filename]));
        }

        $this->reset();

        // The Files screen owns the listing, and both the size and (on a rename) the
        // filename just changed.
        $this->dispatch('library-updated');
    }

    // ------------------------------------------------------------------ internals

    private function loadRelease(MetadataLookup $lookup, string $releaseId): void
    {
        if (! Mbid::isValid($releaseId)) {
            $this->addError('releaseId', __('That is not a MusicBrainz release ID.'));

            return;
        }

        try {
            $tracks = $lookup->tracks($releaseId, $this->title);
        } catch (MetadataException $e) {
            $this->addError('releaseId', $e->getMessage());

            return;
        }

        $this->releaseId = $releaseId;
        $this->tracks = array_map(fn (TrackCandidate $track): array => $track->toArray(), $tracks);

        unset($this->trackRows);

        /*
         * A single-track release needs no picker either. The design only describes
         * step 2 for a release that "contains multiple tracks", and stopping to
         * confirm the only option is friction with no decision in it.
         */
        if (count($tracks) === 1) {
            $this->pickTrack($lookup, $tracks[0]->mediaPosition, $tracks[0]->trackIndex);

            return;
        }

        if ($tracks === []) {
            $this->addError('releaseId', __('That release has no track listing.'));

            return;
        }

        $this->step = 2;
    }

    private function loadStandalone(MetadataLookup $lookup, string $recordingId): void
    {
        try {
            $metadata = $lookup->standaloneMetadata($recordingId);
        } catch (MetadataException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->metadata = $metadata->toArray();

        $this->overrides = [];
        // Genre is the one override that is pre-filled rather than left blank behind a
        // placeholder. The others mean "blank = keep what MusicBrainz said"; genre is a
        // list assembled from pills, so it has to be able to end up empty. With a
        // placeholder fallback, unticking the last pill would restore every suggestion.
        $this->overrides['genre'] = $metadata->genreList();

        // No release, so no track listing and nothing for step 2 to show.
        $this->tracks = [];
        $this->releaseId = '';
        $this->step = 3;

        unset($this->resolved, $this->trackRows);
    }

    /** Re-resolve the file from disk on every action. */
    private function file(): LibraryFile
    {
        return $this->resolve($this->folder, $this->filename);
    }

    private function resolve(string $folder, string $filename): LibraryFile
    {
        $resolved = app(FileService::class)->find(new LibraryFolder($folder), $filename);

        abort_if($resolved === null, 404);

        return $resolved;
    }

}; ?>

<div>
    <x-ui.modal-shell
        name="metadata-edit"
        :title="__('Edit metadata')"
        :width="740"
    >
        <p class="text-ink-muted -mt-4 mb-5 text-xs">
            {{ __('Update metadata for') }}
            <span class="text-ink-secondary font-bold">{{ $filename }}</span>
        </p>

        {{-- Step 1 · search ------------------------------------------------- --}}
        @if ($step === 1)
            <form wire:submit="search" class="flex flex-col gap-4">
                @if ($forceReleaseId)
                    <flux:input
                        wire:model="releaseId"
                        :label="__('MusicBrainz release ID')"
                        placeholder="52fa0b53-4bad-4bbe-b23b-d82233500fc7"
                        class:input="font-mono"
                    />
                @else
                    <flux:input wire:model="artist" :label="__('Artist')" />
                    <flux:input wire:model="title" :label="__('Title')" />
                @endif

                <div class="flex flex-wrap items-center gap-5">
                    <flux:checkbox wire:model="rename" :label="__('Rename file')" />

                    <x-ui.mono variant="chip">%Artist% - %Track%.%extension%</x-ui.mono>

                    {{-- Live so the inputs swap immediately; the label is the design's. --}}
                    <flux:checkbox wire:model.live="forceReleaseId" :label="__('Force release id')" />

                    {{-- Four sequential MusicBrainz passes at one request per second, so this can
                                             take several seconds with nothing else on screen changing. --}}
                    <flux:button
                        variant="primary"
                        type="submit"
                        class="ms-auto"
                        wire:loading.attr="disabled"
                        wire:target="search"
                    >
                        <span wire:loading.remove wire:target="search">
                            {{ $forceReleaseId ? __('Load release') : __('Search') }}
                        </span>
                        <span wire:loading wire:target="search">{{ __('Searching…') }}</span>
                    </flux:button>
                </div>

                @error('releaseId')
                    <p class="text-danger text-xs">{{ $message }}</p>
                @enderror

                @error('title')
                    <p class="text-danger text-xs">{{ $message }}</p>
                @enderror

                {{-- Results ------------------------------------------------- --}}
                @if ($searched)
                    @if ($this->results === [])
                        <x-ui.empty-state
                            dashed
                            :message="__('Nothing on MusicBrainz matched that. Try a shorter title, or drop the artist.')"
                        />
                    @else
                        {{-- Picking a release is a MusicBrainz lookup plus a Cover Art
                                                     Archive call, both behind a one-per-second throttle.
                        
                                                     The overlay sits over the results, where the eye already
                                                     is and where the table is about to be replaced.
                                                     pointer-events-none underneath, so a second impatient
                                                     click cannot queue a second lookup. --}}
                        <div class="relative">
                            <x-ui.busy overlay target="pick" :message="__('Loading release from MusicBrainz…')" />

                            <x-ui.data-table
                                cols="110px 1fr 1fr 70px"
                                wire:loading.class="pointer-events-none"
                                wire:target="pick"
                            >
                            <x-ui.data-table.head>
                                <x-ui.data-table.column>{{ __('Type') }}</x-ui.data-table.column>
                                <x-ui.data-table.column>{{ __('Release') }}</x-ui.data-table.column>
                                <x-ui.data-table.column>{{ __('Artist') }}</x-ui.data-table.column>
                                <x-ui.data-table.column align="end">{{ __('Year') }}</x-ui.data-table.column>
                            </x-ui.data-table.head>

                            @foreach ($this->results as $candidate)
                                <x-ui.data-table.row
                                    wire:key="candidate-{{ $candidate->key() }}"
                                    :last="$loop->last"
                                    class="cursor-pointer"
                                    {{-- Js::from(), not @js(): a directive in the attribute of a
                                                                             bag-forwarding component is never compiled.
                                                                             See the note in files.blade.php. --}}
                                    wire:click="pick({{ Js::from($candidate->id) }}, {{ Js::from($candidate->type->value) }})"
                                >
                                    <x-ui.data-table.cell>
                                        {{-- A standalone is tinted, because picking it leads
                                                                                     somewhere different: no track list, no
                                                                                     cover art. --}}
                                        <x-ui.pill :tone="$candidate->isStandalone() ? 'accent' : 'neutral'">
                                            {{ $candidate->type->label() }}
                                        </x-ui.pill>
                                    </x-ui.data-table.cell>

                                    <x-ui.data-table.cell class="text-brand-text font-bold">
                                        {{ $candidate->title }}
                                    </x-ui.data-table.cell>

                                    <x-ui.data-table.cell class="text-ink-muted">
                                        {{ $candidate->artist }}
                                    </x-ui.data-table.cell>

                                    <x-ui.data-table.cell align="end">
                                        <x-ui.mono>{{ $candidate->year() ?? '—' }}</x-ui.mono>
                                    </x-ui.data-table.cell>
                                </x-ui.data-table.row>
                            @endforeach
                            </x-ui.data-table>
                        </div>
                    @endif
                @endif
            </form>
        @endif

        {{-- Step 2 · pick a track ------------------------------------------- --}}
        @if ($step === 2)
            <div class="flex flex-col gap-4">
                <div>
                    <h3 class="text-md font-extrabold">{{ __('Select track') }}</h3>
                    <p class="text-ink-muted mt-1.5 text-xs">
                        {{ __('This release contains multiple tracks. Pick the one that matches “:title”.', ['title' => $title]) }}
                    </p>
                </div>

                {{-- Same as step 1: picking a track fetches the release's full detail and
                                     its cover art before step 3 can render. --}}
                <div class="relative">
                    <x-ui.busy overlay target="pickTrack" :message="__('Loading track metadata…')" />

                    <x-ui.data-table
                        cols="44px 1fr 90px 150px"
                        wire:loading.class="pointer-events-none"
                        wire:target="pickTrack"
                    >
                    <x-ui.data-table.head>
                        <x-ui.data-table.column>#</x-ui.data-table.column>
                        <x-ui.data-table.column>{{ __('Title') }}</x-ui.data-table.column>
                        <x-ui.data-table.column>{{ __('Duration') }}</x-ui.data-table.column>
                        <x-ui.data-table.column>{{ __('Match') }}</x-ui.data-table.column>
                    </x-ui.data-table.head>

                    @foreach ($this->trackRows as $track)
                        <x-ui.data-table.row
                            wire:key="track-{{ $track->key() }}"
                            :last="$loop->last"
                            class="cursor-pointer {{ $track->isBestMatch ? 'bg-row-hover-strong' : '' }}"
                            wire:click="pickTrack({{ $track->mediaPosition }}, {{ $track->trackIndex }})"
                        >
                            <x-ui.data-table.cell>
                                <x-ui.mono>{{ $track->number }}</x-ui.mono>
                            </x-ui.data-table.cell>

                            <x-ui.data-table.cell class="font-semibold">
                                {{ $track->title }}

                                @if ($track->isBestMatch)
                                    <x-ui.pill class="shrink-0">{{ __('Best match') }}</x-ui.pill>
                                @endif
                            </x-ui.data-table.cell>

                            <x-ui.data-table.cell>
                                <x-ui.mono variant="strong">{{ $track->durationLabel() }}</x-ui.mono>
                            </x-ui.data-table.cell>

                            <x-ui.data-table.cell>
                                <x-ui.progress-bar
                                    :percent="$track->matchScore"
                                    tone="brand"
                                    class="w-[90px] shrink-0"
                                />
                                <x-ui.mono>{{ round($track->matchScore) }}%</x-ui.mono>
                            </x-ui.data-table.cell>
                        </x-ui.data-table.row>
                    @endforeach
                    </x-ui.data-table>
                </div>

                <p class="border-warning/50 bg-warning-soft text-warning rounded-xl border px-4 py-3 text-xs font-semibold">
                    {{ __('Tip: “Best match” is picked automatically from your search title.') }}
                </p>

                <x-ui.modal-footer>
                    <span class="text-ink-faint me-auto text-xs">
                        {{ __('Release ID:') }} <x-ui.mono>{{ $releaseId }}</x-ui.mono>
                    </span>

                    <flux:button variant="outline" type="button" wire:click="back">
                        {{ __('Previous') }}
                    </flux:button>
                </x-ui.modal-footer>
            </div>
        @endif

        {{-- Step 3 · review and write --------------------------------------- --}}
        @if ($step === 3)
            @php $resolved = $this->resolved; @endphp

            <div class="flex flex-col gap-5">
                {{-- The fields a human might want to correct. Everything else is
                                    machine data, shown read-only below.
                
                                    Genre is editable because MusicBrainz often has none, or has one
                                    nobody would call the record by. It is also the field the Files
                                    listing shows in its own column, so a blank one is visible from
                                    the outside.
                
                                    Each input is empty with the resolved value as its placeholder:
                                    leaving it alone accepts what MusicBrainz said. --}}
                <div class="grid grid-cols-2 gap-4">
                    <flux:input wire:model="overrides.title" :label="__('Title')" :placeholder="$resolved->title" />
                    <flux:input wire:model="overrides.artist" :label="__('Artist')" :placeholder="$resolved->artist" />
                    <flux:input wire:model="overrides.album" :label="__('Album')" :placeholder="$resolved->album ?? '—'" />
                    <flux:input wire:model="overrides.year" :label="__('Year')" :placeholder="$resolved->year ?? '—'" />

                    {{-- Plural. A comma separates; a space does NOT, since genres routinely
                                            contain one ("alternative pop", "drum and bass"). Each entry
                                            becomes its own GENRE tag, which is how the Vorbis spec
                                            expresses a list. Semicolons are accepted too. --}}
                    {{-- .live so the suggestion pills below track what is typed. Debounced,
                                             or every keystroke would be a round trip. --}}
                    <flux:input
                        wire:model.live.debounce.400ms="overrides.genre"
                        :label="__('Genres')"
                        :placeholder="__('none')"
                        :description="__('Separate several with commas — each becomes its own tag.')"
                    />

                    {{-- MusicBrainz usually returns several; toggling is faster than typing
                                             them back in. Read from the SUGGESTED list, not the resolved
                                             one: an override replaces the latter, so the pills would
                                             vanish on first click. --}}
                    @if ($this->suggestedGenres !== [])
                        <div class="flex flex-col justify-end gap-1.5 pb-6">
                            <span class="text-ink-faint text-3xs font-extrabold tracking-[1px] uppercase">
                                {{ __('Suggested') }}
                            </span>

                            <div class="flex flex-wrap gap-1.5">
                                @foreach (array_slice($this->suggestedGenres, 0, 6) as $suggestion)
                                    <x-ui.pill
                                        as="button"
                                        wire:key="genre-{{ $loop->index }}"
                                        :selected="$this->genreSelected($suggestion)"
                                        wire:click="toggleGenre({{ \Illuminate\Support\Js::from($suggestion) }})"
                                    >{{ $suggestion }}</x-ui.pill>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                <div class="border-hairline grid grid-cols-2 gap-x-6 gap-y-4 border-t pt-5">
                    @foreach ($resolved->displayFields() as $label => $value)
                        <div>
                            <div class="text-ink-faint text-3xs font-extrabold tracking-[1px] uppercase">
                                {{ $label }}
                            </div>

                            {{-- An empty cell is the design's answer for a field a standalone
                                                             recording cannot have. --}}
                            <div class="mt-1 text-xs font-semibold break-words">
                                {{ filled($value) ? $value : '—' }}
                            </div>
                        </div>
                    @endforeach

                    @if ($resolved->link)
                        <div class="col-span-2">
                            <div class="text-ink-faint text-3xs font-extrabold tracking-[1px] uppercase">
                                {{ __('LINK') }}
                            </div>

                            <x-ui.external-link :href="$resolved->link" class="mt-1 text-xs break-all">
                                {{ $resolved->link }}
                            </x-ui.external-link>
                        </div>
                    @endif
                </div>

                {{-- Cover art --------------------------------------------- --}}
                <div class="flex flex-col gap-2">
                    @if ($resolved->coverArtUrl)
                        <img
                            src="{{ $resolved->coverArtUrl }}"
                            alt="{{ __('Cover art') }}"
                            class="border-border size-[170px] rounded-xl border object-cover"
                        />
                        <x-ui.mono size="text-3xs">{{ __('cover art · coverartarchive.org') }}</x-ui.mono>
                    @else
                        <x-ui.tile :name="$resolved->album ?? $resolved->title ?? $filename" size="cover" />

                        {{-- Said out loud rather than left a mystery: the archive is keyed by
                                                     release, so a recording that belongs to none has nowhere
                                                     for artwork to live. --}}
                        <x-ui.mono size="text-3xs">
                            {{ $resolved->standalone
                                ? __('no cover art · standalone recordings have no release')
                                : __('no cover art on coverartarchive.org') }}
                        </x-ui.mono>
                    @endif
                </div>

                <x-ui.modal-footer>
                    {{-- The ID gives way to the progress line while writing: the write
                                             downloads cover art and then shells out to metaflac, the
                                             longest wait in the modal. --}}
                    <span class="text-ink-faint me-auto text-xs" wire:loading.remove wire:target="write">
                        @if ($resolved->releaseId)
                            {{ __('Release ID:') }} <x-ui.mono>{{ $resolved->releaseId }}</x-ui.mono>
                        @else
                            {{ __('Recording ID:') }} <x-ui.mono>{{ $resolved->recordingId }}</x-ui.mono>
                        @endif
                    </span>

                    <x-ui.busy class="me-auto" target="write" :message="__('Writing tags and embedding cover art…')" />

                    <flux:button
                        variant="outline"
                        type="button"
                        wire:click="back"
                        wire:loading.attr="disabled"
                        wire:target="write"
                    >{{ __('Previous') }}</flux:button>

                    <flux:button
                        variant="primary"
                        type="button"
                        wire:click="write"
                        wire:loading.attr="disabled"
                        wire:target="write"
                    >
                        <span wire:loading.remove wire:target="write">{{ __('Write metadata') }}</span>
                        <span wire:loading wire:target="write">{{ __('Writing…') }}</span>
                    </flux:button>
                </x-ui.modal-footer>
            </div>
        @endif
    </x-ui.modal-shell>
</div>
