<?php

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Support\UserSessions;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Actions\DeletePasskey;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/** Settings: one screen, a stack of cards in three groups. */
new class extends Component
{
    use PasswordValidationRules;
    use ProfileValidationRules;

    // ------------------------------------------------------------------- profile

    public string $name = '';

    public int $paginationSize = 50;

    // -------------------------------------------------------------------- password

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    // ------------------------------------------------------------------------ 2FA

    public bool $twoFactorEnabled = false;

    public bool $requiresConfirmation = false;

    // ------------------------------------------------------------------ passkeys

    /** @var array<int, array<string, mixed>> */
    #[Locked]
    public array $passkeys = [];

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $deletingPasskeyId = null;

    #[Locked]
    public string $deletingPasskeyName = '';

    public function mount(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $user = Auth::user();

        $this->name = $user->name;
        $this->paginationSize = $user->paginationSize();

        if ($this->canManageTwoFactor()) {
            /*
             * A secret with no confirmation is an abandoned setup - someone opened the modal,
             * got a QR code, and closed the tab. Left alone it would show as half-enabled
             * forever, so it is cleared on the way in.
             */
            if (Fortify::confirmsTwoFactorAuthentication() && is_null($user->two_factor_confirmed_at)) {
                $disableTwoFactorAuthentication($user);
            }

            $this->twoFactorEnabled = $user->hasEnabledTwoFactorAuthentication();
            $this->requiresConfirmation = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        if ($this->canManagePasskeys()) {
            $this->loadPasskeys();
        }
    }

    // ------------------------------------------------------------------- feature flags

    public function canManageTwoFactor(): bool
    {
        return Features::canManageTwoFactorAuthentication();
    }

    public function canManagePasskeys(): bool
    {
        return Features::canManagePasskeys();
    }

    /** Whether the sensitive cards are unlocked for this session. */
    #[Computed]
    public function passwordConfirmed(): bool
    {
        $confirmedAt = Session::get('auth.password_confirmed_at');

        if (! is_numeric($confirmedAt)) {
            return false;
        }

        return (time() - (int) $confirmedAt) < config('auth.password_timeout', 10800);
    }

    /** Whether a card needs confirmation before it will show its controls. */
    public function twoFactorLocked(): bool
    {
        return Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword')
            && ! $this->passwordConfirmed;
    }

    public function passkeysLocked(): bool
    {
        return Features::optionEnabled(Features::passkeys(), 'confirmPassword')
            && ! $this->passwordConfirmed;
    }

    /** Where the "Confirm password" button goes. */
    public function confirmPassword(): void
    {
        Session::put('url.intended', route('settings.edit'));

        $this->redirect(route('password.confirm'), navigate: false);
    }

    // --------------------------------------------------------------------- profile

    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        // Name and page size only. Email is absent from both the form and this rule set:
        // validating a field the form does not submit would let a crafted request change
        // it, which a read-only input alone does not prevent.
        $validated = $this->validate([
            'name' => $this->nameRules(),
            'paginationSize' => [
                'required',
                'integer',
                'min:'.config('minizo.pagination.min', 10),
                'max:'.config('minizo.pagination.max', 200),
            ],
        ]);

        $user->forceFill([
            'name' => $validated['name'],
            'pagination_size' => $validated['paginationSize'],
        ])->save();

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    // The starter kit's "email unverified, re-send the link" affordance is gone: User
    // does not implement MustVerifyEmail, the route is behind `verified`, and email is
    // read-only here. Enabling MustVerifyEmail routes to Fortify's own notice, which
    // carries its own re-send button.

    // -------------------------------------------------------------------- password

    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => $this->currentPasswordRules(),
                'password' => $this->passwordRules(),
            ]);
        } catch (ValidationException $e) {
            // Cleared on failure so a wrong current password does not leave the new one
            // sitting in the DOM for the next person at the keyboard.
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        $user = Auth::user();

        $user->update(['password' => $validated['password']]);

        /*
         * Every other session goes, and the remember token with it.
         *
         * Changing a password is the one thing someone does BECAUSE they think a session
         * is compromised, so leaving those sessions alive defeats the point. This one is
         * kept, or the user would be signed out by their own password change.
         */
        UserSessions::purge($user, session()->getId());

        $user->forceFill(['remember_token' => Str::random(60)])->save();

        $this->reset('current_password', 'password', 'password_confirmation');

        Flux::toast(variant: 'success', text: __('Password updated. Any other signed-in devices have been logged out.'));
    }

    // ------------------------------------------------------------------------ 2FA

    #[On('two-factor-enabled')]
    public function onTwoFactorEnabled(): void
    {
        $this->twoFactorEnabled = true;
    }

    public function disableTwoFactor(DisableTwoFactorAuthentication $disable): void
    {
        abort_if($this->twoFactorLocked(), 403);

        $disable(Auth::user());

        $this->twoFactorEnabled = false;

        Flux::toast(variant: 'success', text: __('Two-factor authentication disabled.'));
    }

    // ------------------------------------------------------------------- passkeys

    public function loadPasskeys(): void
    {
        $this->passkeys = Auth::user()->passkeys()
            ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
            ->latest()
            ->get()
            ->map(fn ($passkey): array => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'created_at_diff' => $passkey->created_at->diffForHumans(),
                'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
            ])
            ->all();
    }

    public function confirmDelete(int $passkeyId): void
    {
        abort_if($this->passkeysLocked(), 403);

        $passkey = Auth::user()->passkeys()->findOrFail($passkeyId);

        $this->deletingPasskeyId = $passkey->id;
        $this->deletingPasskeyName = $passkey->name;
        $this->showDeleteModal = true;
    }

    public function deletePasskey(DeletePasskey $deletePasskey): void
    {
        abort_if($this->passkeysLocked(), 403);

        if (! $this->deletingPasskeyId) {
            return;
        }

        // Scoped to the current user's own passkeys, so an id from the payload cannot reach
        // somebody else's credential.
        $passkey = Auth::user()->passkeys()->findOrFail($this->deletingPasskeyId);

        $deletePasskey(Auth::user(), $passkey);

        $this->closeDeleteModal();
        $this->loadPasskeys();
    }

    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deletingPasskeyId = null;
        $this->deletingPasskeyName = '';
    }

    /**
     * @return array<int, int>
     */
    #[Computed]
    public function paginationOptions(): array
    {
        return (array) config('minizo.pagination.options', [20, 50, 100, 200]);
    }
}; ?>

