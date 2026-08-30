<?php

use App\Enums\ShareExpiry;
use App\Enums\ShareType;
use App\Exceptions\ShareException;
use App\Services\Library\FileService;
use App\Services\Sharing\ShareService;
use App\Support\LibraryFolder;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/** The Share modal: pick a lifetime, get a URL. */
new class extends Component
{
    /** folder | track - what is being shared. */
    public string $type = '';

    public string $folder = '';

    public string $filename = '';

    /** Seconds, from ShareExpiry. */
    public int $expiry = 0;

    /** Set once a link exists; the modal then shows the URL instead of the button. */
    public string $token = '';

    public function mount(): void
    {
        $this->expiry = ShareExpiry::default()->value;
    }

    #[On('share-folder')]
    public function openFolder(string $folder): void
    {
        $resolved = new LibraryFolder($folder);

        $this->authorize('share', $resolved);

        $this->reset('filename', 'token');

        $this->type = ShareType::Folder->value;
        $this->folder = $resolved->name;
        $this->expiry = ShareExpiry::default()->value;

        Flux::modal('share')->show();
    }

    #[On('share-file')]
    public function openFile(string $folder, string $filename): void
    {
        $file = app(FileService::class)->find(new LibraryFolder($folder), $filename);

        abort_if($file === null, 404);

        $this->authorize('share', $file);

        $this->reset('token');

        $this->type = ShareType::Track->value;
        $this->folder = $file->folder->name;
        $this->filename = $file->filename;
        $this->expiry = ShareExpiry::default()->value;

        Flux::modal('share')->show();
    }

    #[Computed]
    public function shareType(): ShareType
    {
        return ShareType::tryFrom($this->type) ?? ShareType::Folder;
    }

    /** What the modal names: a folder, or a track without its extension. */
    #[Computed]
    public function itemName(): string
    {
        return $this->shareType() === ShareType::Track
            ? pathinfo($this->filename, PATHINFO_FILENAME)
            : $this->folder;
    }

    #[Computed]
    public function expiryLabel(): string
    {
        return (ShareExpiry::tryFrom($this->expiry) ?? ShareExpiry::default())->label();
    }

    #[Computed]
    public function url(): ?string
    {
        return $this->token === '' ? null : route('share.show', $this->token);
    }

    public function generate(ShareService $shares): void
    {
        $expiry = ShareExpiry::tryFrom($this->expiry) ?? ShareExpiry::default();

        try {
            $user = auth()->user();

            $share = $this->shareType() === ShareType::Track
                ? $shares->shareFile($user, $this->file(), $expiry)
                : $shares->shareFolder($user, new LibraryFolder($this->folder), $expiry);
        } catch (ShareException $e) {
            // A user-fixable outcome - an empty folder, a file that has gone, the
            // instance switch turned off since the modal opened.
            $this->addError('expiry', $e->getMessage());

            return;
        }

        $this->token = $share->token;

        unset($this->url);

        // The Share links screen is a sibling and owns its own listing.
        $this->dispatch('shares-updated');
    }

    private function file(): \App\Support\LibraryFile
    {
        // Re-resolved from disk on submit, never trusted from the payload.
        $file = app(FileService::class)->find(new LibraryFolder($this->folder), $this->filename);

        abort_if($file === null, 404);

        return $file;
    }
}; ?>

<div>
    <x-ui.modal-shell
        name="share"
        :title="__('Share :kind', ['kind' => $this->shareType()->label()])"
        :width="480"
    >
        <p class="text-ink-muted -mt-4 mb-4.5 text-xs">
            {{ __('Create a public link for') }}
            <span class="text-ink-secondary font-bold">{{ $this->itemName() }}</span>
        </p>

        @if ($this->url === null)
            <form wire:submit="generate" class="flex items-end gap-2.5">
                <flux:select wire:model="expiry" :label="__('Expires after')" class="flex-1">
                    @foreach (ShareExpiry::cases() as $option)
                        <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:button variant="primary" type="submit">{{ __('Generate link') }}</flux:button>
            </form>

            @error('expiry')
                <p class="text-danger mt-3 text-xs">{{ $message }}</p>
            @enderror
        @else
            <div class="flex flex-col gap-2.5">
                <div class="flex gap-2">
                    {{-- The URL, selectable. A read-only input rather than a div, so it can be
                                             selected and copied by keyboard where the clipboard API is
                                             unavailable, which is the case on plain http. --}}
                    <input
                        type="text"
                        readonly
                        value="{{ $this->url }}"
                        onfocus="this.select()"
                        class="bg-sunken border-field-border text-brand-text min-w-0 flex-1 truncate rounded-[9px] border px-3.5 py-2.5 font-mono text-2xs"
                        aria-label="{{ __('Public link') }}"
                    />

                    <x-ui.copy-button :value="$this->url" class="shrink-0" />
                </div>

                <p class="text-ink-faint text-2xs leading-relaxed">
                    {{ __('Anyone with this link can browse and download the shared content — it stops working after :expiry.', ['expiry' => $this->expiryLabel()]) }}
                </p>

                <x-ui.external-link :href="$this->url" class="text-brand-text! self-start font-extrabold">
                    {{ __('Open the public page') }}
                </x-ui.external-link>
            </div>
        @endif

        <x-ui.modal-footer>
            <flux:modal.close>
                <flux:button variant="outline" type="button">{{ __('Close') }}</flux:button>
            </flux:modal.close>
        </x-ui.modal-footer>
    </x-ui.modal-shell>
</div>
