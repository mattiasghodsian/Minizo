<?php

namespace App\Exceptions;

use RuntimeException;

class TidalException extends RuntimeException
{
    /** No Tidal client id and secret are set. */
    public static function notConfigured(): self
    {
        return new self(__('Tidal is not configured. Add TIDAL_CLIENT_ID and TIDAL_CLIENT_SECRET to your .env — register an app at developer.tidal.com.'));
    }

    /** Credentials present but rejected. */
    public static function authenticationFailed(string $detail): self
    {
        return new self(__('Tidal rejected these credentials: :detail', ['detail' => $detail]));
    }

    /** Tidal did not answer a catalogue search. */
    public static function searchFailed(): self
    {
        return new self(__('Tidal did not respond to that search. Try again in a moment.'));
    }
}
