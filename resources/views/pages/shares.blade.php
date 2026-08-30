<?php

use App\Models\Share;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Share links - "one place to audit and kill every public link created from the library." */
new class extends Component
{
    /** Whose links to show. Null is "All users". */
    #[Url(as: 'by', except: null)]
    public ?int $ownerId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Share::class);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function owners(): Collection
    {
        // Admin-only. A non-admin's list is already one person's, so a "Links by" row
        // would offer a choice between "me" and "me".
        if (! auth()->user()->can('viewAnyOwner', Share::class)) {
            return collect();
        }

        // Only accounts that have actually shared something. A pill per empty account
        // would be a row of dead ends.
        //
        // A subquery rather than pluck(): pluck runs its own query and sends every
        // owner id back as a literal IN list.
        return User::query()
            ->whereIn('id', Share::query()->select('user_id'))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Share>
     */
    #[Computed]
    public function links(): Collection
    {
        return Share::query()
            ->with('user')
            // Ownership first, and never from $ownerId - that is a client-writable filter,
            // not an authorization. See Share::scopeVisibleTo.
            ->visibleTo(auth()->user())
            ->when($this->ownerId !== null, fn ($query) => $query->where('user_id', $this->ownerId))
            /*
             * Live first, then newest. Dead rows are kept for the retention window and
             * render at half opacity, but they must not push a live link off the top of
             * the screen - the live ones are the ones someone can still act on.
             */
            ->orderByRaw('CASE WHEN revoked_at IS NULL AND expires_at > ? THEN 0 ELSE 1 END', [now()])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * The green "N active links" badge.
     *
     * Counts what this viewer can see rather than the whole instance, or it would report
     * a number the table below cannot account for. It still ignores the owner FILTER: it
     * answers "how much of mine is published right now", not "how much is on screen".
     */
    #[Computed]
    public function activeCount(): int
    {
        return Share::query()->visibleTo(auth()->user())->live()->count();
    }

    public function filterBy(?int $ownerId): void
    {
        // Admin-only, and authorized rather than just hidden: the pills are gone from a
        // non-admin's page, but the action is still callable over the wire.
        $this->authorize('viewAnyOwner', Share::class);

        $this->ownerId = $ownerId;

        unset($this->links);
    }

    public function revoke(int $id): void
    {
        $share = $this->share($id);

        $this->authorize('revoke', $share);

        $share->revoke();

        $this->refresh();

        Flux::toast(
            variant: 'success',
            text: __('":name" is no longer reachable.', ['name' => $share->name]),
        );
    }

    /** Remove a dead row from the audit list. */
    public function forget(int $id): void
    {
        $share = $this->share($id);

        $this->authorize('delete', $share);

        abort_if($share->isLive(), 403);

        $share->delete();

        $this->refresh();
    }

    #[On('shares-updated')]
    public function refresh(): void
    {
        unset($this->links, $this->owners, $this->activeCount);
    }

    private function share(int $id): Share
    {
        $share = Share::find($id);

        // 404 rather than an exception: the row may have been pruned between render and
        // click.
        abort_if($share === null, 404);

        return $share;
    }
}; ?>

<div class="space-y-4">
    {{-- LINKS BY ------------------------------------------------------------- --}}
    <div class="flex flex-wrap items-center gap-2">
        {{-- Admin-only: a non-admin's list is already just their own links, so the filter
             would choose between "me" and "me". The count badge below stays for everyone. --}}
        @if ($this->owners->isNotEmpty())
            <x-ui.section-label variant="table" tone="faint">{{ __('Links by') }}</x-ui.section-label>

            {{-- "All users" first, with the design's asterisk avatar. --}}
            <x-ui.pill
                as="button"
                :selected="$ownerId === null"
                wire:click="filterBy(null)"
                class="ps-1"
            >
                <span class="bg-line/14 text-ink-muted inline-flex size-5 items-center justify-center rounded-full text-2xs font-extrabold">
                    &lowast;
                </span>
                {{ __('All users') }}
            </x-ui.pill>

            @foreach ($this->owners as $owner)
                <x-ui.pill
                    as="button"
                    wire:key="owner-{{ $owner->id }}"
                    :selected="$ownerId === $owner->id"
                    wire:click="filterBy({{ $owner->id }})"
                    class="ps-1"
                >
                    <x-ui.tile :name="$owner->name" size="xs" round />
                    {{ $owner->name }}
                </x-ui.pill>
            @endforeach
        @endif

        <x-ui.mono
            variant="chip"
            class="bg-success-soft! text-success! ms-auto"
        >{{ trans_choice(':count active link|:count active links', $this->activeCount, ['count' => $this->activeCount]) }}</x-ui.mono>
    </div>

    {{-- The table ----------------------------------------------------------- --}}
    <x-ui.data-table cols="1fr 130px 96px 110px 172px">
        <x-ui.data-table.head>
            <x-ui.data-table.column>{{ __('Shared item') }}</x-ui.data-table.column>
            <x-ui.data-table.column>{{ __('Owner') }}</x-ui.data-table.column>
            <x-ui.data-table.column>{{ __('Created') }}</x-ui.data-table.column>
            <x-ui.data-table.column>{{ __('Expires') }}</x-ui.data-table.column>
            <x-ui.data-table.column align="end">
                <span class="sr-only">{{ __('Actions') }}</span>
            </x-ui.data-table.column>
        </x-ui.data-table.head>

        @forelse ($this->links as $link)
            @php $expiry = $link->expiry(); @endphp

            <x-ui.data-table.row
                wire:key="share-{{ $link->id }}"
                :last="$loop->last"
                @class(['opacity-50' => $expiry->dead])
            >
                {{-- SHARED ITEM: type badge + name, with the bare URL beneath. --}}
                <x-ui.data-table.cell :truncate="false" class="flex-col! items-start! gap-[3px]">
                    <div class="flex min-w-0 items-center gap-2">
                        <x-ui.mono
                            :variant="$link->type->isCollection() ? 'accent' : 'chip'"
                            size="text-[9.5px]"
                            class="shrink-0"
                        >{{ strtoupper($link->type->label()) }}</x-ui.mono>

                        <span class="truncate font-bold">{{ $link->name }}</span>
                    </div>

                    <x-ui.mono size="text-3xs" class="text-ink-faint max-w-full truncate">
                        {{ $link->displayUrl() }}
                    </x-ui.mono>
                </x-ui.data-table.cell>

                <x-ui.data-table.cell>
                    <x-ui.tile :name="$link->user->name" size="sm" round />
                    <span class="truncate text-xs font-semibold">{{ $link->user->name }}</span>
                </x-ui.data-table.cell>

                <x-ui.data-table.cell>
                    <x-ui.mono :title="$link->created_at?->toDayDateTimeString()">
                        {{ $link->created_at?->diffForHumans(short: true) }}
                    </x-ui.mono>
                </x-ui.data-table.cell>

                <x-ui.data-table.cell>
                    <x-ui.expiry-pill :expiry="$expiry" />
                </x-ui.data-table.cell>

                {{-- Open · Copy · Expire now on a live row; Remove on a dead one, whose
                                     Open and Copy would hand out a URL that 404s. --}}
                <x-ui.data-table.cell align="end" :truncate="false" class="gap-1.5!">
                    @if (! $expiry->dead)
                        <flux:button
                            size="xs"
                            variant="outline"
                            href="{{ $link->url() }}"
                            target="_blank"
                        >{{ __('Open') }}</flux:button>

                        <x-ui.copy-button :value="$link->url()" variant="outline" class="text-3xs!" />

                        @can('revoke', $link)
                            <flux:button
                                size="xs"
                                variant="danger"
                                wire:click="revoke({{ $link->id }})"
                                wire:confirm="{{ __('Expire this link now? Anyone holding the URL loses access immediately.') }}"
                            >{{ __('Expire now') }}</flux:button>
                        @endcan
                    @else
                        @can('delete', $link)
                            <flux:button
                                size="xs"
                                variant="outline"
                                wire:click="forget({{ $link->id }})"
                            >{{ __('Remove') }}</flux:button>
                        @endcan
                    @endif
                </x-ui.data-table.cell>
            </x-ui.data-table.row>
        @empty
            <x-ui.empty-state
                :message="__('No public links yet — share a track or folder to create one.')"
            />
        @endforelse
    </x-ui.data-table>

    {{-- The design's footer note, and the only place the retention rule is stated
             to the person who has to reason about it. --}}
    <p class="text-ink-faint text-2xs leading-relaxed">
        {{ __('Expiring a link kills it immediately for anyone holding the URL. Links also stop working on their own once the expiry window passes — dead rows are kept for :days days so you can see what was shared.', ['days' => config('minizo.shares.retention_days', 30)]) }}
    </p>
</div>
