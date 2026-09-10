<?php

namespace App\Support;

use App\Models\User;

/**
 * The personal token behind the feed API.
 *
 * Hashed with sha256, not Hash::make: the token is 256 bits of CSPRNG output, so a slow
 * hash defends against nothing, and a per-row salt would turn every request into a table
 * scan instead of one index lookup.
 */
class ApiToken
{
    /**
     * Prefix on the plaintext, so a stray token in a .env is recognisable and scannable.
     *
     * A constant rather than config: changing it would invalidate every live token.
     */
    public const PREFIX = 'mnz_';

    /** A new plaintext token. Not stored anywhere by itself. */
    public static function generate(): string
    {
        return self::PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /** Issue a token, replacing any existing one. Returns the plaintext, which is not recoverable later. */
    public static function issueFor(User $user): string
    {
        $plain = self::generate();

        $user->forceFill([
            'api_token' => self::hash($plain),
            'api_token_created_at' => now(),
            'api_token_last_used_at' => null,
        ])->save();

        return $plain;
    }

    /** The user holding this token. Ignores is_active, so the caller can answer 403 rather than 401. */
    public static function resolve(string $plain): ?User
    {
        if (! str_starts_with($plain, self::PREFIX)) {
            return null;
        }

        return User::query()->where('api_token', self::hash($plain))->first();
    }

    public static function revokeFor(User $user): void
    {
        $user->forceFill([
            'api_token' => null,
            'api_token_created_at' => null,
            'api_token_last_used_at' => null,
        ])->save();
    }
}
