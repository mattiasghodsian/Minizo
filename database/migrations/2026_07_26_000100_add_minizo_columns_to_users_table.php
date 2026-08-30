<?php

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // string(), not enum(): the tests run on SQLite, where a real enum
            // column cannot be altered later without recreating the table.
            $table->string('role', 16)->default(Role::User->value)->index()->after('email');

            // "Disabled users cannot log in." Indexed because it filters listings.
            $table->boolean('is_active')->default(true)->index()->after('role');

            /*
             * Folder access: a JSON array of folder names, where ["*"] means all.
             *
             * Folders are directories on disk with no database rows of their own,
             * so access can only be stored by name — there is nothing to key a
             * pivot table against. That means a rename has to fan out to this
             * column; FolderService owns that.
             *
             * Nullable rather than defaulted: MariaDB will not accept a DEFAULT on
             * a JSON/TEXT column. Null is read as [] — no access — so a user
             * created without explicit folders sees nothing rather than
             * everything.
             */
            $table->json('folder_access')->nullable()->after('is_active');

            // One boolean per permission. Six columns instead of a pivot because
            // the set is fixed and closed, the Manage-user modal edits all six at
            // once, and this keeps every authorization check a single row read.
            foreach (Permission::cases() as $permission) {
                $table->boolean($permission->column())->default(false);
            }

            /*
             * Downloader restrictions. Not permissions — constraints on the
             * arguments of an action: "new downloads from this user are forced
             * into this folder and this format". Null means unrestricted.
             *
             * The format lock is stored but has no UI while Minizo is FLAC-only,
             * since there is nothing to choose between. The column stays so the
             * feature does not need a migration when a second format lands.
             */
            $table->string('download_folder_lock')->nullable();
            $table->string('download_format_lock', 16)->nullable();

            // Per-user page size, editable in Settings. Bounded on read (see
            // config('minizo.pagination')) so a stored value cannot be used to
            // request an unbounded page.
            $table->unsignedSmallInteger('pagination_size')
                ->default(config('minizo.pagination.default', 50));
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'role',
                'is_active',
                'folder_access',
                ...Permission::columns(),
                'download_folder_lock',
                'download_format_lock',
                'pagination_size',
            ]);
        });
    }
};
