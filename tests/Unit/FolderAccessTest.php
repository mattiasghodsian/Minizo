<?php

namespace Tests\Unit;

use App\Support\FolderAccess;
use PHPUnit\Framework\TestCase;

class FolderAccessTest extends TestCase
{
    public function test_all_access_allows_anything_including_folders_that_do_not_exist_yet(): void
    {
        $access = FolderAccess::all();

        $this->assertTrue($access->allowsAll());
        $this->assertTrue($access->allows('Spanish'));

        // The "*" sentinel exists precisely so an all-access user gains folders
        // created after their account was set up. A materialised list would not.
        $this->assertTrue($access->allows('CreatedTomorrow'));
        $this->assertSame(['*'], $access->toArray());
    }

    public function test_null_folder_access_means_no_access(): void
    {
        // Least privilege: the column is nullable because MariaDB rejects a
        // DEFAULT on JSON, so null must read as "nothing", never "everything".
        $access = FolderAccess::of(null);

        $this->assertTrue($access->isEmpty());
        $this->assertFalse($access->allows('Spanish'));
        $this->assertSame([], $access->toArray());
    }

    public function test_explicit_list_allows_only_those_folders(): void
    {
        $access = FolderAccess::of(['Spanish', 'Folk']);

        $this->assertFalse($access->allowsAll());
        $this->assertTrue($access->allows('Spanish'));
        $this->assertTrue($access->allows('Folk'));
        $this->assertFalse($access->allows('GameBT'));
    }

    public function test_matching_is_case_insensitive_and_whitespace_tolerant(): void
    {
        // A Windows host is case-insensitive while the Linux container is not, so
        // "Spanish" and "spanish" may be the same directory. Treating them as
        // equal is the only behaviour that never grants access by accident.
        $access = FolderAccess::of(['Spanish']);

        $this->assertTrue($access->allows('spanish'));
        $this->assertTrue($access->allows('SPANISH'));
        $this->assertTrue($access->allows('  Spanish  '));
    }

    public function test_the_sentinel_anywhere_in_the_list_means_all(): void
    {
        $access = FolderAccess::of(['Spanish', '*']);

        $this->assertTrue($access->allowsAll());
        $this->assertSame(['*'], $access->toArray());
    }

    public function test_blank_entries_are_discarded(): void
    {
        $access = FolderAccess::of(['Spanish', '', '   ']);

        $this->assertSame(['Spanish'], $access->toArray());
    }

    public function test_duplicates_are_collapsed_case_insensitively(): void
    {
        $access = FolderAccess::of(['Spanish', 'spanish', 'SPANISH']);

        $this->assertCount(1, $access->toArray());
    }

    public function test_filter_keeps_only_allowed_folders_in_caller_order(): void
    {
        $access = FolderAccess::of(['Folk', 'Spanish']);

        $this->assertSame(
            ['Spanish', 'Folk'],
            $access->filter(['Spanish', 'GameBT', 'Folk', 'Asian']),
        );
    }

    public function test_filter_with_all_access_keeps_everything(): void
    {
        $this->assertSame(
            ['Spanish', 'GameBT'],
            FolderAccess::all()->filter(['Spanish', 'GameBT']),
        );
    }

    public function test_revoking_a_folder_from_an_all_access_user_expands_the_sentinel(): void
    {
        // This is the design's chip behaviour: toggling one folder off an
        // "All folders" user turns their access into an explicit list of
        // everything else. Getting this wrong either revokes nothing or revokes
        // everything.
        $all = ['Asian', 'Folk', 'GameBT', 'Spanish'];

        $access = FolderAccess::all()->withoutFolder('Folk', $all);

        $this->assertFalse($access->allowsAll());
        $this->assertSame(['Asian', 'GameBT', 'Spanish'], $access->toArray());
        $this->assertFalse($access->allows('Folk'));
    }

    public function test_revoking_from_an_explicit_list_does_not_reintroduce_other_folders(): void
    {
        $all = ['Asian', 'Folk', 'GameBT', 'Spanish'];

        $access = FolderAccess::of(['Folk', 'Spanish'])->withoutFolder('Folk', $all);

        $this->assertSame(['Spanish'], $access->toArray());
    }

    public function test_revoking_the_last_folder_leaves_no_access_not_all_access(): void
    {
        $access = FolderAccess::of(['Folk'])->withoutFolder('Folk', ['Folk']);

        $this->assertTrue($access->isEmpty());
        $this->assertFalse($access->allowsAll());
    }

    public function test_granting_a_folder_is_idempotent_and_leaves_all_access_alone(): void
    {
        $access = FolderAccess::of(['Spanish'])->withFolder('Folk');
        $this->assertSame(['Folk', 'Spanish'], $access->toArray());

        $this->assertSame(['Folk', 'Spanish'], $access->withFolder('folk')->toArray());

        $this->assertTrue(FolderAccess::all()->withFolder('Folk')->allowsAll());
    }

    public function test_rename_follows_an_in_app_folder_rename(): void
    {
        // Renaming inside Minizo must not silently revoke access. Renaming on the
        // host cannot be followed - that is a documented boundary.
        $access = FolderAccess::of(['Spanish', 'Folk'])->renameFolder('Folk', 'Folk Music');

        $this->assertTrue($access->allows('Folk Music'));
        $this->assertFalse($access->allows('Folk'));
    }

    public function test_rename_is_a_no_op_for_all_access_or_an_unheld_folder(): void
    {
        $this->assertTrue(FolderAccess::all()->renameFolder('Folk', 'X')->allowsAll());

        $access = FolderAccess::of(['Spanish']);
        $this->assertSame(['Spanish'], $access->renameFolder('Folk', 'X')->toArray());
    }

    public function test_intersect_drops_folders_that_no_longer_exist(): void
    {
        $access = FolderAccess::of(['Spanish', 'Deleted'])->intersect(['Spanish', 'Folk']);

        $this->assertSame(['Spanish'], $access->toArray());
    }

    public function test_intersect_leaves_all_access_untouched(): void
    {
        $this->assertTrue(FolderAccess::all()->intersect(['Spanish'])->allowsAll());
    }

    public function test_summary_label_matches_the_design(): void
    {
        $this->assertSame('All folders', FolderAccess::all()->summaryLabel());
        $this->assertSame('1 folder', FolderAccess::of(['Spanish'])->summaryLabel());
        $this->assertSame('2 folders', FolderAccess::of(['Spanish', 'Folk'])->summaryLabel());
        $this->assertSame('0 folders', FolderAccess::none()->summaryLabel());
    }
}
