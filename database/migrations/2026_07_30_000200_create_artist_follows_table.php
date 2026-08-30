<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who follows whom.
 *
 * Follows are PER USER, which is the significant departure from the legacy app: there,
 * artists were global, so one person following someone changed everybody's feed. The
 * design keys the Feed by user id and gives admins a row of preview pills precisely
 * because each account has its own.
 *
 * `last_viewed_at` is what makes "new" work without storing a flag. The legacy schema
 * had a `seen` column that the model and the table disagreed about the name of
 * (`seen` vs `known`), and which needed writing on every render. Comparing a release's
 * first_seen_at against this timestamp answers the same question with nothing to keep
 * in sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artist_follows', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();

            // One follow per person per artist. Without this, a double-clicked Follow
            // button would quietly create two rows and the artist would appear twice.
            $table->unique(['user_id', 'artist_id']);

            // When this user last looked at their feed. Releases first seen after this
            // are "new" to them.
            $table->timestamp('last_viewed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_follows');
    }
};
