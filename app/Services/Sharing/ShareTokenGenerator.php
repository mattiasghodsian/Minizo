<?php

namespace App\Services\Sharing;

use App\Models\Share;
use RuntimeException;

class ShareTokenGenerator
{
    /** Look-alikes are kept: these tokens are copied and pasted, never read aloud, so
     * dropping 0/O and l/1 would only shrink the space. */
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    /** A token unique across the shares table. */
    public function generate(): string
    {
        $length = max(8, (int) config('minizo.shares.token_length', 12));

        // Retried against the unique index rather than trusted. A collision at 71 bits
        // is vanishingly unlikely, but "vanishingly unlikely" and "handled" differ, and
        // the alternative is a 500 on someone's Generate-link click.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = $this->random($length);

            if (! Share::query()->where('token', $token)->exists()) {
                return $token;
            }
        }

        throw new RuntimeException('Could not generate a unique share token.');
    }

    /** A random string from the token alphabet. */
    private function random(int $length): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $token = '';

        for ($i = 0; $i < $length; $i++) {
            // random_int, not rand() or array_rand(): both are non-cryptographic, and
            // this value is a capability.
            $token .= $alphabet[random_int(0, $max)];
        }

        return $token;
    }
}
