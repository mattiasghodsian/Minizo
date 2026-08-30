@props([
    'optionsRoute' => 'passkey.login-options',
    'submitRoute' => 'passkey.login',
    'label' => __('Sign in with a passkey'),
    'loadingLabel' => __('Authenticating...'),
    'separator' => __('Or continue with email'),

    // Conditional mediation ("passkey autofill"), where the browser offers saved
    // passkeys from the username field. Only enable it where an input carries
    // autocomplete="... webauthn"; without one there is nothing to anchor to.
    'autofill' => false,
])

@assets
@vite('resources/js/passkeys.js')
@endassets

<div
    x-data="{
        supported: false,
        loading: false,
        error: null,
        autofillEnabled: {{ $autofill ? 'true' : 'false' }},
        autofillStarted: false,
        updateSupport() {
            this.supported = Boolean(window.Passkeys?.isSupported());
        },
        init() {
            this.updateSupport();
            this.startAutofill();

            window.addEventListener('passkeys:ready', () => {
                this.updateSupport();
                this.startAutofill();
            }, { once: true });
        },
        async startAutofill() {
            if (!this.autofillEnabled || this.autofillStarted || !this.supported) return;

            this.autofillStarted = true;

            try {
                // Resolves only if the user picks a passkey from the dropdown;
                // returns undefined when autofill is unsupported or dismissed.
                const response = await window.Passkeys.autofill({
                    routes: {
                        options: '{{ route($optionsRoute) }}',
                        submit: '{{ route($submitRoute) }}',
                    },
                });

                if (response) {
                    Livewire.navigate(response.redirect || '/download');
                }
            } catch (e) {
                // Never surface this: the ceremony is speculative and the explicit
                // button below remains available as a fallback.
            }
        },
        async verify() {
            this.loading = true;
            this.error = null;
            try {
                const response = await window.Passkeys.verify({
                    routes: {
                        options: '{{ route($optionsRoute) }}',
                        submit: '{{ route($submitRoute) }}',
                    },
                });
                Livewire.navigate(response.redirect || '/download');
            } catch (e) {
                if (e.constructor?.name !== 'UserCancelledError') {
                    this.error = e.message;
                }
            } finally {
                this.loading = false;
            }
        },
    }"
>
    <template x-if="supported">
        <div>
            <div class="grid gap-2">
                <flux:button
                    variant="outline"
                    icon="finger-print"
                    class="w-full"
                    x-on:click="verify()"
                    x-bind:disabled="loading"
                >
                    <span x-show="!loading">{{ $label }}</span>
                    <span x-show="loading" x-cloak>{{ $loadingLabel }}</span>
                </flux:button>
                <p x-show="error" x-text="error" x-cloak
                   class="text-danger text-center text-xs"></p>
            </div>

            {{-- bg-surface, because this component only ever renders inside
                             x-ui.auth-card. A token, so it stays right in both themes. --}}
            <div class="relative my-5">
                <div class="absolute inset-0 flex items-center" aria-hidden="true">
                    <div class="border-hairline w-full border-t"></div>
                </div>
                <div class="relative flex justify-center">
                    <span class="bg-surface text-ink-faint px-2 text-3xs font-bold tracking-[1px] uppercase">
                        {{ $separator }}
                    </span>
                </div>
            </div>
        </div>
    </template>
</div>
