<x-layouts::auth :title="__('Confirm password')">
    <x-ui.auth-card
        :title="__('Confirm password')"
        :description="__('This is a secure area of the application. Please confirm your password before continuing.')"
    >
        <x-auth-session-status class="mb-4 text-center" :status="session('status')" />

        {{-- No autofill here: this page has no username field, and conditional
                     mediation needs an input carrying autocomplete="... webauthn" to anchor
                     its dropdown to. The explicit button is the whole passkey flow. --}}
        <x-passkey-verify
            options-route="passkey.confirm-options"
            submit-route="passkey.confirm"
            :label="__('Confirm with passkey')"
            :loading-label="__('Confirming...')"
            :separator="__('Or confirm with password')"
        />

        <form method="POST" action="{{ route('password.confirm.store') }}" class="flex flex-col gap-5">
            @csrf

            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autofocus
                autocomplete="current-password"
                :placeholder="__('Password')"
                viewable
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="confirm-password-button">
                {{ __('Confirm') }}
            </flux:button>
        </form>
    </x-ui.auth-card>
</x-layouts::auth>
