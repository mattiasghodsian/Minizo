<?php

use App\Exceptions\LibraryException;
use App\Services\Library\FolderService;
use App\Support\LibraryFolder;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/** Owns the three folder modals: new, rename, delete. */
new class extends Component
{
    public bool $showCreate = false;

    public bool $showRename = false;

    public bool $showDelete = false;

    public string $name = '';

    /** The folder being renamed or deleted. */
    public string $target = '';

    #[Computed]
    public function folders(): array
    {
        return app(FolderService::class)->names();
    }

    /** Live duplicate detection, so the "/music/<name>" preview can show the problem before the form is submitted. */
    #[Computed]
    public function nameError(): ?string
    {
        $name = trim($this->name);

        if ($name === '') {
            return null;
        }

        if (LibraryFolder::tryMake($name) === null) {
            return __('Letters, numbers, spaces and dashes. No slashes.');
        }

        $taken = collect($this->folders)
            ->reject(fn (string $folder): bool => $this->showRename && strcasecmp($folder, $this->target) === 0)
            ->contains(fn (string $folder): bool => strcasecmp($folder, $name) === 0);

        return $taken ? __('A folder with that name already exists.') : null;
    }

    #[Computed]
    public function previewPath(): string
    {
        return '/music/'.(trim($this->name) ?: '…');
    }

    #[On('folder-create')]
    public function openCreate(): void
    {
        $this->authorize('create', LibraryFolder::class);

        $this->reset('name', 'target');
        $this->showCreate = true;

        Flux::modal('folder-create')->show();
    }

    #[On('folder-rename')]
    public function openRename(string $folder): void
    {
        $this->authorize('rename', new LibraryFolder($folder));

        $this->target = $folder;
        $this->name = $folder;
        $this->showRename = true;

        Flux::modal('folder-rename')->show();
    }

    #[On('folder-delete')]
    public function openDelete(string $folder): void
    {
        $this->authorize('delete', new LibraryFolder($folder));

        $this->target = $folder;
        $this->showDelete = true;

        Flux::modal('folder-delete')->show();
    }

    public function create(FolderService $folders): void
    {
        $this->authorize('create', LibraryFolder::class);

        try {
            $folder = $folders->create($this->name);
        } catch (LibraryException $e) {
            // A user-fixable outcome, so it belongs on the field rather than in an
            // error page.
            $this->addError('name', $e->getMessage());

            return;
        }

        $this->finish(__('Folder ":name" created.', ['name' => $folder->name]));

        $this->redirectRoute('files', $folder->name, navigate: true);
    }

    public function rename(FolderService $folders): void
    {
        $folder = new LibraryFolder($this->target);

        $this->authorize('rename', $folder);

        try {
            $renamed = $folders->rename($folder, $this->name);
        } catch (LibraryException $e) {
            $this->addError('name', $e->getMessage());

            return;
        }

        $this->finish(__('Renamed to ":name".', ['name' => $renamed->name]));

        // The URL still carries the old name, so stay on the folder under its new one.
        $this->redirectRoute('files', $renamed->name, navigate: true);
    }

    public function delete(FolderService $folders): void
    {
        $folder = new LibraryFolder($this->target);

        $this->authorize('delete', $folder);

        try {
            $folders->delete($folder);
        } catch (LibraryException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
            $this->finish();

            return;
        }

        $this->finish(__('Folder ":name" deleted.', ['name' => $folder->name]));

        // Nowhere left to stand if we were viewing it.
        $next = $folders->firstVisibleTo(auth()->user());

        $this->redirect(
            $next !== null ? route('files', $next->name) : route('download'),
            navigate: true,
        );
    }

    private function finish(?string $message = null): void
    {
        $this->reset('name', 'target', 'showCreate', 'showRename', 'showDelete');

        Flux::modals()->close();

        if ($message !== null) {
            Flux::toast(variant: 'success', text: $message);
        }
    }
}; ?>

<div>
    {{-- New folder ---------------------------------------------------------- --}}
    <x-ui.modal-shell name="folder-create" :title="__('New folder')" :width="440">
        <form wire:submit="create" class="space-y-4">
            <flux:input
                wire:model.live.debounce.300ms="name"
                :label="__('Folder name')"
                :placeholder="__('e.g. Spanish')"
                autofocus
            />

            {{-- The design shows the resulting path as you type. --}}
            <x-ui.mono>{{ $this->previewPath }}</x-ui.mono>

            @if ($this->nameError)
                <p class="text-danger text-xs">{{ $this->nameError }}</p>
            @endif

            <x-ui.modal-footer>
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                {{-- Dimmed rather than hidden while invalid, so the button does not jump
                                     around as you type. --}}
                <flux:button
                    variant="primary"
                    type="submit"
                    :disabled="blank(trim($name)) || filled($this->nameError)"
                    class="disabled:pointer-events-none disabled:opacity-40"
                >{{ __('Create folder') }}</flux:button>
            </x-ui.modal-footer>
        </form>
    </x-ui.modal-shell>

    {{-- Rename folder ------------------------------------------------------- --}}
    <x-ui.modal-shell
        name="folder-rename"
        :title="__('Rename folder')"
        :subtitle="__('Renames the directory on disk. Existing share links keep working.')"
        :width="440"
    >
        <form wire:submit="rename" class="space-y-4">
            <flux:input
                wire:model.live.debounce.300ms="name"
                :label="__('Folder name')"
                autofocus
            />

            <x-ui.mono>{{ $this->previewPath }}</x-ui.mono>

            @if ($this->nameError)
                <p class="text-danger text-xs">{{ $this->nameError }}</p>
            @endif

            <x-ui.modal-footer>
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button
                    variant="primary"
                    type="submit"
                    :disabled="blank(trim($name)) || filled($this->nameError)"
                    class="disabled:pointer-events-none disabled:opacity-40"
                >{{ __('Rename') }}</flux:button>
            </x-ui.modal-footer>
        </form>
    </x-ui.modal-shell>

    {{-- Delete folder ------------------------------------------------------- --}}
    <x-ui.confirm-modal
        name="folder-delete"
        :title="__('Delete folder')"
        :body="__('Everything inside :name is deleted from disk. This cannot be undone, and any active share links pointing at it stop working immediately.', ['name' => $target ?: '…'])"
        variant="delete-folder"
        :confirm-label="__('Delete folder')"
        confirm="delete"
    />
</div>
