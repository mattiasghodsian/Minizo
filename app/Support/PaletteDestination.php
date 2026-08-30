<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\User;

final readonly class PaletteDestination
{
    /**
     * One screen the command palette can jump to.
     *
     * @param  string  $keywords  extra words that should match this entry, for the names
     *                            people reach for that are not on the label
     */
    private function __construct(
        public string $label,
        public string $icon,
        public string $route,
        public string $keywords = '',
    ) {}

    /**
     * The destinations one user may reach.
     *
     * Gated with the same checks the sidebar uses, so the palette can never offer a screen
     * that would 403 on arrival. Share links reads granted() rather than effective() for
     * the same reason SharePolicy does: turning the instance switch off must not hide the
     * screen for finding links that are still live.
     *
     * @return array<int, self>
     */
    public static function forUser(User $user): array
    {
        $destinations = [
            new self(__('Download'), 'arrow-down-tray', 'download', 'queue add url youtube'),
            new self(__('Feed'), 'rss', 'feed', 'artists releases follow new'),
        ];

        if ($user->permissions()->granted(Permission::Share)) {
            $destinations[] = new self(__('Share links'), 'link', 'shares', 'public url token expire');
        }

        if ($user->can('manage-users')) {
            $destinations[] = new self(__('Users'), 'users', 'users', 'accounts roles permissions admin');
        }

        $destinations[] = new self(
            __('Settings'),
            'cog-6-tooth',
            'settings.edit',
            'profile password two-factor 2fa passkeys account delete',
        );

        return $destinations;
    }

    /** Whether a query matches this entry's label or its keywords. */
    public function matches(string $query): bool
    {
        if ($query === '') {
            return true;
        }

        return str_contains(mb_strtolower($this->label.' '.$this->keywords), mb_strtolower($query));
    }

    public function url(): string
    {
        return route($this->route);
    }
}
