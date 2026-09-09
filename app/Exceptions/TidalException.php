<?php

namespace App\Exceptions;

use RuntimeException;

class TidalException extends RuntimeException
{
    /**
     * Split in two: a searcher can act on "try again", not on "HTTP 401 from the catalogue".
     *
     * @param  string  $message  what an end user is shown
     * @param  string|null  $diagnostic  the operator's half, shown only to admins
     */
    public function __construct(string $message, public readonly ?string $diagnostic = null)
    {
        parent::__construct($message);
    }

    /** No Tidal client id and secret are set. */
    public static function notConfigured(): self
    {
        return new self(__('Tidal is not configured. Add TIDAL_CLIENT_ID and TIDAL_CLIENT_SECRET to your .env — register an app at developer.tidal.com.'));
    }

    /** Credentials rejected by the TOKEN endpoint. */
    public static function authenticationFailed(string $detail): self
    {
        return new self(__('Tidal rejected these credentials: :detail', ['detail' => $detail]));
    }

    /**
     * A token minted, then the catalogue refused it anyway.
     *
     * Kept apart from authenticationFailed: that is a credentials problem, this is an
     * entitlement one at developer.tidal.com.
     */
    public static function bearerRejected(int $status, ?string $detail): self
    {
        return new self(
            __('Tidal accepted these credentials but refused the request. The application registered at developer.tidal.com may not have access to the catalogue API.'),
            'HTTP '.$status.' from the catalogue with a token minted seconds earlier'
                .($detail !== null ? ' — '.$detail : ''),
        );
    }

    /** Tidal did not answer a catalogue search. Friendly half unchanged, so translations hold. */
    public static function searchFailed(?string $diagnostic = null): self
    {
        return new self(__('Tidal did not respond to that search. Try again in a moment.'), $diagnostic);
    }

    /** Tidal did not answer for an artist's releases, and nothing was collected. */
    public static function releasesFailed(string $artistProviderId, ?string $diagnostic = null): self
    {
        return new self(
            __('Tidal did not return releases for that artist. The next sync will try again.'),
            'artist '.$artistProviderId.($diagnostic !== null ? ': '.$diagnostic : ''),
        );
    }

    /** The friendly line plus the operator's half. */
    public function detailedMessage(): string
    {
        return $this->diagnostic === null
            ? $this->getMessage()
            : $this->getMessage().' ('.$this->diagnostic.')';
    }
}
