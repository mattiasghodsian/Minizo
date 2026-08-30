<?php

use App\Concerns\ProfileValidationRules;
use App\Enums\AudioFormat;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use App\Services\Library\FolderService;
use App\Services\Sharing\ShareService;
use App\Support\FolderAccess;
use App\Support\NewUserDefaults;
use App\Support\Permissions;
use App\Support\UserSessions;
use Flux\Flux;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/** Manage user, and Add user. */
new class extends Component
{
    use ProfileValidationRules;

    /** The account being edited. Null while adding. */
    public ?int $userId = null;

    public bool $adding = false;

    // ---- Add-user fields only
    public string $name = '';

    public string $email = '';

    /** The manage modal's own email field, kept separate from the add form's. */
    public string $editingEmail = '';

    #[On('user-manage')]
    public function manage(int $user): void
    {
        $subject = $this->find($user);

        $this->authorize('update', $subject);

        $this->adding = false;
        $this->userId = $subject->getKey();
        $this->editingEmail = $subject->email;

        $this->resetErrorBag();

        unset($this->subject);

        Flux::modal('user-manage')->show();
    }

    #[On('user-create')]
    public function startAdding(): void
    {
        $this->authorize('create', User::class);

        $this->reset('userId', 'name', 'email');

        $this->adding = true;

        Flux::modal('user-add')->show();
    }

    // ------------------------------------------------------------------- subject

    #[Computed]
    public function subject(): ?User
    {
        return $this->userId === null ? null : User::find($this->userId);
    }

    /** Whether the viewer may change this account's privileges. */
    #[Computed]
    public function editable(): bool
    {
        $subject = $this->subject;

        return $subject !== null && auth()->user()->can('setPermissions', $subject);
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function folders(): array
    {
        return app(FolderService::class)->names();
    }

    #[Computed]
    public function access(): FolderAccess
    {
        return $this->subject?->folderAccess() ?? FolderAccess::none();
    }

    #[Computed]
    public function permissions(): Permissions
    {
        // sharingEnabled: true, because this modal edits what an admin grants. A Share
        // toggle rendered off by the instance switch would look like a revoked grant.
        return $this->subject !== null
            ? Permissions::forUser($this->subject, sharingEnabled: true)
            : Permissions::none();
    }

    // ---------------------------------------------------------------------- role

    public function setRole(string $role): void
    {
        $subject = $this->authorized();

        $resolved = Role::tryFrom($role) ?? Role::User;

        $subject->forceFill(['role' => $resolved])->save();

        $this->done(__('Role changed to :role.', ['role' => $resolved->label()]));
    }

    // ------------------------------------------------------------- folder access

    /** The "All folders" chip. */
    public function toggleAllFolders(): void
    {
        $subject = $this->authorized();

        $access = $this->access->allowsAll() ? FolderAccess::none() : FolderAccess::all();

        $subject->forceFill(['folder_access' => $access->toArray()])->save();

        $this->done();
    }

    /** One folder chip. */
    public function toggleFolder(string $folder): void
    {
        $subject = $this->authorized();

        $access = $this->access;

        $updated = $access->allows($folder)
            ? $access->withoutFolder($folder, $this->folders)
            : $access->withFolder($folder);

        $subject->forceFill(['folder_access' => $updated->toArray()])->save();

        $this->done();
    }

    // --------------------------------------------------------------- permissions

    public function togglePermission(string $permission): void
    {
        $subject = $this->authorized();

        $resolved = Permission::tryFrom($permission);

        abort_if($resolved === null, 404);

        $granted = $this->permissions->granted($resolved);

        $subject->forceFill([$resolved->column() => ! $granted])->save();

        /*
         * Revoking "Use downloader" clears the locks with it. A folder lock on an account
         * that cannot download is dead configuration that would silently come back into
         * force if the permission were ever re-granted - which is a surprise nobody needs.
         */
        if ($resolved === Permission::Downloader && $granted) {
            $subject->forceFill([
                'download_folder_lock' => null,
                'download_format_lock' => null,
            ])->save();
        }

        $this->done();
    }

    public function toggleActive(): void
    {
        $subject = $this->find($this->userId ?? 0);

        // Its own ability, not setPermissions: an admin may not deactivate themselves for
        // the same reason they may not demote themselves.
        $this->authorize('setActive', $subject);

        $active = ! $subject->is_active;

        $subject->forceFill(['is_active' => $active])->save();

        $revoked = 0;

        if (! $active) {
            /*
             * Disabling an account must not leave the person browsing until their session
             * happens to expire. EnsureUserIsActive catches the NEXT request; purging their
             * session rows closes the window in between.
             */
            UserSessions::purge($subject);

            // And their public links, if the instance is configured for it. Off by
            // default, since disabling revokes login rather than un-publishing, but an
            // instance disabling a compromised account wants the opposite.
            $revoked = app(ShareService::class)->revokeForUser($subject);
        }

        if ($active) {
            $this->done(__(':name can sign in again.', ['name' => $subject->name]));

            return;
        }

        // Said out loud when it happens: silently killing someone's published links is a
        // surprising side effect of a button labelled "disable".
        $this->done($revoked > 0
            ? trans_choice(
                ':name has been signed out, and :count share link was revoked.|:name has been signed out, and :count share links were revoked.',
                $revoked,
                ['name' => $subject->name, 'count' => $revoked],
            )
            : __(':name has been signed out and cannot sign in.', ['name' => $subject->name]));
    }

    // ------------------------------------------------------- downloader locks

    public function setFolderLock(string $folder): void
    {
        $subject = $this->authorized();

        // '' is the "Any allowed folder" option. An unknown name is dropped rather than
        // stored, so a lock can never point at a folder that does not exist.
        $lock = $folder === '' || ! in_array($folder, $this->folders, true) ? null : $folder;

        $subject->forceFill(['download_folder_lock' => $lock])->save();

        $this->done();
    }

    public function setFormatLock(string $format): void
    {
        $subject = $this->authorized();

        $subject->forceFill([
            'download_format_lock' => $format === '' ? null : AudioFormat::tryFrom($format),
        ])->save();

        $this->done();
    }

    // --------------------------------------------------------------------- email

    /** Change an account's email address. */
    public function updateEmail(): void
    {
        $subject = $this->find($this->userId ?? 0);

        $this->authorize('update', $subject);

        $validated = $this->validate(
            ['editingEmail' => $this->emailRules($subject->getKey())],
            attributes: ['editingEmail' => __('email')],
        );

        if ($validated['editingEmail'] === $subject->email) {
            return;
        }

        $subject->forceFill([
            'email' => $validated['editingEmail'],
            /*
             * Re-verified immediately rather than nulled. Minizo sends no mail - accounts are
             * created force-verified for exactly that reason - so clearing this would strand
             * the account behind a link that can never arrive.
             */
            'email_verified_at' => now(),
        ])->save();

        $this->done(__('Email changed to :email.', ['email' => $subject->email]));
    }

    // ------------------------------------------------------------------ add user

    public function store(): void
    {
        $this->authorize('create', User::class);

        $validated = $this->validate([
            'name' => $this->nameRules(),
            'email' => $this->emailRules(),
        ]);

        // No password is set and no invitation is sent. MAIL_MAILER defaults to `log`,
        // so an invitation flow would fail on most installs. A random password plus the
        // forgot-password flow works whether or not SMTP is configured.
        $user = new User;

        $user->forceFill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make(Str::random(40)),
            // No verification email either, for the same reason. An account an admin
            // created by hand is already vouched for.
            'email_verified_at' => now(),
        ])->save();

        // Locked down: no folders, no permissions. An admin grants them in the modal
        // that opens next.
        NewUserDefaults::lockDown($user);

        $this->userId = $user->getKey();
        $this->adding = false;

        unset($this->subject, $this->access, $this->permissions);

        Flux::modals()->close();

        $this->dispatch('users-updated');

        Flux::toast(
            variant: 'success',
            text: __('Created :name. They must use “Forgot password” to set a password.', ['name' => $user->name]),
        );

        // Straight into managing them, because a locked-down account is not useful yet.
        Flux::modal('user-manage')->show();
    }

    // ------------------------------------------------------------------ internals

    private function find(int $id): User
    {
        $user = User::find($id);

        abort_if($user === null, 404);

        return $user;
    }

    /** The subject, with the privilege-editing check applied. */
    private function authorized(): User
    {
        $subject = $this->find($this->userId ?? 0);

        $this->authorize('setPermissions', $subject);

        return $subject;
    }

    private function done(?string $message = null): void
    {
        unset($this->subject, $this->access, $this->permissions, $this->editable);

        $this->dispatch('users-updated');

        if ($message !== null) {
            Flux::toast(variant: 'success', text: $message);
        }
    }
}; ?>

