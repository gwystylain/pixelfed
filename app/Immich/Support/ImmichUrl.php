<?php

namespace App\Immich\Support;

/**
 * Fork feature: Immich library. See docs/fork/IMMICH.md
 *
 * The server URL an account types in, made into something safe to build
 * requests from. Pure, so it is unit tested without an app.
 */
final class ImmichUrl
{
    /**
     * Normalise a typed server address, or null if it cannot be one.
     *
     * Accepts what people actually paste: a bare host and port, the address
     * of the web UI with a trailing slash, or the API base with `/api` on the
     * end. Returns the web root without a trailing slash, which is what the
     * client appends `/api/...` to.
     *
     * Rejected: anything not http(s), credentials in the URL (the API key is
     * the credential, and a URL is logged where a header is not), queries
     * and fragments.
     */
    public static function normalize(?string $input): ?string
    {
        $input = trim((string) $input);

        if ($input === '' || strlen($input) > 500) {
            return null;
        }

        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $input)) {
            $input = 'http://'.$input;
        }

        $parts = parse_url($input);

        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $host = strtolower($parts['host']);

        if (! self::isValidHost($host)) {
            return null;
        }

        $path = rtrim($parts['path'] ?? '', '/');

        // People paste the API base as often as the web UI's address.
        if (preg_match('#/api$#i', $path)) {
            $path = substr($path, 0, -4);
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port.$path;
    }

    /**
     * Whether a normalised URL's host is on the operator's allow-list.
     *
     * Entries are `host`, `host:port` or `*.domain`. An empty list allows
     * every host.
     *
     * @param  array<int, string>  $allowed
     */
    public static function hostAllowed(string $url, array $allowed): bool
    {
        if (empty($allowed)) {
            return true;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $port = parse_url($url, PHP_URL_PORT);
        $hostPort = $port ? $host.':'.$port : $host;

        foreach ($allowed as $entry) {
            $entry = strtolower(trim($entry));

            if ($entry === '') {
                continue;
            }

            if ($entry === $host || $entry === $hostPort) {
                return true;
            }

            if (str_starts_with($entry, '*.') && str_ends_with($host, substr($entry, 1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Same scheme, host and port. Used to refuse redirects that would carry
     * the API key header somewhere else: unlike Authorization, Guzzle does
     * not strip a custom header on a cross-host redirect.
     */
    public static function sameOrigin(string $a, string $b): bool
    {
        $origin = function (string $url): ?string {
            $p = parse_url($url);

            if (! is_array($p) || empty($p['host'])) {
                return null;
            }

            $scheme = strtolower($p['scheme'] ?? '');
            $port = $p['port'] ?? ($scheme === 'https' ? 443 : 80);

            return $scheme.'://'.strtolower($p['host']).':'.$port;
        };

        $oa = $origin($a);

        return $oa !== null && $oa === $origin($b);
    }

    protected static function isValidHost(string $host): bool
    {
        // An IPv6 literal, as parse_url leaves it: [::1]
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        return (bool) preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host);
    }
}
