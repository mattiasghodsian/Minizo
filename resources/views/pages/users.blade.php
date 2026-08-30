<?php

use App\Models\User;
use App\Support\Sharing;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/** The Users screen: every account, and the instance-wide switch that sits above them. */
new class extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function accounts(): Collection
    {
        // Admins first, then alphabetical - the people who can change things are the ones
        // an admin is usually looking for.
        return User::query()
            ->orderByRaw("CASE WHEN role = 'admin' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function sharingEnabled(): bool
    {
        return Sharing::enabled();
    }

    public function toggleSharing(): void
    {
        $this->authorize('toggle-sharing');

        $enabled = ! Sharing::enabled();

        Sharing::set($enabled);

        unset($this->sharingEnabled, $this->accounts);

        Flux::toast(
            variant: 'success',
            text: $enabled
                ? __('Public sharing is on.')
                : __('Public sharing is off. Links that already exist keep working until they expire or are revoked.'),
        );
    }

    #[On('users-updated')]
    public function refreshAccounts(): void
    {
        unset($this->accounts);
    }
}; ?>

<div class="space-y-4">
    {{-- ACCOUNTS -------------------------------------------------------------- --}}
    <div class="flex items-center gap-3">
        <x-ui.section-label tone="muted">{{ __('Accounts') }}</x-ui.section-label>

        <x-ui.mono variant="accent">
            {{ trans_choice(':count account|:count accounts', $this->accounts->count(), ['count' => $this->accounts->count()]) }}
        </x-ui.mono>

        <flux:button
            size="sm"
            variant="primary"
            icon="plus"
            class="ms-auto"
            x-on:click="Livewire.dispatch('user-create')"
        >{{ __('Add user') }}</flux:button>
    </div>

    {{-- The instance-wide sharing switch. Stated plainly on the card because
            people assume it works the other way round: turning it off stops new links and
            dims every Share control, but does not revoke what is already published. --}}
    <x-ui.section-card class="p-4.5!">
        <x-ui.toggle-row
            :label="__('Public share links')"
            :description="__('Users with the Share permission can create expiring public links to tracks and folders. Turning this off does not revoke links that already exist.')"
        >
            <flux:switch
                :checked="$this->sharingEnabled"
                wire:click="toggleSharing"
            />
        </x-ui.toggle-row>
    </x-ui.section-card>

    {{-- The accounts table -------------------------------------------------- --}}
    <x-ui.data-table cols="1.3fr 90px 120px 1.2fr 100px 90px">
        <x-ui.data-table.head>
            <x-ui.data-table.column>{{ __('User') }}</x-ui.data-table.column>
            <x-ui.data-table.column>{{ __('Role') }}</x-ui.data-table.column>
            <x-ui.data-table.column>{{ __('Folders') }}</x-ui.data-table.column>
            <x-ui.data-table.column>{{ __('Permissions') }}</x-ui.data-table.column>
            <x-ui.data-table.column>{{ __('Status') }}</x-ui.data-table.column>
            <x-ui.data-table.column align="end">
                <span class="sr-only">{{ __('Actions') }}</span>
            </x-ui.data-table.column>
        </x-ui.data-table.head>

        @foreach ($this->accounts as $account)
            @php
                // granted(), not effective(): this column reports what an admin gave
                // the account, and must not change when the instance switch flips.
                $permissions = \App\Support\Permissions::forUser($account, sharingEnabled: true);
            @endphp

            <x-ui.data-table.row wire:key="user-{{ $account->id }}" :last="$loop->last">
                <x-ui.data-table.cell :truncate="false">
                    <x-ui.tile :name="$account->name" size="lg" />

                    <span class="min-w-0">
                        <span class="block truncate font-bold">{{ $account->name }}</span>
                        <span class="text-ink-muted block truncate text-xs">{{ $account->email }}</span>
                    </span>
                </x-ui.data-table.cell>

                <x-ui.data-table.cell>
                    <x-ui.pill :tone="$account->isAdmin() ? 'accent' : 'neutral'">
                        {{ $account->role->label() }}
                    </x-ui.pill>
                </x-ui.data-table.cell>

                <x-ui.data-table.cell class="text-ink-secondary text-xs! font-semibold">
                    {{ $account->folderAccess()->summaryLabel() }}
                </x-ui.data-table.cell>

                <x-ui.data-table.cell class="text-ink-muted text-xs!" :title="$permissions->summaryLabel()">
                    {{ $permissions->summaryLabel() }}
                </x-ui.data-table.cell>

                <x-ui.data-table.cell>
                    {{-- A dot plus a word, not a coloured word alone: colour is the fastest
                                             signal, the word is the one that survives colour blindness. --}}
                    <span @class([
                        'flex items-center gap-1.5 text-xs font-semibold',
                        'text-success' => $account->is_active,
                        'text-danger' => ! $account->is_active,
                    ])>
                        <span @class([
                            'size-[7px] rounded-full',
                            'bg-success' => $account->is_active,
                            'bg-danger' => ! $account->is_active,
                        ]) aria-hidden="true"></span>

                        {{ $account->is_active ? __('Active') : __('Disabled') }}
                    </span>
                </x-ui.data-table.cell>

                <x-ui.data-table.cell align="end">
                    <flux:button
                        size="xs"
                        variant="outline"
                        x-on:click="Livewire.dispatch('user-manage', { user: {{ $account->id }} })"
                    >{{ __('Manage') }}</flux:button>
                </x-ui.data-table.cell>
            </x-ui.data-table.row>
        @endforeach
    </x-ui.data-table>

    {{-- The one place an account's role, access and permissions are edited. --}}
    <livewire:pages::users.manage />
</div>
