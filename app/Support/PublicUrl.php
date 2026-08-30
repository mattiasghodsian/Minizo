<?php

namespace App\Support;

final class PublicUrl
{
    /**
     * Whether a URL points somewhere on the public internet.
     *
     * The downloader hands its URL to yt-dlp, which is a full HTTP client running inside
     * the container. Without this check, "may download tracks" also means "may make the
     * server issue requests to anything it can reach" - the cloud metadata endpoint, a
     * database admin panel on the Docker network, an internal service on localhost. The
     * failure is reported back to the user, so an unguarded version is a usable scanner
     * rather than a blind one.
     *
     * Resolving here and again before the fetch is deliberate: a hostname that answers
     * with a public address now can answer with 127.0.0.1 a second later, and one check
     * cannot close that.
     */
    public static function isSafe(?string $url): bool
    {
        if (! is_string($url) || trim($url) === '') {
            return false;
        }

        $url = trim($url);

        if (! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        if (! (bool) config('minizo.downloads.block_private_hosts', true)) {
            return true;
        }

        foreach (self::addressesFor($host) as $address) {
            if (! self::isPublicAddress($address)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every address a host resolves to, or the host itself when it is already one.
     *
     * All of them are checked, not just the first: a name that returns one public and one
     * loopback address would otherwise pass here and be fetched from the private one.
     *
     * @return array<int, string>
     */
    private static function addressesFor(string $host): array
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA) ?: [];

        $addresses = [];

        foreach ($records as $record) {
            $addresses[] = $record['ip'] ?? $record['ipv6'] ?? null;
        }

        $addresses = array_values(array_filter($addresses));

        // A name that resolves to nothing is refused rather than allowed. yt-dlp would
        // fail on it anyway, and "could not resolve" must not become "not checked".
        return $addresses === [] ? ['0.0.0.0'] : $addresses;
    }

    /** Whether one literal address is routable on the public internet. */
    private static function isPublicAddress(string $address): bool
    {
        // An IPv4-mapped IPv6 address (::ffff:127.0.0.1) carries a v4 address inside a v6
        // one, and the v6 range checks below do not see it. Unwrap it first.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $address, $matches) === 1) {
            $address = $matches[1];
        }

        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
