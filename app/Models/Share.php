<?php

namespace App\Models;

use App\Enums\ShareType;
use App\Support\ExpiryLabel;
use App\Support\LibraryFile;
use App\Support\LibraryFolder;
use Database\Factories\ShareFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * @property int $id
 * @property int $user_id
 * @property string $token
 * @property ShareType $type
 * @property string $name
 * @property string $folder
 * @property string|null $filename
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_accessed_at
 * @property int $access_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
class Share extends Model
{
    /** @use HasFactory<ShareFactory> */
    use HasFactory, Prunable;

    /**
     * Nothing is mass-assignable. A share's folder, filename and token decide what the internet can read, so every one of them is set explicitly by ShareService.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ShareType::class,
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_accessed_at' => 'datetime',
            'access_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ------------------------------------------------------------------- pruning

    /**
     * Dead links are kept for the retention window, then removed.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $cutoff = now()->subDays((int) config('minizo.shares.retention_days', 30));

        return static::query()
            ->where('expires_at', '<', $cutoff)
            ->where(fn (Builder $query) => $query
                ->whereNull('revoked_at')
                ->orWhere('revoked_at', '<', $cutoff));
    }

    /** Prunable deletes silently, which would make the retention promise unverifiable. */
    protected function pruning(): void
    {
        Log::info('Pruning expired share link', [
            'share_id' => $this->getKey(),
            'name' => $this->name,
            'expired_at' => (string) $this->expires_at,
        ]);
    }

    // -------------------------------------------------------------------- scopes

    /**
     * Resolvable right now.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeDead(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereNotNull('revoked_at')
            ->orWhere('expires_at', '<=', now()));
    }

    /**
     * The links one user may see on the audit screen.
     *
     * A share link is a capability: anyone holding the URL can read the folder behind it,
     * with no account and no folder_access check. So the audit screen has to scope by
     * ownership rather than by the Share permission alone - otherwise someone granted one
     * folder could copy a link published for a folder they were never granted, and the
     * grant list would mean nothing.
     *
     * Admins see everything, which is what makes the screen an audit tool.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $query->where('user_id', $user->getKey());
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeForFolder(Builder $query, LibraryFolder|string $folder): void
    {
        $name = $folder instanceof LibraryFolder ? $folder->name : $folder;

        $query->where('folder', $name);
    }

    // ---------------------------------------------------------------- lifecycle

    /** Whether this link still resolves. */
    public function isLive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** Whether the link has been revoked or has expired. */
    public function isDead(): bool
    {
        return ! $this->isLive();
    }

    /** Stop the link resolving, keeping the row for the audit list. */
    public function revoke(): void
    {
        // Idempotent, and the first revocation's timestamp is the one that matters -
        // clicking "Expire now" twice must not rewrite the audit record.
        if ($this->revoked_at !== null) {
            return;
        }

        $this->forceFill(['revoked_at' => now()])->save();
    }

    /** Record a public hit. */
    public function recordAccess(): void
    {
        $this->forceFill(['last_accessed_at' => now()])->save();

        static::query()->whereKey($this->getKey())->increment('access_count');
    }

    // ------------------------------------------------------------- presentation

    /** How the remaining lifetime reads on the Share links screen. */
    public function expiry(): ExpiryLabel
    {
        return ExpiryLabel::for($this->expires_at, $this->revoked_at);
    }

    /** The public URL to hand out. */
    public function url(): string
    {
        return route('share.show', $this->token);
    }

    /** The URL as the design shows it in the table: no scheme, mono, truncated. */
    public function displayUrl(): string
    {
        return preg_replace('#^https?://#', '', $this->url()) ?? $this->url();
    }

    /** The folder this link points into. */
    public function libraryFolder(): LibraryFolder
    {
        return new LibraryFolder($this->folder);
    }

    /** The shared file, for a track share. Null for a folder share. */
    public function libraryFile(): ?LibraryFile
    {
        if ($this->type !== ShareType::Track || $this->filename === null) {
            return null;
        }

        return new LibraryFile($this->libraryFolder(), $this->filename);
    }

    /** A filesystem-safe name for the generated zip. */
    public function archiveFilename(): string
    {
        $stem = preg_replace('/[^\p{L}\p{N} _\-.]+/u', '', $this->name) ?: 'minizo-share';

        return trim($stem).'.zip';
    }
}
