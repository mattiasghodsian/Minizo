<?php

use App\Exceptions\TidalException;
use App\Models\Artist;
use App\Models\User;
use App\Services\Tidal\FeedService;
use App\Support\TidalArtist;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/** The Feed: follow artists on Tidal, see what they release. */
new class extends Component
{
    public string $query = '';

    public bool $searched = false;

    /** @var array<int, array<string, mixed>> */
    public array $results = [];

    /** Whose feed is shown. Admin-only; null means the viewer's own. */
    public ?int $previewUserId = null;

    /**
     * Release ids that were new on THIS page load.
     *
     * @var array<int, int>
     */
    public array $newReleaseIds = [];

    public function mount(FeedService $feed): void
    {
        // markViewed reads what was new, THEN stamps. Order matters; see the service.
        //
        // Handed the computed feed rather than letting the service load its own, so
        // the page runs that query once instead of once here and once at render.
        $this->newReleaseIds = $feed->markViewed($this->subject(), $this->feed);
    }

    #[Computed]
    public function configured(): bool
    {
        return app(FeedService::class)->configured();
    }

    // ------------------------------------------------------------- admin preview

    /**
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

        // Re-resolved from the authoritative list, so a hand-edited id cannot select
        // someone an admin was never offered.
        return $this->previewUsers->firstWhere('id', $this->previewUserId) ?? auth()->user();
    }

    public function preview(?int $userId): void
    {
        $this->authorize('preview-other-users');

        $this->previewUserId = $userId;

        /*
         * Previewing does NOT mark the other person's feed as viewed. An admin looking at
         * someone's feed would otherwise clear their new-release badges for them, which is
         * exactly the kind of side effect a read-only preview must not have.
         */
        $this->newReleaseIds = [];

        unset($this->feed, $this->previewUser, $this->followedIds);
    }

    // -------------------------------------------------------------------- search

    public function search(FeedService $feed): void
    {
        if (trim($this->query) === '') {
            $this->reset('results', 'searched');

            return;
        }

        try {
            $artists = $feed->search(auth()->user(), $this->query);
        } catch (TidalException $e) {
            $this->addError('query', $e->getMessage());

            return;
        }

        $this->results = array_map(fn (TidalArtist $a): array => $a->toArray(), $artists);
        $this->searched = true;

        unset($this->resultArtists);
    }

    public function clearSearch(): void
    {
        $this->reset('query', 'results', 'searched');

        unset($this->resultArtists);
    }

    /**
     * @return array<int, TidalArtist>
     */
    #[Computed]
    public function resultArtists(): array
    {
        return array_map(fn (array $row): TidalArtist => TidalArtist::fromArray($row), $this->results);
    }

    /**
     * The provider ids the viewer already follows, so a result can say "Following".
     *
     * @return array<int, string>
     */
    #[Computed]
    public function followedIds(): array
    {
        return $this->previewUser->followedArtists()->pluck('provider_id')->all();
    }

    // ------------------------------------------------------------------- follow

    public function follow(FeedService $feed, string $providerId): void
    {
        /*
         * Following is always for YOURSELF, even while previewing someone else. An admin
         * inspecting a colleague's feed must not be able to subscribe them to things.
         */
        // The id is checked against the results the client is holding, but the artist is
        // then re-fetched from Tidal rather than read out of them: $results is a public
        // property, so its contents are whatever the browser sent back, and a follow
        // writes to the shared artists table.
        abort_if(collect($this->resultArtists)->firstWhere('providerId', $providerId) === null, 404);

        try {
            $artist = $feed->followById(auth()->user(), $providerId);
        } catch (TidalException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        unset($this->feed, $this->followedIds);

        Flux::toast(
            variant: 'success',
            text: __('Following :name. New releases will appear here shortly.', ['name' => $artist->name]),
        );
    }

    public function unfollow(FeedService $feed, int $artistId): void
    {
        $artist = Artist::find($artistId);

        abort_if($artist === null, 404);

        $feed->unfollow(auth()->user(), $artist);

        unset($this->feed, $this->followedIds);

        Flux::toast(variant: 'success', text: __('Unfollowed :name.', ['name' => $artist->name]));
    }

    // --------------------------------------------------------------------- feed

    /**
     * @return Collection<int, Artist>
     */
    #[Computed]
    public function feed(): Collection
    {
        return app(FeedService::class)->feedFor($this->subject());
    }

    /** Whether a release should carry the "new" marker. */
    public function isNew(int $releaseId): bool
    {
        return in_array($releaseId, $this->newReleaseIds, true);
    }

    private function subject(): User
    {
        return $this->previewUserId === null ? auth()->user() : $this->previewUser;
    }
}; ?>

