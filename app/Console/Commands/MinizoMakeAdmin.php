<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\NewUserDefaults;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class MinizoMakeAdmin extends Command
{
    protected $signature = 'minizo:make-admin
                            {email : The account to create or promote}
                            {--name= : Display name, when creating}
                            {--password= : Password, when creating (prompted if omitted)}';

    protected $description = 'Create an administrator, or promote an existing user to one';

    /** Promote an existing account to admin, or create one. */
    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));

        $user = User::where('email', $email)->first();

        if ($user !== null) {
            return $this->promote($user);
        }

        return $this->createAdmin($email);
    }

    /** Give an existing account the admin role and full access. */
    private function promote(User $user): int
    {
        $wasAdmin = $user->isAdmin();

        NewUserDefaults::promoteToAdmin($user);

        $this->components->info($wasAdmin
            ? "Refreshed full access for {$user->email}."
            : "Promoted {$user->email} to administrator.");

        // Worth saying out loud: promoting also reactivates and re-grants
        // everything, which is the point when recovering a locked-out instance.
        $this->line('  <fg=gray>role=admin · all permissions · all folders · active</>');

        return self::SUCCESS;
    }

    /** Create an admin account with an unusable password, to be set by reset. */
    private function createAdmin(string $email): int
    {
        $name = (string) ($this->option('name') ?: $this->ask('Display name'));

        $password = (string) ($this->option('password') ?: $this->secret('Password'));

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                // Password::default() is min-12-with-symbols in production and
                // unconstrained locally (see AppServiceProvider), so a dev can use
                // a short password while a real install cannot.
                'password' => ['required', 'string', Password::default()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        // Mark verified: there is no inbox to click through on a self-hosted
        // install, and the dashboard sits behind the `verified` middleware.
        $user->forceFill(['email_verified_at' => now()])->save();

        NewUserDefaults::promoteToAdmin($user);

        $this->components->info("Created administrator {$user->email}.");

        return self::SUCCESS;
    }
}
