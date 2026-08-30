<?php

use App\Enums\AudioFormat;
use App\Enums\Permission;
use App\Exceptions\DownloadException;
use App\Models\DownloadJob;
use App\Models\User;
use App\Services\Download\DownloadQueue;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/** The Download screen, and the app's home. */
new class extends Component
{
    public string $url = '';

    /** Destination folder name. Ignored entirely when the user is locked to one. */
    public string $folder = '';

    /** Whose Recent activity is shown. Admin-only; null means "mine". */
    public ?int $previewUserId = null;

    public function mount(): void
    {
        // Preselect a destination so the common case is one paste and one click. A
        // locked user has exactly one option and never sees the select at all.
        $this->folder = $this->destinations()[0] ?? '';
    }

    // ------------------------------------------------------------------ the form

    #[Computed]
    public function canDownload(): bool
    {
        return auth()->user()->hasPermission(Permission::Downloader);
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function destinations(): array
    {
        return app(DownloadQueue::class)->destinationsFor(auth()->user());
    }

    /** Whether an admin has pinned this user's downloads to one folder. */
    #[Computed]
    public function folderLocked(): bool
    {
        return filled(auth()->user()->download_folder_lock);
    }

    /**
     * The formats offered.
     *
     * @return array<int, AudioFormat>
     */
    #[Computed]
    public function formats(): array
    {
        $lock = auth()->user()->download_format_lock;

        return $lock !== null ? [$lock] : AudioFormat::cases();
    }

    public function queueDownload(DownloadQueue $queue): void
    {
        try {
            $job = $queue->push(auth()->user(), $this->url, $this->folder);
        } catch (DownloadException $e) {
            if ($e->field !== null) {
                $this->addError($e->field, $e->getMessage());
            } else {
                Flux::toast(variant: 'danger', text: $e->getMessage());
            }

            return;
        }

        $this->reset('url');
        $this->refreshRows();

        Flux::toast(
            variant: 'success',
            text: __('Queued into :folder.', ['folder' => $job->folder]),
        );
    }

    // ----------------------------------------------------------------- the queue

    /**
     * @return Collection<int, DownloadJob>
     */
    #[Computed]
    public function queue(): Collection
    {
        return DownloadJob::query()
            ->where('user_id', auth()->id())
            ->queueWidget()
            ->orderByDesc('id')
            ->get();
    }

    /** Whether to keep polling. */
    #[Computed]
    public function hasActiveJobs(): bool
    {
        return $this->queue->contains(fn (DownloadJob $job): bool => ! $job->status->isTerminal());
    }

    /** The queue row's "×". */
    public function dismiss(int $id): void
    {
        $job = $this->job($id);

        if ($job->status->isTerminal()) {
            $this->authorize('hide', $job);
            $job->hide();
        } else {
            $this->authorize('cancel', $job);

            /*
             * A queued job has no worker to notice a cancel request, so it is
             * settled here and the worker checks the status when it eventually
             * starts. A running one can only be asked.
             */
            $job->status->isActive() ? $job->requestCancel() : $job->markCancelled();
        }

        $this->refreshRows();
    }

    // -------------------------------------------------------- recent activity

    /**
     * Users whose activity an admin may preview.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function previewUsers(): Collection
    {
        if (! auth()->user()->can('preview-other-users')) {
            return collect();
        }

        return User::query()->orderBy('name')->get();
    }

    #[Computed]
    public function previewUser(): User
    {
        if ($this->previewUserId === null) {
            return auth()->user();
        }

        // Re-resolved from the authoritative list, so a hand-edited id in the
        // payload cannot select a user an admin would not have been offered.
        return $this->previewUsers->firstWhere('id', $this->previewUserId) ?? auth()->user();
    }

    public function preview(?int $userId): void
    {
        $this->authorize('preview-other-users');

        $this->previewUserId = $userId;

        $this->refreshRows();
    }

    /**
     * What landed, for the previewed user, in folders that user can still see.
     *
     * @return Collection<int, DownloadJob>
     */
    #[Computed]
    public function recent(): Collection
    {
        $user = $this->previewUser;
        $access = $user->folderAccess();

        $query = DownloadJob::query()
            ->where('user_id', $user->getKey())
            ->completed()
            ->orderByDesc('finished_at');

        // Narrowed in SQL so the limit applies to rows this user can actually see.
        // Filtering after the limit returned a short table - or an empty one - for
        // anyone whose recent downloads were mostly in folders they cannot reach.
        if (! $access->allowsAll()) {
            $query->whereIn('folder', $access->folders);
        }

        return $query
            ->limit((int) config('minizo.downloads.recent_limit', 25))
            ->get()
            // Kept as a backstop: allows() compares case-insensitively, which a
            // whereIn cannot promise across collations.
            ->filter(fn (DownloadJob $job): bool => $access->allows($job->folder))
            ->values();
    }

    // ---------------------------------------------------------------- internals

    private function job(int $id): DownloadJob
    {
        $job = DownloadJob::find($id);

        // 404 rather than an exception: the row may simply have been pruned.
        abort_if($job === null, 404);

        return $job;
    }

    private function refreshRows(): void
    {
        unset($this->queue, $this->hasActiveJobs, $this->recent, $this->previewUser);
    }
}; ?>

{{-- One root element and no layout wrapper: Livewire applies layouts::app
     itself, and the heading comes from config('minizo.pages'). --}}
<div class="space-y-7" @if ($this->hasActiveJobs) wire:poll.3s @endif>

    {{-- New download ---------------------------------------------------------- --}}
    <x-ui.section-card :label="__('New download')">
        @if ($this->canDownload)
            <form wire:submit="queueDownload" class="space-y-3">
                <div class="flex flex-wrap items-start gap-2.5">
                    <div class="min-w-[280px] flex-1">
                        {{-- No visible label: the design's row is placeholder-only, so the
                                                     accessible name comes from aria-label. --}}
                        <flux:input
                            wire:model="url"
                            type="url"
                            :placeholder="__('https://music.youtube.com/watch?v=xxxxxxxxxxx')"
                            :aria-label="__('Track URL')"
                        />
                    </div>

                    {{-- Destination. A locked user sees where it is going and cannot change
                                             it, which is more honest than a disabled select. --}}
                    @if ($this->folderLocked)
                        <div class="flex h-[42px] items-center gap-2">
                            <flux:icon name="lock-closed" class="text-ink-faint size-3.5" />
                            <x-ui.mono variant="chip">{{ $this->destinations[0] ?? __('unset') }}</x-ui.mono>
                        </div>
                    @elseif ($this->destinations !== [])
                        <flux:select wire:model="folder" class="w-auto min-w-[150px]">
                            @foreach ($this->destinations as $name)
                                <flux:select.option :value="$name">{{ $name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif

    {{-- No format select. AudioFormat has one case, so the "more than one format"
         condition this used to sit behind could never be true, and its wire:model
         pointed at a property that does not exist - it would have thrown the moment a
         second format made it render. queueDownload passes no format either, so
         DownloadQueue::resolveFormat applies the user's lock or the default. Bring the
         select back with the property when there is a second format to choose. --}}

                    <flux:button
                        variant="primary"
                        type="submit"
                        :disabled="$this->destinations === []"
                        class="disabled:pointer-events-none disabled:opacity-40"
                    >{{ count($this->formats) === 1 ? __('Download as') . ' ' . $this->formats[0]->label() : __('Download') }}</flux:button>
                </div>

                @error('url')
                    <p class="text-danger text-xs">{{ $message }}</p>
                @enderror

                @error('folder')
                    <p class="text-danger text-xs">{{ $message }}</p>
                @enderror

                @if ($this->destinations === [])
                    <p class="text-warning text-xs">
                        {{ __('You have no folder to download into. Ask an administrator for folder access.') }}
                    </p>
                @endif

                {{-- The design's copy, verbatim. --}}
                <p class="text-ink-faint text-2xs leading-relaxed">
                    {{ __('Downloading copyrighted content without authorization is illegal. This project is for educational purposes only — ensure you have the right to download and use the content.') }}
                </p>
            </form>
        @else
            <x-ui.empty-state
                icon="lock-closed"
                :message="__('You do not have permission to queue downloads. An administrator can grant “Use downloader”.')"
            />
        @endif
    </x-ui.section-card>

    {{-- Queue ----------------------------------------------------------------- --}}
    <section>
        <div class="mb-3 flex items-baseline gap-2.5">
            <x-ui.section-label tone="muted">{{ __('Queue') }}</x-ui.section-label>

            {{-- Plain text rather than the accent count badge, which the design keeps
                             for figures that matter. --}}
            <span class="text-ink-faint text-2xs">
                {{ trans_choice(':count item|:count items', $this->queue->count(), ['count' => $this->queue->count()]) }}
            </span>
        </div>

        @if ($this->queue->isEmpty())
            <div class="bg-surface border-border rounded-2xl border">
                <x-ui.empty-state :message="__('Nothing queued.')" />
            </div>
        @else
            <div class="flex flex-col gap-2">
                @foreach ($this->queue as $job)
                    <div
                        wire:key="queue-{{ $job->id }}"
                        class="bg-surface border-border flex items-center gap-3.5 rounded-xl border px-4 py-3.5"
                    >
                        <x-ui.tile :name="$job->displayTitle()" size="xl" />

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2.5">
                                <span class="truncate text-sm font-bold">{{ $job->displayTitle() }}</span>

                                {{-- Lowercase, as the design has it: "flac". --}}
                                <x-ui.mono variant="chip" class="shrink-0">{{ $job->format->value }}</x-ui.mono>

                                <span class="text-ink-faint shrink-0 text-xs">&rarr; {{ $job->folder }}</span>
                            </div>

                            <div class="mt-2 flex items-center gap-2.5">
                                <x-ui.progress-bar
                                    :percent="$job->progress_percent"
                                    :status="$job->status"
                                    class="flex-1"
                                />

                                {{-- min-width keeps the bar from twitching as the percentage
                                                                     gains and loses digits. The tone class comes from
                                                                     the literal map above, never from "text-{$tone}":
                                                                     a class assembled in PHP is never seen by
                                                                     Tailwind's scanner. --}}
                                <x-ui.mono
                                    class="min-w-[88px] shrink-0 justify-end text-end {{ $job->status->textClass() }}"
                                    size="text-xs"
                                >{{ $job->statusLabel() }}</x-ui.mono>
                            </div>

                            @if ($job->error)
                                <p class="text-danger mt-1.5 truncate text-2xs">{{ $job->error }}</p>
                            @endif
                        </div>

                        <x-ui.icon-button
                            icon="x-mark"
                            size="sm"
                            :label="$job->isCancellable() ? __('Cancel') : __('Dismiss')"
                            wire:click="dismiss({{ $job->id }})"
                            wire:loading.attr="disabled"
                            wire:target="dismiss({{ $job->id }})"
                        />
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    {{-- Recent activity ------------------------------------------------------- --}}
    <section>
        <div class="mb-3 flex flex-wrap items-center gap-2.5">
            <x-ui.section-label tone="muted">{{ __('Recent activity') }}</x-ui.section-label>

            @if ($this->previewUsers->isNotEmpty())
                @foreach ($this->previewUsers as $candidate)
                    <x-ui.pill
                        as="button"
                        wire:key="preview-{{ $candidate->id }}"
                        :selected="$this->previewUser->is($candidate)"
                        wire:click="preview({{ $candidate->id }})"
                        class="ps-1"
                    >
                        <x-ui.tile :name="$candidate->name" size="xs" round />
                        {{ $candidate->name }}
                    </x-ui.pill>
                @endforeach

                <span class="text-ink-faint text-2xs font-medium">
                    {{ __('only folders this user can access') }}
                </span>
            @endif
        </div>

        <x-ui.data-table cols="160px 1fr 110px 100px">
            <x-ui.data-table.head>
                <x-ui.data-table.column>{{ __('Artist') }}</x-ui.data-table.column>
                <x-ui.data-table.column>{{ __('Track') }}</x-ui.data-table.column>
                <x-ui.data-table.column>{{ __('Folder') }}</x-ui.data-table.column>
                <x-ui.data-table.column align="end">{{ __('When') }}</x-ui.data-table.column>
            </x-ui.data-table.head>

            @forelse ($this->recent as $job)
                <x-ui.data-table.row
                    wire:key="recent-{{ $job->id }}"
                    :last="$loop->last"
                >
                    <x-ui.data-table.cell class="font-semibold">
                        <x-ui.tile :name="$job->artist ?? $job->folder" size="sm" />
                        {{ $job->artist ?? __('Unknown artist') }}
                    </x-ui.data-table.cell>

                    <x-ui.data-table.cell class="text-ink-secondary">
                        {{ $job->title ?? $job->filename ?? $job->url }}
                    </x-ui.data-table.cell>

                    <x-ui.data-table.cell>
                        {{-- The chip links to the folder, which is the question a finished
                                                     download raises. --}}
                        <a href="{{ route('files', $job->folder) }}" wire:navigate>
                            <x-ui.mono variant="chip">{{ $job->folder }}</x-ui.mono>
                        </a>
                    </x-ui.data-table.cell>

                    <x-ui.data-table.cell align="end">
                        <x-ui.mono :title="$job->finished_at?->toDayDateTimeString()">
                            {{ $job->finished_at?->diffForHumans(short: true) }}
                        </x-ui.mono>
                    </x-ui.data-table.cell>
                </x-ui.data-table.row>
            @empty
                <x-ui.empty-state :message="__('No activity in folders this user can access.')" />
            @endforelse
        </x-ui.data-table>
    </section>
</div>
