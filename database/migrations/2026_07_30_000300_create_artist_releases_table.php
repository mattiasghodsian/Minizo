<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A followed artist's releases.
 *
 * The unique index on (artist_id, provider_id) is the fix for a real legacy defect: the
 * feed importer called firstOrCreate() on a table with no unique constraint behind it,
 * so two concurrent syncs of the same artist inserted every release twice — and because
 * nothing enforced it at the database level, the duplicates were permanent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artist_releases', function (Blueprint $table) {
            $table->id();

            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();

            // The release's id at the provider. Scoped to the artist rather than global,
            // for the same reason artists.provider_id is scoped to the provider.
            $table->string('provider_id', 64);
            $table->unique(['artist_id', 'provider_id']);

            $table->string('title');

            // album | ep | single | compilation | other — see App\Enums\ReleaseType.
            $table->string('release_type', 16)->nullable();

            /*
             * A date, not a timestamp: a release date has no meaningful time of day, and
             * storing one would invite timezone bugs where a release appears to arrive a
             * day early or late depending on the server's zone.
             */
            $table->date('released_on')->nullable()->index();

            $table->text('cover_url')->nullable();
            $table->text('link')->nullable();

            /*
             * When MINIZO first saw this release — not when it came out.
             *
             * These are different questions and both matter. released_on orders the feed;
             * first_seen_at is what "new" is computed from, because a release from three
             * months ago that we only just discovered is new TO THIS INSTANCE. Comparing
             * released_on against a user's last visit would silently hide exactly the
             * back-catalogue additions people follow artists to notice.
             */
            $table->timestamp('first_seen_at')->index();

            $table->timestamps();

            // The Feed's main query: one artist's releases, newest first.
            $table->index(['artist_id', 'released_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_releases');
    }
};
