<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Artists the Feed knows about.
 *
 * Provider-agnostic on purpose. Tidal is what powers this today, but the schema says
 * so in a column rather than in its shape — so swapping or adding a provider is a
 * config change and some new service classes, not a migration. That matters because
 * this feature has already changed provider once: Last.fm was dropped for requiring
 * artist images to be scraped out of HTML after they removed them from the API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artists', function (Blueprint $table) {
            $table->id();

            /*
             * Where this artist came from, and their id THERE. Unique together rather
             * than provider_id alone: two providers can and do use the same id format,
             * and a global unique index would make importing the same artist from a
             * second provider fail for no reason.
             */
            $table->string('provider', 16)->default('tidal');
            $table->string('provider_id', 64);
            $table->unique(['provider', 'provider_id']);

            $table->string('name');

            /*
             * A lowercased copy of the name, for lookups.
             *
             * Necessary because MariaDB's default collation is case-insensitive while
             * SQLite's is not, so `where('name', $name)` behaves differently in
             * production and in the test suite. A derived column makes the comparison
             * explicit and identical everywhere.
             *
             * 191 keeps it inside MariaDB's 3072-byte index limit under utf8mb4.
             */
            $table->string('name_key', 191)->index();

            // text, not string: Tidal's image URLs are signed CDN links well over 255
            // characters.
            $table->text('image_url')->nullable();

            $table->unsignedInteger('popularity')->nullable();

            /*
             * When this artist's releases were last fetched. Null means never — which is
             * what the sync job looks for first, so a newly followed artist is filled in
             * before anyone else is refreshed.
             */
            $table->timestamp('last_synced_at')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artists');
    }
};
