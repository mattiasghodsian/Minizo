<?php

use Illuminate\Support\Facades\Session;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Features;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** The recovery codes shown inside the enabled 2FA card. */
new class extends Component
{
    /** @var array<int, string> */
    #[Locked]
    public array $recoveryCodes = [];

    public function mount(): void
    {
        $this->loadRecoveryCodes();
    }

    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $generateNewRecoveryCodes): void
    {
        /*
         * Checked on the server, not just by where this component is rendered.
         *
         * The parent only mounts it inside its unlocked branch, but a Livewire snapshot
         * carries no expiry and is not bound to the password-confirmation timestamp - so
         * one captured while the window was open stays callable after it lapses. Its three
         * sibling actions in the parent already do this; this one did not.
         */
        abort_if($this->locked(), 403);

        $generateNewRecoveryCodes(auth()->user());

        $this->loadRecoveryCodes();
    }

    /** Whether the password-confirmation window has lapsed. Mirrors the parent's gate. */
    private function locked(): bool
    {
        if (! Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword')) {
            return false;
        }

        $confirmedAt = Session::get('auth.password_confirmed_at');

        return ! is_numeric($confirmedAt)
            || (time() - (int) $confirmedAt) >= config('auth.password_timeout', 10800);
    }

    private function loadRecoveryCodes(): void
    {
        $user = auth()->user();

        if (! $user->hasEnabledTwoFactorAuthentication() || ! $user->two_factor_recovery_codes) {
            return;
        }

        try {
            $this->recoveryCodes = json_decode(decrypt($user->two_factor_recovery_codes), true);
        } catch (Exception) {
            // A decrypt failure means APP_KEY changed. Saying so beats an empty grid that
            // looks like "you have no codes".
            $this->addError('recoveryCodes', __('Recovery codes could not be read. Regenerate them to get a fresh set.'));

            $this->recoveryCodes = [];
        }
    }
}; ?>

{{-- Hidden by default: recovery codes are password-equivalent, and this card is
     on a screen someone might have open while sharing it. --}}
<div x-data="{ shown: false }" wire:cloak>
    <div class="mb-2 flex flex-wrap items-center gap-2">
        <x-ui.section-label variant="table">{{ __('Recovery codes · store these safely') }}</x-ui.section-label>

        <button
            type="button"
            x-on:click="shown = ! shown"
            x-bind:aria-expanded="shown ? 'true' : 'false'"
            aria-controls="recovery-codes"
            class="text-brand-text hover:text-brand-text-hover cursor-pointer text-2xs font-bold transition-colors"
        >
            <span x-show="! shown">{{ __('Show') }}</span>
            <span x-show="shown" x-cloak>{{ __('Hide') }}</span>
        </button>

        @if (filled($recoveryCodes))
            <button
                type="button"
                x-show="shown"
                x-cloak
                wire:click="regenerateRecoveryCodes"
                wire:confirm="{{ __('Generate a new set? The codes below stop working immediately.') }}"
                class="text-ink-muted hover:text-ink ms-auto cursor-pointer text-2xs font-bold transition-colors"
            >{{ __('Regenerate') }}</button>
        @endif
    </div>

    @error('recoveryCodes')
        <p class="text-danger text-xs">{{ $message }}</p>
    @enderror

    <div x-show="shown" x-cloak id="recovery-codes">
        @if (filled($recoveryCodes))
            {{-- The design's 4-across grid, collapsing on narrow screens. --}}
            <div
                class="grid gap-2 [grid-template-columns:repeat(auto-fill,minmax(110px,1fr))]"
                role="list"
                aria-label="{{ __('Recovery codes') }}"
            >
                @foreach ($recoveryCodes as $code)
                    <span
                        role="listitem"
                        wire:loading.class="opacity-40"
                        class="bg-line/8 text-ink-secondary rounded-md px-2.5 py-1.5 text-center font-mono text-2xs select-all"
                    >{{ $code }}</span>
                @endforeach
            </div>

            <p class="text-ink-faint mt-2 text-3xs font-medium">
                {{ __('Each code works once and is consumed when used.') }}
            </p>
        @endif
    </div>
</div>
