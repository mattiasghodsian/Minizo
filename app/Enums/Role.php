<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case User = 'user';

    /** The role name as the Users screen shows it. */
    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::User => 'User',
        };
    }

    /** Whether the role carries administrative authority. */
    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }

    /** The design shows the role as a pill: accent for admins, neutral otherwise. */
    public function tone(): string
    {
        return match ($this) {
            self::Admin => 'accent',
            self::User => 'neutral',
        };
    }

    /**
     * Value => label, for a select or a radio group.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
