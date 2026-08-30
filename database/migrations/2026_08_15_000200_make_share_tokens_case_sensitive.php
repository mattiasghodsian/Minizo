<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compare share tokens byte for byte.
 *
 * The column was declared without a collation, so on MySQL and MariaDB it inherits the
 * table default - one of the utf8mb4_*_ci family. Case-insensitive means "AbCd" and "abcd"
 * are the same token, which costs real entropy: the alphabet is 62 symbols but only 36
 * distinct values survive the comparison, so a 12-character token drops from ~71 bits to
 * ~62. It also makes the unique index reject a legitimately different token as a duplicate.
 *
 * ~62 bits is still far beyond guessing at 60 requests a minute, so this is not urgent -
 * it is just free. SQLite already compares strings as bytes, so it is a no-op there and
 * the tests see no change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->isMySql()) {
            return;
        }

        // Raw SQL rather than a Blueprint change: doctrine/dbal is not installed, and a
        // collation change on an indexed column is exactly the case ->change() cannot
        // express without it.
        DB::statement('ALTER TABLE `shares` MODIFY `token` VARCHAR(32) COLLATE utf8mb4_bin NOT NULL');
    }

    public function down(): void
    {
        if (! $this->isMySql()) {
            return;
        }

        DB::statement('ALTER TABLE `shares` MODIFY `token` VARCHAR(32) COLLATE utf8mb4_unicode_ci NOT NULL');
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