{{--
    Three groups, each with a standalone label: account, security, danger.

    One full-width card per row throughout, rather than the auto-fit/minmax grid this
    started as. That grid was written against the design's 1040px column and quietly
    became THREE columns once the layout widened, which stranded short cards beside a
    void and floated Delete account up to eye level. A single column cannot do that at
    any width.

    The forms inside are capped rather than stretched: a full-width card is the right
    unit for the page, but a 1100px-wide "Name" field is not.

    Appearance is not here: it is in the header, since it applies instantly and is worth
    reaching from any screen. See x-ui.appearance-switch.
--}}
<div class="flex flex-col gap-8">

    {{-- ACCOUNT ============================================================ --}}
    <section class="flex flex-col gap-3">
        <x-ui.section-label tone="muted">{{ __('Account') }}</x-ui.section-label>

        <div class="flex flex-col gap-5">
            <x-ui.section-card>
                <div class="mb-3.5 flex items-center gap-3">
                    <x-ui.tile :name="auth()->user()->name" size="2xl" />

                    <div class="min-w-0">
                        <div class="truncate text-sm font-extrabold">{{ auth()->user()->name }}</div>
                        <div class="text-ink-muted text-2xs">{{ auth()->user()->role->label() }}</div>
                    </div>
                </div>

                <x-ui.section-label>{{ __('Profile') }}</x-ui.section-label>

                <p class="text-ink-muted mt-2 mb-4.5 text-2xs leading-relaxed">
                    {{ __("Update your account's profile information.") }}
                </p>

                <form wire:submit="updateProfileInformation" class="flex max-w-xl flex-col gap-3.5">
                    <flux:input wire:model="name" :label="__('Name')" type="text" required autocomplete="name" />

                    {{-- A select rather than the prototype's free-text box: the column is
                                     user-editable and feeds a query LIMIT. --}}
                    <flux:select wire:model="paginationSize" :label="__('Pagination size')">
                        @foreach ($this->paginationOptions as $size)
                            <flux:select.option :value="$size">{{ $size }} {{ __('per page') }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <div>
                        {{-- Read-only, per the design, and not merely cosmetic. Minizo ships
                                            with no mailer and force-verifies new accounts, so a self-service
                                            change would null email_verified_at and strand the account behind
                                            a verification link that can never arrive. An admin can change it
                                            from the Users screen. --}}
                        <flux:input
                            :value="auth()->user()->email"
                            :label="__('Email')"
                            type="email"
                            readonly
                            disabled
                            icon:trailing="lock-closed"
                        />

                        <p class="text-ink-faint mt-1.5 text-3xs font-medium">
                            {{ __('Email is locked — contact an administrator to change it.') }}
                        </p>
                    </div>

                    <flux:button variant="primary" type="submit" class="mt-1 self-start" data-test="update-profile-button">
                        {{ __('Save') }}
                    </flux:button>
                </form>
            </x-ui.section-card>

            {{-- PASSWORD ---------------------------------------------------- --}}
            <x-ui.section-card :label="__('Password')">
                <p class="text-ink-muted -mt-2 mb-4.5 text-2xs leading-relaxed">
                    {{ __('Ensure your account is using a long, random password to stay secure.') }}
                </p>

                <form wire:submit="updatePassword" class="flex max-w-xl flex-col gap-3.5">
                    <flux:input
                        wire:model="current_password"
                        :label="__('Current password')"
                        type="password"
                        required
                        autocomplete="current-password"
                        viewable
                    />
                    <flux:input
                        wire:model="password"
                        :label="__('New password')"
                        type="password"
                        required
                        autocomplete="new-password"
                        passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                        viewable
                    />
                    <flux:input
                        wire:model="password_confirmation"
                        :label="__('Confirm password')"
                        type="password"
                        required
                        autocomplete="new-password"
                        passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                        viewable
                    />

                    <flux:button variant="primary" type="submit" class="mt-1 self-start" data-test="update-password-button">
                        {{ __('Save') }}
                    </flux:button>
                </form>
            </x-ui.section-card>
        </div>
    </section>

    {{-- SECURITY =========================================================== --}}
    @if ($this->canManageTwoFactor() || $this->canManagePasskeys())
        <section class="flex flex-col gap-3">
            <x-ui.section-label tone="muted">{{ __('Security') }}</x-ui.section-label>

            <div class="flex flex-col gap-5">
                {{-- TWO-FACTOR AUTHENTICATION ------------------------------- --}}
                @if ($this->canManageTwoFactor())
                    {{-- Full width: the enabled state carries a grid of recovery codes
                         that a half-width card cannot lay out. --}}
                    <x-ui.section-card>
                        <div class="mb-2 flex flex-wrap items-center gap-2.5">
                            <x-ui.section-label tone="accent">{{ __('Two-factor authentication') }}</x-ui.section-label>

                            <x-ui.pill :tone="$twoFactorEnabled ? 'success' : 'neutral'">
                                {{ $twoFactorEnabled ? __('Enabled') : __('Disabled') }}
                            </x-ui.pill>
                        </div>

                        <p class="text-ink-muted mb-4 text-2xs leading-relaxed">
                            {{ __('Add an extra layer of security — a one-time code from your authenticator app is required at login.') }}
                        </p>

                        @if ($this->twoFactorLocked())
                            <x-ui.confirm-password-notice
                                :message="__('Confirm your password to manage two-factor authentication.')"
                            />
                        @elseif ($twoFactorEnabled)
                            <div class="flex flex-col gap-3.5">
                                <div class="border-success/40 bg-success-soft text-success rounded-xl border px-3.5 py-2.5 text-2xs font-semibold">
                                    {{ __('Two-factor authentication is active on this account.') }}
                                </div>

                                <livewire:pages::settings.two-factor.recovery-codes :$requiresConfirmation />

                                <flux:button
                                    variant="danger"
                                    class="self-start"
                                    wire:click="disableTwoFactor"
                                    wire:confirm="{{ __('Disable two-factor authentication? Your account will be protected by its password alone.') }}"
                                >{{ __('Disable 2FA') }}</flux:button>
                            </div>
                        @else
                            <flux:modal.trigger name="two-factor-setup-modal">
                                <flux:button variant="primary" wire:click="$dispatch('start-two-factor-setup')">
                                    {{ __('Enable 2FA') }}
                                </flux:button>
                            </flux:modal.trigger>

                            <livewire:pages::settings.two-factor-setup-modal :requires-confirmation="$requiresConfirmation" />
                        @endif
                    </x-ui.section-card>
                @endif

                {{-- PASSKEYS ------------------------------------------------ --}}
                @if ($this->canManagePasskeys())
                    <x-ui.section-card :label="__('Passkeys')" :count="count($passkeys)">
                        <p class="text-ink-muted -mt-2 mb-4 text-2xs leading-relaxed">
                            {{ __('Sign in with a fingerprint, face or device PIN instead of a password.') }}
                        </p>

                        @if ($this->passkeysLocked())
                            <x-ui.confirm-password-notice :message="__('Confirm your password to manage passkeys.')" />
                        @else
                            <div class="border-border divide-border mb-4 divide-y overflow-hidden rounded-xl border">
                                @forelse ($passkeys as $passkey)
                                    <div wire:key="passkey-{{ $passkey['id'] }}" class="flex items-center gap-3.5 px-3.5 py-3">
                                        <span class="bg-line/10 flex size-9 shrink-0 items-center justify-center rounded-lg">
                                            <flux:icon.key class="text-ink-muted size-4" />
                                        </span>

                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2">
                                                <span class="truncate text-xs font-bold">{{ $passkey['name'] }}</span>

                                                @if ($passkey['authenticator'])
                                                    <x-ui.mono variant="chip">{{ $passkey['authenticator'] }}</x-ui.mono>
                                                @endif
                                            </div>

                                            <p class="text-ink-faint mt-0.5 text-2xs">
                                                {{ __('Added :time', ['time' => $passkey['created_at_diff']]) }}
                                                @if ($passkey['last_used_at_diff'])
                                                    <span class="mx-1 opacity-50">/</span>
                                                    {{ __('Last used :time', ['time' => $passkey['last_used_at_diff']]) }}
                                                @endif
                                            </p>
                                        </div>

                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="trash"
                                            icon:variant="outline"
                                            class="hover:text-danger!"
                                            :aria-label="__('Remove :name', ['name' => $passkey['name']])"
                                            wire:click="confirmDelete({{ $passkey['id'] }})"
                                        />
                                    </div>
                                @empty
                                    <x-ui.empty-state icon="key" :message="__('No passkeys yet — add one to sign in without a password.')" />
                                @endforelse
                            </div>

                            <x-passkey-registration />
                        @endif
                    </x-ui.section-card>
                @endif
            </div>
        </section>
    @endif

    {{-- DANGER ZONE ======================================================== --}}
    <section class="flex flex-col gap-3">
        <x-ui.section-label tone="muted">{{ __('Danger zone') }}</x-ui.section-label>

        {{-- Last on the page and outlined in danger, rather than sitting mid-grid
             between Profile and Appearance as though it were an ordinary setting. --}}
        <x-ui.section-card class="border-danger-line!">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="min-w-0">
                    <h3 class="text-sm font-extrabold">{{ __('Delete account') }}</h3>

                    <p class="text-ink-muted mt-1 max-w-prose text-2xs leading-relaxed">
                        {{ __('Permanently delete your account and everything in it. Files in the library are not removed.') }}
                    </p>
                </div>

                <flux:modal.trigger name="confirm-user-deletion">
                    <flux:button variant="danger" class="shrink-0" data-test="delete-user-button">
                        {{ __('Delete account') }}
                    </flux:button>
                </flux:modal.trigger>
            </div>

            <livewire:pages::settings.delete-user-modal />
        </x-ui.section-card>
    </section>

    {{-- Passkey removal confirmation. Outside the card, so closing it is not
             affected by the card's own conditional rendering. --}}
    <flux:modal
        name="delete-passkey-modal"
        class="max-w-md md:min-w-md"
        @close="closeDeleteModal"
        wire:model="showDeleteModal"
    >
        <div class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Remove passkey') }}</flux:heading>
                <flux:text>
                    {{ __('Are you sure you want to remove the passkey ":name"? You will no longer be able to use it to sign in.', ['name' => $deletingPasskeyName]) }}
                </flux:text>
            </div>

            <div class="flex justify-end gap-3">
                <flux:button variant="outline" wire:click="closeDeleteModal">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="deletePasskey">{{ __('Remove passkey') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
