<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AudioFormat;
use App\Enums\Permission;
use App\Enums\Role;
use App\Support\FolderAccess;
use App\Support\Permissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property Role $role
 * @property bool $is_active
 * @property array<int, string>|null $folder_access
 * @property bool $can_edit
 * @property bool $can_move
 * @property bool $can_download
 * @property bool $can_delete
 * @property bool $can_downloader
 * @property bool $can_share
 * @property string|null $download_folder_lock
 * @property AudioFormat|null $download_format_lock
 * @property int $pagination_size
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Note what is NOT fillable: role, is_active, folder_access and the six can_* columns are all privilege. They are set through explicit, authorized paths (the Manage-user screen, minizo:make-admin, the first-user bootstrap) - never from a mass-assigned request payload.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
            'folder_access' => 'array',
            'can_edit' => 'boolean',
            'can_move' => 'boolean',
            'can_download' => 'boolean',
            'can_delete' => 'boolean',
            'can_downloader' => 'boolean',
            'can_share' => 'boolean',
            'download_format_lock' => AudioFormat::class,
        ];
    }

    /** Get the user's initials */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /** Whether this account holds the admin role. */
    public function isAdmin(): bool
    {
        return $this->role->isAdmin();
    }

    /**
     * Artists this user follows.
     *
     * @return BelongsToMany<Artist, $this, ArtistFollow, 'pivot'>
     */
    public function followedArtists(): BelongsToMany
    {
        return $this->belongsToMany(Artist::class, 'artist_follows')
            // using(), so last_viewed_at comes back as a Carbon rather than a raw string.
            ->using(ArtistFollow::class)
            ->withPivot('last_viewed_at')
            ->withTimestamps();
    }

    /** Which folders this user may see. */
    public function folderAccess(): FolderAccess
    {
        return FolderAccess::fromUser($this);
    }

    /** This user's capabilities. */
    public function permissions(): Permissions
    {
        return Permissions::forUser($this);
    }

    /** Convenience for the common check. Reads effective(), so it respects any instance-wide switch. */
    public function hasPermission(Permission $permission): bool
    {
        return $this->permissions()->effective($permission);
    }

    /** Page size, clamped to the configured bounds. */
    public function paginationSize(): int
    {
        return max(
            (int) config('minizo.pagination.min', 10),
            min(
                (int) config('minizo.pagination.max', 200),
                $this->pagination_size ?: (int) config('minizo.pagination.default', 50),
            ),
        );
    }
}
