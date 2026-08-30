<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Development seed data.
 *
 * Only ever runs in development — docker/start-container gates seeding on
 * MINIZO_AUTO_SEED, which the production image leaves unset. That matters,
 * because these accounts have known passwords.
 *
 * Three accounts rather than one, so the permission states in the design can
 * actually be seen in the UI: an admin, a restricted user, and a view-only user.
 * The Users screen and the 35%-opacity dimming rule are impossible to eyeball with
 * a single all-powerful account.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::factory()->admin()->create([
            'name' => 'minizo',
            'email' => 'minizo@minizo.com',
            'password' => bcrypt('minizo'),
        ]);

        // Can tag and download in two folders; cannot move, delete or share, and
        // any download they queue is forced into Unprocessed for review.
        User::factory()
            ->withPermissions([Permission::Edit, Permission::Download, Permission::Downloader])
            ->withFolders(['Spanish', 'Folk'])
            ->lockedDownloader('Unprocessed')
            ->create([
                'name' => 'maria',
                'email' => 'maria@minizo.com',
                'password' => bcrypt('maria'),
            ]);

        // The "View only" summary: one folder, no permissions at all.
        User::factory()
            ->viewOnly()
            ->withFolders(['Asian'])
            ->create([
                'name' => 'kev',
                'email' => 'kev@minizo.com',
                'password' => bcrypt('kev'),
            ]);
    }
}
