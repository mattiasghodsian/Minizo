<?php

use App\Support\ApiToken;
use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The API access card: one personal token for the read-only feed endpoint.
 *
 * Its own component rather than another card on the already-long Settings screen, so the
 * show-once plaintext lives in a snapshot of its own and cannot leak into the parent's.
 *
 * No password gate, unlike 2FA and passkeys: the token reads this user's own feed and
 * nothing else, and the session generating it already has strictly more access.
 */
new class extends Component
{
    /** The plaintext, held only for the life of this component instance. Never re-derivable. */
    #[Locked]
    public ?string $plainToken = null;

    #[Locked]
    public bool $hasToken = false;

    #[Locked]
    public ?string $createdAtDiff = null;

    #[Locked]
    public ?string $lastUsedAtDiff = null;

    public function mount(): void
    {
        $this->loadToken();
    }

    private function loadToken(): void
    {
        $user = auth()->user();

        $this->hasToken = filled($user->api_token);
        $this->createdAtDiff = $user->api_token_created_at?->diffForHumans();
        $this->lastUsedAtDiff = $user->api_token_last_used_at?->diffForHumans();
    }

    /** Issue a token, replacing any existing one. */
    public function generate(): void
    {
        $this->plainToken = ApiToken::issueFor(auth()->user());

        $this->loadToken();

        Flux::toast(variant: 'success', text: __('API token generated. Copy it now — it is not shown again.'));
    }

    public function revoke(): void
    {
        ApiToken::revokeFor(auth()->user());

        $this->reset('plainToken');
        $this->loadToken();

        Flux::toast(variant: 'success', text: __('API token revoked.'));
    }

    /** The curl line shown under the card, carrying the real token the one time we have it. */
    public function curlExample(): string
    {
        return sprintf(
            'curl -H "Authorization: Bearer %s" %s',
            $this->plainToken ?? ApiToken::PREFIX.'…',
            route('api.feed'),
        );
    }
}; ?>

<x-ui.section-card :label="__('API access')">
    <p class="text-ink-muted -mt-2 mb-4.5 text-2xs leading-relaxed">
        {{ __('Read your feed from your own apps. One token per account, and it only ever exposes your feed — no library files, no downloads, no other accounts.') }}
    </p>

    {{-- ENDPOINT ----------------------------------------------------------
         Always visible, token or not, so the card documents the feature and
         nobody has to guess the URL. route(), not a literal, so it follows
         APP_URL behind a reverse proxy. --}}
    <div class="border-border bg-sunken mb-4.5 rounded-xl border p-3.5">
        <x-ui.section-label variant="table">{{ __('Endpoint') }}</x-ui.section-label>

        <div class="mt-2.5 flex flex-wrap items-center gap-2.5">
            <x-ui.pill tone="accent">GET</x-ui.pill>

            <x-ui.mono variant="strong" class="min-w-0 truncate">{{ route('api.feed') }}</x-ui.mono>

            <x-ui.copy-button :value="route('api.feed')" class="ms-auto shrink-0" />
        </div>

        <p class="text-ink-faint mt-2.5 text-2xs leading-relaxed">
            {{ __('Send the token as a bearer header. Read-only, and it never marks your feed as viewed.') }}
        </p>
    </div>

    {{-- THE TOKEN --------------------------------------------------------- --}}
    @if ($plainToken)
        {{-- The one moment we hold the plaintext. Readonly input rather than plain
             text so it can be selected and copied by keyboard where the clipboard
             API is unavailable, which is the case on plain http. --}}
        <div class="flex items-center gap-2">
            <input
                type="text"
                readonly
                value="{{ $plainToken }}"
                onfocus="this.select()"
                class="bg-sunken border-field-border text-brand-text min-w-0 flex-1 truncate rounded-[9px] border px-3.5 py-2.5 font-mono text-2xs"
                aria-label="{{ __('API token') }}"
            />

            <x-ui.copy-button :value="$plainToken" class="shrink-0" />
        </div>

        <p class="text-ink-faint mt-2.5 text-2xs leading-relaxed">
            {{ __('Copy this now — it is not shown again. Generate a new one if you lose it.') }}
        </p>
    @elseif ($hasToken)
        <div class="flex flex-wrap items-center gap-2.5">
            <x-ui.pill tone="success">{{ __('Active') }}</x-ui.pill>

            <x-ui.mono>{{ App\Support\ApiToken::PREFIX }}••••••••</x-ui.mono>
        </div>

        <p class="text-ink-faint mt-2.5 text-2xs">
            {{ __('Created :time', ['time' => $createdAtDiff]) }}

            <span class="mx-1 opacity-50">/</span>

            {{ $lastUsedAtDiff
                ? __('Last used :time', ['time' => $lastUsedAtDiff])
                : __('Never used') }}
        </p>
    @endif

    {{-- ACTIONS ----------------------------------------------------------- --}}
    <div class="mt-4 flex flex-wrap items-center gap-2.5">
        {{-- Bound, not wrapped in @if: a Blade directive inside a component tag's
             attribute list is not parsed and leaks into the rendered HTML. A null
             binding omits the attribute, which is what the no-token case wants. --}}
        <flux:button
            :variant="$hasToken ? 'outline' : 'primary'"
            wire:click="generate"
            data-test="generate-api-token-button"
            :wire:confirm="$hasToken ? __('Generate a new token? The current one stops working immediately.') : null"
        >{{ $hasToken ? __('Regenerate token') : __('Generate token') }}</flux:button>

        @if ($hasToken)
            <flux:button
                variant="ghost"
                class="hover:text-danger!"
                wire:click="revoke"
                data-test="revoke-api-token-button"
                wire:confirm="{{ __('Revoke this token? Anything using it stops working immediately.') }}"
            >{{ __('Revoke') }}</flux:button>
        @endif
    </div>

    {{-- The whole request on screen at once, so the card is copy-pasteable
         documentation. Carries the real token the one time we have it. --}}
    <div class="border-border mt-4.5 border-t pt-4">
        <x-ui.section-label variant="table">{{ __('Example') }}</x-ui.section-label>

        <div class="mt-2.5 flex items-center gap-2">
            <x-ui.mono class="min-w-0 flex-1 overflow-x-auto whitespace-nowrap">{{ $this->curlExample() }}</x-ui.mono>

            <x-ui.copy-button :value="$this->curlExample()" class="shrink-0" />
        </div>
    </div>
</x-ui.section-card>
