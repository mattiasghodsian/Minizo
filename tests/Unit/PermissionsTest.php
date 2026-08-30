<?php

namespace Tests\Unit;

use App\Enums\Permission;
use App\Support\Permissions;
use PHPUnit\Framework\TestCase;

class PermissionsTest extends TestCase
{
    public function test_granted_and_effective_agree_when_no_global_switch_is_off(): void
    {
        $permissions = Permissions::of([Permission::Edit, Permission::Download]);

        foreach ([Permission::Edit, Permission::Download] as $granted) {
            $this->assertTrue($permissions->granted($granted));
            $this->assertTrue($permissions->effective($granted));
            $this->assertFalse($permissions->dimmed($granted));
        }

        $this->assertFalse($permissions->granted(Permission::Delete));
        $this->assertFalse($permissions->effective(Permission::Delete));
    }

    public function test_the_global_switch_separates_granted_from_effective(): void
    {
        // This is the whole reason Permissions exists as a value object. Sharing is
        // still GRANTED to the user (so the control renders, dimmed), but not
        // EFFECTIVE (so no server check passes).
        $permissions = Permissions::of([Permission::Share], sharingEnabled: false);

        $this->assertTrue($permissions->granted(Permission::Share), 'control should still render');
        $this->assertFalse($permissions->effective(Permission::Share), 'action must be refused');
        $this->assertTrue($permissions->dimmed(Permission::Share), 'should be the 35%-opacity state');
    }

    public function test_a_disabled_switch_does_not_dim_a_permission_that_was_never_granted(): void
    {
        // Not granted means "do not render at all" - which is a different state
        // from "render dimmed", and conflating them would show controls to users
        // who were never given them.
        $permissions = Permissions::none(sharingEnabled: false);

        $this->assertFalse($permissions->granted(Permission::Share));
        $this->assertFalse($permissions->effective(Permission::Share));
        $this->assertFalse($permissions->dimmed(Permission::Share));
    }

    public function test_the_global_switch_only_affects_sharing(): void
    {
        $permissions = Permissions::all(sharingEnabled: false);

        foreach (Permission::cases() as $permission) {
            if ($permission === Permission::Share) {
                continue;
            }

            $this->assertTrue(
                $permissions->effective($permission),
                "{$permission->value} must be unaffected by the sharing switch",
            );
        }
    }

    public function test_only_sharing_declares_a_global_switch(): void
    {
        foreach (Permission::cases() as $permission) {
            $this->assertSame(
                $permission === Permission::Share,
                $permission->hasGlobalSwitch(),
                "{$permission->value} global-switch flag is wrong",
            );
        }
    }

    public function test_summary_label_matches_the_design(): void
    {
        // The design's compact form, derived from the permission key rather than its full
        // label. Six full labels are ~90 characters against a 1.2fr column.
        $this->assertSame(
            'Edit · Move · Download',
            Permissions::of([Permission::Edit, Permission::Move, Permission::Download])->summaryLabel(),
        );

        $this->assertSame('View only', Permissions::none()->summaryLabel());
    }

    public function test_the_full_labels_are_still_available_for_the_manage_modal(): void
    {
        // Where each toggle sits beside a description and has room for the long form.
        $this->assertSame('Edit metadata', Permission::Edit->label());
        $this->assertSame('Use downloader', Permission::Downloader->label());
    }

    public function test_summary_label_reflects_grants_not_the_global_switch(): void
    {
        // The Users table describes what an account was GIVEN. That should not
        // change because an admin flipped an instance-wide switch.
        $permissions = Permissions::of([Permission::Share], sharingEnabled: false);

        $this->assertSame('Share', $permissions->summaryLabel());
    }

    public function test_summary_label_is_in_declaration_order(): void
    {
        // Passed in reverse; must still read in the enum's order so the label is
        // stable regardless of how permissions were assigned.
        $permissions = Permissions::of([Permission::Move, Permission::Edit]);

        $this->assertSame('Edit · Move', $permissions->summaryLabel());
    }

    public function test_to_columns_maps_every_permission_to_its_column(): void
    {
        $columns = Permissions::of([Permission::Edit])->toColumns();

        $this->assertSame([
            'can_edit' => true,
            'can_move' => false,
            'can_download' => false,
            'can_delete' => false,
            'can_downloader' => false,
            'can_share' => false,
        ], $columns);
    }

    public function test_granted_permissions_returns_enum_cases(): void
    {
        $this->assertSame(
            [Permission::Edit, Permission::Delete],
            Permissions::of([Permission::Delete, Permission::Edit])->grantedPermissions(),
        );
    }

    public function test_permission_copy_matches_the_design_handoff(): void
    {
        // These strings appear verbatim in the Manage-user modal. The enum is the
        // single source, so this guards against a well-meaning reword drifting
        // from the handoff.
        $this->assertSame('Edit metadata', Permission::Edit->label());
        $this->assertSame('Search MusicBrainz and write tags', Permission::Edit->description());

        $this->assertSame('Permanently delete tracks from disk', Permission::Delete->description());
        $this->assertSame('Queue new downloads from the Download page', Permission::Downloader->description());
        $this->assertSame('Create expiring public links to tracks & folders', Permission::Share->description());
    }

    public function test_permission_columns_are_prefixed_can(): void
    {
        $this->assertSame(
            ['can_edit', 'can_move', 'can_download', 'can_delete', 'can_downloader', 'can_share'],
            Permission::columns(),
        );
    }
}
