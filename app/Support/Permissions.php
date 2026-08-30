<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\User;

final readonly class Permissions
{
    /**
     * @param  array<string, bool>  $granted  Keyed by Permission::value.
     * @param  array<string, bool>  $globallyEnabled  Keyed by Permission::value; absent means enabled.
     */
    private function __construct(
        private array $granted,
        private array $globallyEnabled,
    ) {}

    /** The capabilities of one account. */
    public static function forUser(User $user, ?bool $sharingEnabled = null): self
    {
        $granted = [];

        foreach (Permission::cases() as $permission) {
            $granted[$permission->value] = (bool) $user->{$permission->column()};
        }

        return new self($granted, [
            Permission::Share->value => $sharingEnabled ?? Sharing::enabled(),
        ]);
    }

    /**
     * For tests and for previewing a permission set that is not persisted yet.
     *
     * @param  array<int, Permission>  $permissions
     */
    public static function of(array $permissions, bool $sharingEnabled = true): self
    {
        $granted = [];

        foreach (Permission::cases() as $permission) {
            $granted[$permission->value] = in_array($permission, $permissions, true);
        }

        return new self($granted, [Permission::Share->value => $sharingEnabled]);
    }

    /** No capabilities at all. */
    public static function none(bool $sharingEnabled = true): self
    {
        return self::of([], $sharingEnabled);
    }

    /** Every capability. */
    public static function all(bool $sharingEnabled = true): self
    {
        return self::of(Permission::cases(), $sharingEnabled);
    }

    /** The user's own flag, ignoring any global switch. */
    public function granted(Permission $permission): bool
    {
        return $this->granted[$permission->value] ?? false;
    }

    /** Whether the action may actually be performed. */
    public function effective(Permission $permission): bool
    {
        if (! $this->granted($permission)) {
            return false;
        }

        return $this->globallyEnabled[$permission->value] ?? true;
    }

    /** Granted to this user, but switched off instance-wide: the design's 35%-opacity, does-nothing-on-click state. */
    public function dimmed(Permission $permission): bool
    {
        return $this->granted($permission) && ! $this->effective($permission);
    }

    /** "Edit · Move · Download", or "View only" when nothing is granted. */
    public function summaryLabel(): string
    {
        $labels = [];

        foreach (Permission::cases() as $permission) {
            if ($this->granted($permission)) {
                // shortLabel(), not label(): six long labels are ~90 characters and the
                // design's column is 1.2fr, so the summary would ellipsise into uselessness.
                $labels[] = $permission->shortLabel();
            }
        }

        return $labels === [] ? 'View only' : implode(' · ', $labels);
    }

    /**
     * @return array<int, Permission>
     */
    public function grantedPermissions(): array
    {
        return array_values(array_filter(
            Permission::cases(),
            fn (Permission $permission): bool => $this->granted($permission),
        ));
    }

    /**
     * The column => bool map for persisting.
     *
     * @return array<string, bool>
     */
    public function toColumns(): array
    {
        $columns = [];

        foreach (Permission::cases() as $permission) {
            $columns[$permission->column()] = $this->granted($permission);
        }

        return $columns;
    }
}
