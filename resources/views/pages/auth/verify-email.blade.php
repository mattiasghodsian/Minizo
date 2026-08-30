<x-layouts::auth :title="__('Email verification')">
    <x-ui.auth-card
        :title="__('Verify your email')"
        :description="__('Please verify your email address by clicking on the link we just emailed to you.')"
    >
        @if (session('status') == 'verification-link-sent')
            {{-- text-success rather than `!dark:text-green-400 !text-green-600`, which is
                            invalid in Tailwind v4 (the important flag goes after the variant) and
                            silently did nothing in dark mode. The token carries both themes. --}}
            <p class="text-success mb-5 text-center text-xs font-semibold">
                {{ __('A new verification link has been sent to the email address you provided during registration.') }}
            </p>
        @endif

        <div class="flex flex-col gap-3">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <flux:button type="submit" variant="primary" class="w-full">
                    {{ __('Resend verification email') }}
                </flux:button>
            </form>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <flux:button
                    variant="ghost"
                    type="submit"
                    class="w-full cursor-pointer"
                    data-test="logout-button"
                >
                    {{ __('Log out') }}
                </flux:button>
            </form>
        </div>
    </x-ui.auth-card>
</x-layouts::auth>
