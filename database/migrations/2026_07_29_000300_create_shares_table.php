<?php

use App\Enums\ShareType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public share links.
 *
 * The column that matters most is the one the design does not have. Its record shape
 * carries only a display `name`, which is enough to render a row but cannot resolve a
 * track: filenames are unique only within a folder, so "song.flac" identifies nothing
 * on its own.
 *
 * Splitting it into `folder` + nullable `filename` makes both hard requirements
 * one-liners:
 *
 *   folder renamed  ->  update(['folder' => $new])          links keep working
 *   folder deleted  ->  update(['revoked_at' => now()])     links die immediately,
 *                                                           audit trail survives
 *
 * `name` is kept alongside as the label to show, because a share of a folder that has
 * since been renamed should still say what it was called when it was shared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shares', function (Blueprint $table) {
            $table->id();

            /*
             * The owner. cascadeOnDelete rather than nullOnDelete: a link with no owner
             * cannot be audited, and the Share links screen is organised entirely by who
             * created what. Deleting an account takes its links down with it.
             */
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * The public URL segment. 12 characters of URL-safe randomness — the only
             * thing standing between the internet and this file, so it is generated from
             * random_bytes and unique-indexed rather than derived from the id.
             */
            $table->string('token', 32)->unique();

            $table->string('type', 16)->default(ShareType::Folder->value);

            // Display label, frozen at creation time. See the class docblock.
            $table->string('name');

            // The resolvable reference. filename is null for a folder share.
            $table->string('folder');
            $table->string('filename')->nullable();

            /*
             * Always set. Every link expires — ShareExpiry is a closed set of five
             * options precisely so there is no "never" case to reason about.
             */
            $table->timestamp('expires_at');

            // "Expire now", and the fan-out from a deleted folder. Distinct from
            // expires_at so the Share links screen can say Revoked rather than Expired.
            $table->timestamp('revoked_at')->nullable();

            // Access telemetry. Not required by the design, but "was this link ever
            // used?" is the first question anyone asks about a leak.
            $table->timestamp('last_accessed_at')->nullable();
            $table->unsignedInteger('access_count')->default(0);

            $table->timestamps();

            // Resolving a public request: token is already unique-indexed above.
            // The Share links screen, filtered by owner.
            $table->index(['user_id', 'created_at']);

            // The fan-out from a folder rename or delete.
            $table->index('folder');

            // Pruning, and the "N active links" badge.
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shares');
    }
};
