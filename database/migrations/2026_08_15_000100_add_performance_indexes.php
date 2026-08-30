<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the sorts and filters the screens actually run.
 *
 * Each one below was matched against a real query. The original migrations indexed
 * what looked useful when the table was written; these cover what the finished screens
 * turned out to ask for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('download_jobs', function (Blueprint $table): void {
            // Recent activity: where user_id and status, order by finished_at. The
            // existing (user_id, status) matches the predicates and then leaves the
            // whole matching set to be filesorted before LIMIT 25 applies.
            $table->index(['user_id', 'status', 'finished_at'], 'download_jobs_recent_index');

            // scopeVisible, which is the first predicate of the queue widget - and the
            // queue widget re-runs every 3 seconds while a download is in flight.
            $table->index('hidden_at', 'download_jobs_hidden_at_index');

            // A folder rename rewrites every row that references the old name, and
            // minizo:library:audit scans on it. Both were full table scans.
            $table->index('folder', 'download_jobs_folder_index');
        });

        Schema::table('shares', function (Blueprint $table): void {
            // ShareService::revokeForFile / renameFile / moveFile all narrow by folder,
            // then type, then filename. Only folder was indexed, so the rest scanned -
            // on every file delete, rename and move.
            $table->index(['folder', 'type', 'filename'], 'shares_folder_type_filename_index');

            // The Share links screen's default view has no owner predicate, so the
            // (user_id, created_at) composite cannot serve its ordering.
            $table->index('created_at', 'shares_created_at_index');
        });

        Schema::table('users', function (Blueprint $table): void {
            // Four screens order the whole table by name: the Feed and Download preview
            // pickers, the Share links owner filter, and the Users list tiebreak.
            $table->index('name', 'users_name_index');
        });
    }

    public function down(): void
    {
        Schema::table('download_jobs', function (Blueprint $table): void {
            $table->dropIndex('download_jobs_recent_index');
            $table->dropIndex('download_jobs_hidden_at_index');
            $table->dropIndex('download_jobs_folder_index');
        });

        Schema::table('shares', function (Blueprint $table): void {
            $table->dropIndex('shares_folder_type_filename_index');
            $table->dropIndex('shares_created_at_index');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_name_index');
        });
    }
};
