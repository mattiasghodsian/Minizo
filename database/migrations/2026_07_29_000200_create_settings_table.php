<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instance settings an administrator changes at runtime.
 *
 * A table rather than `.env` for one specific reason: the design puts the public
 * sharing switch in the UI, and a value in `.env` cannot be flipped by clicking a
 * toggle — it needs a file edit, a config-cache clear and usually a container
 * restart. Anything an admin is shown a control for has to live somewhere writable.
 *
 * Deliberately not a settings *package*. There is one key today, and the wrapper that
 * caches it (App\Support\Settings) is shorter than the configuration a package would
 * need.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            // The key IS the primary key: there is exactly one row per setting, and a
            // surrogate id would only make it possible to have two.
            $table->string('key', 191)->primary();

            // Text, and always read through a cast in App\Support\Settings. Storing a
            // typed column per setting would mean a migration for every new one.
            $table->text('value')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
