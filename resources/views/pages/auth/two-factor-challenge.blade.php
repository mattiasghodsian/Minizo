{{--
    The Alpine block below is kept verbatim from the starter kit. It seeds
    showRecoveryInput from $errors so a failed recovery attempt returns on the
    recovery tab, and moves focus into the right control after a toggle.

    The card and header sit outside the x-data so the two headings can swap without
    the card re-rendering.
--}}
<x-layouts::auth :title="__('Two-factor authentication')">
    <div
        class="w-full"
        x-cloak
        x-data="{
            showRecoveryInput: @js($errors->has('recovery_code')),
            code: '',
            recovery_code: '',
            focusOtp() {
                this.$nextTick(() => this.$refs.otp?.querySelector('input')?.focus());
            },
            init() {
                if (! this.showRecoveryInput) {
                    this.focusOtp();
                }
            },
            toggleInput() {
                this.showRecoveryInput = !this.showRecoveryInput;

                this.code = '';
                this.recovery_code = '';

                $nextTick(() => {
                    this.showRecoveryInput
                        ? this.$refs.recovery_code?.focus()
                        : this.focusOtp();
                });
            },
        }"
    >
        <x-ui.auth-card class="mx-auto">
            <div x-show="!showRecoveryInput" class="mb-6">
                <x-auth-header
                    :title="__('Authentication code')"
                    :description="__('Enter the authentication code provided by your authenticator application.')"
                />
            </div>

            <div x-show="showRecoveryInput" class="mb-6">
                <x-auth-header
                    :title="__('Recovery code')"
                    :description="__('Please confirm access to your account by entering one of your emergency recovery codes.')"
                />
            </div>

            <form method="POST" action="{{ route('two-factor.login.store') }}">
                @csrf

                <div class="flex flex-col gap-5">
                    <div x-show="!showRecoveryInput">
                        {{-- flux:otp needs no vendor override: its cells carry [data-flux-control],
                                                     so app.css already gives them the field treatment. These
                                                     classes only enlarge them into mono code cells. --}}
                        <div class="flex items-center justify-center" x-ref="otp">
                            <flux:otp
                                x-model="code"
                                length="6"
                                name="code"
                                label="OTP Code"
                                label:sr-only
                                class="mx-auto [&_input]:size-12 [&_input]:text-center [&_input]:font-mono [&_input]:text-lg"
                            />
                        </div>

                        @error('code')
                            <p class="text-danger mt-3 text-center text-xs">{{ $message }}</p>
                        @enderror
                    </div>

                    <div x-show="showRecoveryInput">
                        <flux:input
                            type="text"
                            name="recovery_code"
                            x-ref="recovery_code"
                            x-bind:required="showRecoveryInput"
                            autocomplete="one-time-code"
                            x-model="recovery_code"
                            :placeholder="__('Recovery code')"
                            class="[&_input]:font-mono"
                        />

                        @error('recovery_code')
                            <p class="text-danger mt-2 text-center text-xs">{{ $message }}</p>
                        @enderror
                    </div>

                    <flux:button variant="primary" type="submit" class="w-full">
                        {{ __('Continue') }}
                    </flux:button>
                </div>
            </form>
        </x-ui.auth-card>

        {{-- The toggle sits outside the card, like the "Sign up" link on login.
                     Real <button>s, so it is keyboard-reachable; the starter kit used
                     clickable <span>s. --}}
        <p class="text-ink-muted mt-5 text-center text-xs">
            <span>{{ __('or you can') }}</span>

            <button
                type="button"
                x-show="!showRecoveryInput"
                x-on:click="toggleInput()"
                class="text-brand-text hover:text-brand-text-hover cursor-pointer font-semibold underline"
            >{{ __('login using a recovery code') }}</button>

            <button
                type="button"
                x-show="showRecoveryInput"
                x-on:click="toggleInput()"
                class="text-brand-text hover:text-brand-text-hover cursor-pointer font-semibold underline"
            >{{ __('login using an authentication code') }}</button>
        </p>
    </div>
</x-layouts::auth>
