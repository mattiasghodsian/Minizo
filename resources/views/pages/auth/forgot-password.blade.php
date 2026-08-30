<x-layouts::auth :title="__('Forgot password')">
    <x-ui.auth-card
        :title="__('Forgot password')"
        :description="__('Enter your email to receive a password reset link')"
    >
        <x-auth-session-status class="mb-4 text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-5">
            @csrf

            <flux:input
                name="email"
                :label="__('Email address')"
                type="email"
                required
                autofocus
                placeholder="email@example.com"
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="email-password-reset-link-button">
                {{ __('Email password reset link') }}
            </flux:button>
        </form>
    </x-ui.auth-card>

    <p class="text-ink-muted text-xs">
        {{ __('Or, return to') }}
        <flux:link :href="route('login')" wire:navigate>{{ __('log in') }}</flux:link>
    </p>
</x-layouts::auth>
