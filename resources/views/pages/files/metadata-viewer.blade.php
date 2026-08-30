<?php

use App\Services\Library\FileService;
use App\Services\Metadata\AudioTagReader;
use App\Support\AudioTags;
use App\Support\LibraryFile;
use App\Support\LibraryFolder;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/** Read-only preview of what a file currently carries. */
new class extends Component
{
    public string $folder = '';

    public string $filename = '';

    /** @var array<string, mixed> */
    public array $tags = [];

    /** True when the file could not be parsed at all. */
    public bool $unreadable = false;

    #[On('metadata-view')]
    public function open(string $folder, string $filename, AudioTagReader $reader): void
    {
        $file = $this->resolve($folder, $filename);

        $this->authorize('view', $file);

        $this->folder = $file->folder->name;
        $this->filename = $file->filename;

        $tags = $reader->read($file);

        // A file can be truncated, half-written by a download in flight, or simply not the
        // format its extension claims. Saying so beats an empty grid.
        $this->unreadable = $tags === null;
        $this->tags = $tags?->toArray() ?? [];

        unset($this->parsed);

        Flux::modal('metadata-view')->show();
    }

    #[Computed]
    public function parsed(): AudioTags
    {
        return AudioTags::fromArray($this->tags);
    }

    #[Computed]
    public function file(): ?LibraryFile
    {
        return $this->filename === ''
            ? null
            : app(FileService::class)->find(new LibraryFolder($this->folder), $this->filename);
    }

    /** The cover endpoint for this file, or null when the tags say there is none. */
    #[Computed]
    public function coverUrl(): ?string
    {
        return $this->parsed->hasCover && $this->filename !== ''
            ? route('files.cover', [$this->folder, $this->filename])
            : null;
    }

    /** Hands off to the editor without making the user reopen the row menu. */
    public function edit(): void
    {
        Flux::modals()->close();

        $this->dispatch('metadata-edit', folder: $this->folder, filename: $this->filename);
    }

    private function resolve(string $folder, string $filename): LibraryFile
    {
        $file = app(FileService::class)->find(new LibraryFolder($folder), $filename);

        abort_if($file === null, 404);

        return $file;
    }
}; ?>

<div>
    <x-ui.modal-shell
        name="metadata-view"
        :title="__('File metadata')"
        :width="560"
    >
        <p class="text-ink-muted -mt-4 mb-5 text-xs">
            {{ __('Currently stored in') }}
            <span class="text-ink-secondary font-bold">{{ $filename }}</span>
        </p>

        @if ($unreadable)
            <x-ui.empty-state
                dashed
                icon="exclamation-triangle"
                :message="__('This file could not be read. It may be truncated, still downloading, or not really the format its extension claims.')"
            />
        @else
            @php $tags = $this->parsed; @endphp

            <div class="flex flex-col gap-5">
                {{-- Artwork and the stream facts side by side: the two things you cannot
                                     see anywhere else in the app. --}}
                <div class="flex items-start gap-4.5">
                    <x-ui.tile
                        :name="$tags->album ?? $tags->title ?? $filename"
                        size="cover"
                        variant="cover"
                        :cover="$this->coverUrl"
                        {{-- The tags are already loaded, so we know whether artwork exists. --}}
                        :cover-known="$this->coverUrl !== null"
                        class="size-[104px]! text-4xl! mr-4"
                    />

                    <div class="flex min-w-0 flex-1 flex-col gap-1.5 pt-0.5">
                        <span class="truncate text-md font-extrabold">
                            {{ $tags->title ?? $this->file?->basename() ?? $filename }}
                        </span>

                        <span class="text-ink-muted truncate text-xs font-semibold">
                            {{ $tags->artist ?? __('Unknown artist') }}
                        </span>

                        <x-ui.meta-line :parts="array_filter([
                            $tags->durationLabel(),
                            $this->file?->sizeLabel(),
                            $this->file?->formatLabel(),
                        ])" />

                        @if ($tags->streamLabel())
                            <x-ui.mono size="text-2xs">{{ $tags->streamLabel() }}</x-ui.mono>
                        @endif

                        @if ($tags->bitrateLabel())
                            <x-ui.mono size="text-2xs">{{ $tags->bitrateLabel() }}</x-ui.mono>
                        @endif

                        {{-- Says where the tags came from, which is the difference between a
                                                     database and whatever YouTube called the video. --}}
                        @if ($tags->hasMusicBrainzIds())
                            <x-ui.pill tone="success" class="mt-1 self-start">
                                {{ __('Tagged from MusicBrainz') }}
                            </x-ui.pill>
                        @elseif (! $tags->isEmpty())
                            <x-ui.pill tone="warning" class="mt-1 self-start">
                                {{ __('Not matched to MusicBrainz') }}
                            </x-ui.pill>
                        @endif
                    </div>
                </div>

                @if ($tags->isEmpty())
                    <x-ui.empty-state
                        dashed
                        :message="__('This file has no tags yet. Use Edit metadata to look it up on MusicBrainz.')"
                    />
                @else
                    {{-- The tag grid, in the same shape as step 3 of the editor, so the two
                                             read as before-and-after. --}}
                    <div class="border-hairline grid grid-cols-2 gap-x-6 gap-y-4 border-t pt-5">
                        @foreach ($tags->tagFields() as $label => $value)
                            <div @class(['col-span-2' => str_starts_with($label, 'MUSICBRAINZ_')])>
                                <div class="text-ink-faint text-3xs font-extrabold tracking-[1px] uppercase">
                                    {{ $label }}
                                </div>

                                <div @class([
                                    'mt-1 text-xs font-semibold break-words',
                                    // The identifiers are machine values, so they read mono
                                    // like every other machine value in the design.
                                    'font-mono text-2xs' => str_starts_with($label, 'MUSICBRAINZ_') || $label === 'ISRC' || $label === 'BARCODE',
                                ])>
                                    {{ filled($value) ? $value : '—' }}
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if ($tags->comment)
                        <div>
                            <div class="text-ink-faint text-3xs font-extrabold tracking-[1px] uppercase">
                                {{ __('COMMENT') }}
                            </div>
                            <p class="text-ink-muted mt-1 text-xs break-words">{{ $tags->comment }}</p>
                        </div>
                    @endif
                @endif

                {{-- Outside the isEmpty() branch: "no tags, but it does have artwork"
                                    is the normal state of a fresh download, since yt-dlp embeds the
                                    video thumbnail long before anything is tagged. --}}
                <x-ui.mono size="text-3xs">
                    {{ $tags->coverLabel()
                        ? __('embedded artwork · :details', ['details' => $tags->coverLabel()])
                        : __('no embedded artwork') }}
                </x-ui.mono>
            </div>
        @endif

        <x-ui.modal-footer>
            {{-- Only when there is a file AND the user may tag it: the preview is
                             readable by anyone who can see the folder, editing is not. --}}
            @if ($this->file !== null && auth()->user()->can('editMetadata', $this->file))
                <flux:button variant="ghost" type="button" wire:click="edit" class="me-auto">
                    {{ __('Edit metadata') }}
                </flux:button>
            @endif

            <flux:modal.close>
                <flux:button variant="outline" type="button">{{ __('Close') }}</flux:button>
            </flux:modal.close>
        </x-ui.modal-footer>
    </x-ui.modal-shell>
</div>