<div>
    {{-- Manage user -------------------------------------------------------- --}}
    <x-ui.modal-shell name="user-manage" :width="560">
        @if ($this->subject)
            @php
                $subject = $this->subject;
                $access = $this->access;
                $permissions = $this->permissions;
                $canEdit = $this->editable;
                $downloaderOff = ! $permissions->granted(Permission::Downloader);
            @endphp

            {{-- Header: avatar, name, email. Not modal-shell's title, because the
                             design puts an avatar beside it. --}}
            <div class="-mt-1 flex items-center gap-3">
                <x-ui.tile :name="$subject->name" size="xl" />

                <div class="min-w-0">
                    <div class="truncate text-md font-extrabold">{{ $subject->name }}</div>
                    <div class="text-ink-muted truncate text-xs">{{ $subject->email }}</div>
                </div>
            </div>

            {{-- Email. Here because Settings locks the field and tells the user to ask
                             an administrator; this is where that request gets actioned. --}}
            <div class="mt-5">
                <x-ui.section-label variant="table">{{ __('Email address') }}</x-ui.section-label>

                <form wire:submit="updateEmail" class="mt-2 flex items-start gap-2">
                    <div class="flex-1">
                        <flux:input
                            wire:model="editingEmail"
                            type="email"
                            size="sm"
                            :aria-label="__('Email address')"
                        />
                    </div>

                    <flux:button size="sm" variant="outline" type="submit">{{ __('Change') }}</flux:button>
                </form>
            </div>

            {{-- Explains every disabled control below in one sentence, rather than
                             leaving an admin wondering why their own row is inert. --}}
            @unless ($canEdit)
                <p class="border-warning/40 bg-warning-soft text-warning mt-4 rounded-xl border px-4 py-3 text-xs leading-relaxed font-semibold">
                    {{ __('This is your own account. Role, folder access and permissions cannot be changed here — otherwise an administrator could lock themselves, and everyone else, out of the instance.') }}
                </p>
            @endunless

            {{-- ROLE ------------------------------------------------------- --}}
            <div class="mt-5">
                <x-ui.section-label variant="table">{{ __('Role') }}</x-ui.section-label>

                <div class="mt-2 flex gap-2" @class(['pointer-events-none opacity-35' => ! $canEdit])>
                    @foreach (Role::cases() as $role)
                        <button
                            type="button"
                            wire:click="setRole('{{ $role->value }}')"
                            @class([
                                'rounded-[9px] border px-4.5 py-2 text-xs font-extrabold transition-colors',
                                'border-brand bg-brand-soft text-brand-text' => $subject->role === $role,
                                'border-field-border text-ink-muted hover:border-line/45 cursor-pointer' => $subject->role !== $role,
                            ])
                        >{{ $role->label() }}</button>
                    @endforeach
                </div>
            </div>

            {{-- FOLDER ACCESS --------------------------------------------- --}}
            <div class="mt-5">
                <x-ui.section-label variant="table">{{ __('Folder access') }}</x-ui.section-label>

                <div class="mt-2.5 flex flex-wrap gap-1.5" @class(['pointer-events-none opacity-35' => ! $canEdit])>
                    {{-- "All folders" first, as the design has it. --}}
                    <x-ui.pill
                        as="button"
                        :selected="$access->allowsAll()"
                        wire:click="toggleAllFolders"
                        class="px-3 py-1.5"
                    >{{ __('All folders') }}</x-ui.pill>

                    @foreach ($this->folders as $folder)
                        {{-- A folder reads as selected when the sentinel is set, even though it
                                                     is not named in the list, which is what makes toggling one
                                                     off expand the sentinel rather than do nothing. --}}
                        <x-ui.pill
                            as="button"
                            wire:key="folder-{{ $folder }}"
                            :selected="$access->allows($folder)"
                            wire:click="toggleFolder({{ \Illuminate\Support\Js::from($folder) }})"
                            class="px-3 py-1.5"
                        >{{ $folder }}</x-ui.pill>
                    @endforeach

                    @if ($this->folders === [])
                        <span class="text-ink-faint text-xs">{{ __('No folders in the library yet.') }}</span>
                    @endif
                </div>
            </div>

            {{-- PERMISSIONS ----------------------------------------------- --}}
            <div class="mt-5">
                <x-ui.section-label variant="table">{{ __('Permissions') }}</x-ui.section-label>

                <div class="mt-1 flex flex-col" @class(['pointer-events-none opacity-35' => ! $canEdit])>
                    @foreach (Permission::cases() as $permission)
                        <x-ui.toggle-row
                            wire:key="perm-{{ $permission->value }}"
                            :label="$permission->label()"
                            :description="$permission->description()"
                            class="border-hairline/70 border-b py-2.5"
                        >
                            <flux:switch
                                :checked="$permissions->granted($permission)"
                                wire:click="togglePermission('{{ $permission->value }}')"
                            />
                        </x-ui.toggle-row>
                    @endforeach

                    {{-- Account active sits with the permissions in the design, but it is a
                                             separate ability: an admin may not deactivate themselves. --}}
                    <x-ui.toggle-row
                        :label="__('Account active')"
                        :description="__('Disabled users cannot log in, and are signed out immediately.')"
                        :inert="! auth()->user()->can('setActive', $subject)"
                        class="py-2.5"
                    >
                        <flux:switch
                            :checked="$subject->is_active"
                            wire:click="toggleActive"
                        />
                    </x-ui.toggle-row>
                </div>
            </div>

            {{-- DOWNLOADER RESTRICTIONS ----------------------------------- --}}
            {{-- Dimmed when the account cannot download at all: a lock on a user with
                             no downloader permission is configuration that does nothing. --}}
            <div class="mt-5" @class(['pointer-events-none opacity-35' => ! $canEdit || $downloaderOff])>
                <x-ui.section-label variant="table">{{ __('Downloader restrictions') }}</x-ui.section-label>

                <div class="mt-2.5 grid grid-cols-2 gap-3">
                    <flux:select
                        :label="__('Lock download folder')"
                        wire:change="setFolderLock($event.target.value)"
                    >
                        <flux:select.option value="" :selected="$subject->download_folder_lock === null">
                            {{ __('Any allowed folder') }}
                        </flux:select.option>

                        @foreach ($this->folders as $folder)
                            <flux:select.option
                                :value="$folder"
                                :selected="$subject->download_folder_lock === $folder"
                            >{{ $folder }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select
                        :label="__('Lock format')"
                        wire:change="setFormatLock($event.target.value)"
                    >
                        <flux:select.option value="" :selected="$subject->download_format_lock === null">
                            {{ __('Any format') }}
                        </flux:select.option>

                        @foreach (AudioFormat::cases() as $format)
                            <flux:select.option
                                :value="$format->value"
                                :selected="$subject->download_format_lock === $format"
                            >{{ $format->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <p class="text-ink-faint mt-2 text-2xs leading-relaxed">
                    {{ __('New downloads from this user are forced into the locked folder and format — e.g. Unprocessed + FLAC, so their downloads land in review before entering the library.') }}
                </p>

                @if ($downloaderOff)
                    <p class="text-ink-faint mt-1.5 text-2xs">
                        {{ __('Locks apply only to accounts that can queue downloads.') }}
                    </p>
                @endif
            </div>

            {{-- Every change above saved as it was made, so this only closes. --}}
            <x-ui.modal-footer>
                <flux:modal.close>
                    <flux:button variant="primary" type="button">{{ __('Done') }}</flux:button>
                </flux:modal.close>
            </x-ui.modal-footer>
        @endif
    </x-ui.modal-shell>

    {{-- Add user ---------------------------------------------------------- --}}
    <x-ui.modal-shell
        name="user-add"
        :title="__('Add user')"
        :subtitle="__('The account starts with no folder access and no permissions. You grant those next.')"
        :width="440"
    >
        <form wire:submit="store" class="space-y-4">
            <flux:input wire:model="name" :label="__('Display name')" autofocus />
            <flux:input wire:model="email" type="email" :label="__('Email')" />

            {{-- Said out loud, because an admin expecting an invitation email would
                             otherwise create an account nobody can sign into. Minizo does not
                             require working mail; MAIL_MAILER defaults to `log`. --}}
            <p class="text-ink-faint text-2xs leading-relaxed">
                {{ __('No password is set and no email is sent. Tell them to use “Forgot password” on the login page, or set one yourself with php artisan minizo:make-admin.') }}
            </p>

            <x-ui.modal-footer>
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="primary" type="submit">{{ __('Create account') }}</flux:button>
            </x-ui.modal-footer>
        </form>
    </x-ui.modal-shell>
</div>