<div class="space-y-6.5">
    {{-- FEED FOR (admin only) ------------------------------------------------ --}}
    @if ($this->previewUsers->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2">
            <x-ui.section-label variant="table" tone="faint">{{ __('Feed for') }}</x-ui.section-label>

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
                {{ __('admin preview — each user only sees their own feed') }}
            </span>
        </div>
    @endif

    {{-- Tidal unavailable ---------------------------------------------------- --}}
    @if (! $this->configured)
        <x-ui.section-card :label="__('Feed unavailable')">
            <x-ui.empty-state
                icon="key"
                :message="__('Tidal is not configured, so artists cannot be searched or followed. Add TIDAL_CLIENT_ID and TIDAL_CLIENT_SECRET to your .env — register an application at developer.tidal.com.')"
            />
        </x-ui.section-card>
    @else
        {{-- ADD ARTIST ------------------------------------------------------- --}}
        <x-ui.section-card class="p-4.5!">
            <form wire:submit="search" class="flex flex-col gap-3.5">
                <div class="flex items-center gap-2.5">
                    <x-ui.section-label class="shrink-0">{{ __('Add artist') }}</x-ui.section-label>

                    <div class="flex-1">
                        <flux:input
                            wire:model="query"
                            :placeholder="__('Search Tidal for an artist…')"
                            :aria-label="__('Artist name')"
                        />
                    </div>

                    {{-- A cold search is a token fetch plus a catalogue call. Disabling while it
                                             runs also stops a second click spending another of the user's
                                             per-minute search allowance on the same query. --}}
                    <flux:button
                        variant="primary"
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="search"
                    >
                        <span wire:loading.remove wire:target="search">{{ __('Search') }}</span>
                        <span wire:loading wire:target="search">{{ __('Searching…') }}</span>
                    </flux:button>

                    @if ($searched)
                        <flux:button variant="outline" type="button" wire:click="clearSearch">
                            {{ __('Clear') }}
                        </flux:button>
                    @endif
                </div>

                @error('query')
                    <p class="text-danger text-xs">{{ $message }}</p>
                @enderror

                @if ($searched)
                    <p class="text-ink-faint text-2xs font-semibold">
                        {{ trans_choice(
                            ':count artist on Tidal matching “:q” — click to follow|:count artists on Tidal matching “:q” — click to follow',
                            count($this->resultArtists),
                            ['count' => count($this->resultArtists), 'q' => trim($query)],
                        ) }}
                    </p>

                    @if ($this->resultArtists !== [])
                        <div class="grid gap-2.5 [grid-template-columns:repeat(auto-fill,minmax(240px,1fr))]">
                            @foreach ($this->resultArtists as $artist)
                                @php $following = in_array($artist->providerId, $this->followedIds, true); @endphp

                                <button
                                    type="button"
                                    wire:key="result-{{ $artist->providerId }}"
                                    wire:click="follow({{ \Illuminate\Support\Js::from($artist->providerId) }})"
                                    @disabled($following)
                                    @class([
                                        'bg-sidebar border-border flex items-center gap-3 rounded-xl border p-2.5 text-start transition-colors',
                                        'hover:border-line/30 hover:bg-surface-raised cursor-pointer' => ! $following,
                                        'cursor-default' => $following,
                                    ])
                                >
                                    {{-- Real artist photo where Tidal has one, generated art otherwise.
                                                                             coverKnown but not eager: twenty results is
                                                                             twenty photos. An expired CDN link still
                                                                             works out, since the tile's onerror leaves
                                                                             the gradient. --}}
                                    <x-ui.tile
                                        :name="$artist->name"
                                        size="xl"
                                        round
                                        :cover="$artist->imageUrl"
                                        :cover-known="$artist->imageUrl !== null"
                                        :eager="false"
                                    />

                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-xs leading-tight font-bold">{{ $artist->name }}</span>

                                        @if ($artist->popularity !== null)
                                            <span class="text-ink-muted mt-0.5 block text-2xs">
                                                {{ __('popularity :n', ['n' => $artist->popularity]) }}
                                            </span>
                                        @endif
                                    </span>

                                    <x-ui.pill :tone="$following ? 'accent' : 'neutral'" class="shrink-0">
                                        {{ $following ? __('✓ Following') : __('+ Follow') }}
                                    </x-ui.pill>
                                </button>
                            @endforeach
                        </div>
                    @endif
                @endif
            </form>
        </x-ui.section-card>
    @endif

    {{-- Followed artists ---------------------------------------------------- --}}
    @forelse ($this->feed as $artist)
        <section wire:key="artist-{{ $artist->id }}">
            <div class="mb-3 flex items-center gap-3">
                {{-- 34px: between the lg (30) and xl (38) steps, so the box is overridden
                                     rather than adding a one-off size to the shared component. --}}
                {{-- Not eager, despite the cover being known: a feed of twenty followed
                     artists is twenty Tidal CDN photos, most of them below the fold. --}}
                <x-ui.tile
                    :name="$artist->name"
                    size="lg"
                    round
                    :cover="$artist->image_url"
                    :cover-known="$artist->image_url !== null"
                    :eager="false"
                    class="size-[34px]!"
                />

                <h2 class="text-md font-extrabold">{{ $artist->name }}</h2>

                <span class="text-ink-faint text-2xs font-semibold">
                    {{ trans_choice(':count recent release|:count recent releases', $artist->releases->count(), ['count' => $artist->releases->count()]) }}
                </span>

                {{-- Only on your own feed: an admin previewing someone else must not
                                     unfollow on their behalf. --}}
                @if ($this->previewUser->is(auth()->user()))
                    <flux:button
                        size="xs"
                        variant="ghost"
                        class="hover:text-danger! ms-auto"
                        wire:click="unfollow({{ $artist->id }})"
                        wire:confirm="{{ __('Unfollow :name? Their releases stop appearing in your feed.', ['name' => $artist->name]) }}"
                    >{{ __('Unfollow') }}</flux:button>
                @endif
            </div>

            @if ($artist->releases->isEmpty())
                {{-- Following dispatches a queued job, so there is a real gap before the
                                     releases arrive. Saying so beats an apparently-empty artist. --}}
                <x-ui.empty-state
                    dashed
                    :message="__('No releases in the last :days days, or the first sync has not finished yet.', ['days' => config('minizo.feed.backfill_days')])"
                />
            @else
                <div class="grid gap-2.5 [grid-template-columns:repeat(auto-fill,minmax(280px,1fr))]">
                    @foreach ($artist->releases as $release)
                        <div
                            wire:key="release-{{ $release->id }}"
                            {{-- ps-20 rather than px-3.5: the clear zone the artwork needs before
                                                             the title starts. --}}
                            class="bg-surface border-border hover:border-line/25 hover:bg-surface-raised relative flex items-center gap-3 overflow-hidden rounded-xl border py-3 pe-3.5 ps-20 transition-colors"
                        >
                            {{-- The cover as the card's own left edge. Unlike the Files rows this
                                                             card holds no dropdown, so it can clip its own
                                                             overflow and the artwork needs no rounding. --}}
                            <x-ui.row-artwork
                                :name="$release->title"
                                :cover="$release->cover_url"
                                width="sm"
                            />

                            <div class="relative min-w-0 flex-1">
                                <div class="flex items-start gap-2">
                                    <span class="line-clamp-2 text-2xs leading-snug font-bold">
                                        {{ $release->title }}
                                    </span>

                                    {{-- The design's "new" marker. Read from the list captured at
                                                                             mount, because loading this page clears it. --}}
                                    @if ($this->isNew($release->id))
                                        <x-ui.pill tone="accent" class="shrink-0 px-2 py-0.5">
                                            {{ __('New') }}
                                        </x-ui.pill>
                                    @endif
                                </div>

                                <div class="mt-1 flex items-center gap-2">
                                    @if ($release->release_type)
                                        <x-ui.mono variant="chip">{{ $release->release_type->label() }}</x-ui.mono>
                                    @endif

                                    <x-ui.mono :title="$release->dateLabel()">
                                        {{ $release->released_on?->diffForHumans(short: true) ?? '—' }}
                                    </x-ui.mono>
                                </div>

                                {{-- Two links doing different jobs: Tidal confirms the release
                                                                     exists, YouTube Music is where you go to get it.
                                                                     Search, copy the URL, paste it into Download. --}}
                                <div class="mt-1 flex flex-wrap items-center gap-3">
                                    @if ($release->link)
                                        <x-ui.external-link :href="$release->link" class="text-brand-text!">
                                            {{ __('Tidal') }}
                                        </x-ui.external-link>
                                    @endif

                                    <x-ui.external-link
                                        :href="$release->youtubeMusicUrl($artist->name)"
                                        class="text-brand-text!"
                                        :title="__('Search YouTube Music for :artist — :title', ['artist' => $artist->name, 'title' => $release->title])"
                                    >{{ __('YouTube Music') }}</x-ui.external-link>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    @empty
        <x-ui.empty-state
            dashed
            :message="$this->configured
                ? __('No artists followed yet — search Tidal above and follow the first one.')
                : __('No artists followed yet.')"
        />
    @endforelse
</div>
