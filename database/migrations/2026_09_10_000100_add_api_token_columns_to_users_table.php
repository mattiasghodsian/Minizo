<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One API token per user, for the read-only feed endpoint.
 *
 * Columns rather than a personal_access_tokens table: the feature is one token
 * per account with one scope, so there is no many-to-one relationship to model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // sha256 hex of the token, never the plaintext. See App\Support\ApiToken.
            $table->char('api_token', 64)->nullable()->unique()->after('pagination_size');
            $table->timestamp('api_token_created_at')->nullable()->after('api_token');
            $table->timestamp('api_token_last_used_at')->nullable()->after('api_token_created_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Dropped before the column: SQLite cannot drop a column an index still covers.
            $table->dropUnique(['api_token']);
            $table->dropColumn(['api_token', 'api_token_created_at', 'api_token_last_used_at']);
        });
    }
};
