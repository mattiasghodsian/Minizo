{{--
    Still a plain POST form.

    Moving the auth pages to Livewire would mean re-pointing Fortify's view closures
    and re-implementing its pipeline: throttling, the 2FA hand-off, the `login.id`
    session key the two-factor limiter reads, and the password-confirmation timeout.
    Every field here is already a flux:* component, so the [data-flux-control] rules
    in app.css restyle them with no page edits.
--}}
<x-layouts::auth :title="__('Log in')">
    <x-ui.auth-card
        :title="__('Log in to your account')"
        :description="__('Enter your email and password below to log in')"
    >
        <x-auth-session-status class="mb-4 text-center" :status="session('status')" />

        <x-passkey-verify :autofill="true" />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-5">
            @csrf

            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autofocus
                {{-- "webauthn" anchors the passkey autofill dropdown to this field. --}}
                autocomplete="email webauthn"
                placeholder="email@example.com"
            />

            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="current-password"
                :placeholder="__('Password')"
                viewable
            />

            <flux:checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />

            <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                {{ __('Log in') }}
            </flux:button>

            {{-- Under the button rather than beside the password label. The absolutely
                             positioned version overlapped the label at narrow widths and competed
                             with the field's own eye toggle. --}}
            @if (Route::has('password.request'))
                <flux:link
                    class="text-center text-xs"
                    :href="route('password.request')"
                    wire:navigate
                >
                    {{ __('Forgot your password?') }}
                </flux:link>
            @endif
        </form>
    </x-ui.auth-card>

    {{-- Outside the card, so the card stays about one task. Hidden when
             APP_REGISTER is false, since route('register') would throw. --}}
    @if (Route::has('register'))
        <p class="text-ink-muted text-xs">
            {{ __('Don\'t have an account?') }}
            <flux:link :href="route('register')" wire:navigate>{{ __('Sign up') }}</flux:link>
        </p>
    @endif
</x-layouts::auth>
