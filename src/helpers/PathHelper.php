<?php

declare(strict_types=1);

namespace justinholtweb\telescope\helpers;

/**
 * Turns Craft element URLs into the `pagePath` values GA4 actually recorded.
 *
 * Getting this wrong is the single most common reason a report comes back empty
 * — a trailing slash or a stray query string is enough for an EXACT match to
 * miss every hit — so the normalisation lives here and is covered by tests.
 */
final class PathHelper
{
    /**
     * Extract the GA4 `pagePath` from a full URL.
     *
     * @param bool $keepQueryString whether `?foo=bar` should stay on the path
     *                              (GA4 records it, but per-entry reports
     *                              almost always want it collapsed away)
     * @return string|null null when the URL has no usable path
     */
    public static function pathFromUrl(?string $url, bool $keepQueryString = false): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);

        if ($url === '') {
            return null;
        }

        // parse_url() chokes on a bare host with no scheme ("example.com/foo"),
        // reading the whole thing as a path. Adding a scheme makes it behave.
        if (!str_contains($url, '://') && !str_starts_with($url, '/')) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        $path = $parts['path'] ?? '';

        if ($path === '') {
            $path = '/';
        }

        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $path = self::normalize($path);

        if ($keepQueryString && !empty($parts['query'])) {
            $path .= '?' . $parts['query'];
        }

        return $path;
    }

    /**
     * Collapse a path to its canonical form: decoded, no duplicate slashes, no
     * trailing slash (except the site root, which is always `/`).
     */
    public static function normalize(string $path): string
    {
        $path = rawurldecode($path);
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;

        if ($path === '' || $path === '/') {
            return '/';
        }

        return rtrim($path, '/') ?: '/';
    }

    /**
     * The hostname of a URL, lowercased and stripped of `www.`.
     */
    public static function hostFromUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $url = trim($url);

        // A bare path has no host to find; prefixing a scheme would turn
        // "/about" into the hostname "about".
        if (str_starts_with($url, '/')) {
            return null;
        }

        if (!str_contains($url, '://')) {
            $url = 'https://' . $url;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return null;
        }

        return self::normalizeHost($host);
    }

    public static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * Whether a GA4 `pageReferrer` value points back at one of our own hosts.
     *
     * @param list<string> $knownHosts hostnames belonging to this installation
     */
    public static function isInternalReferrer(string $referrer, array $knownHosts): bool
    {
        $referrerHost = self::hostFromUrl($referrer);

        if ($referrerHost === null) {
            return false;
        }

        foreach ($knownHosts as $host) {
            $host = self::normalizeHost($host);

            if ($host === '') {
                continue;
            }

            if ($referrerHost === $host || str_ends_with($referrerHost, '.' . $host)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A short, readable label for a referrer URL: internal referrers show just
     * their path, external ones show host + path.
     *
     * @param list<string> $knownHosts
     */
    public static function referrerLabel(string $referrer, array $knownHosts): string
    {
        $referrer = trim($referrer);

        if ($referrer === '' || $referrer === '(not set)' || $referrer === '(direct)') {
            return 'Direct / none';
        }

        $host = self::hostFromUrl($referrer);

        if ($host === null) {
            return $referrer;
        }

        $path = self::pathFromUrl($referrer) ?? '/';

        if (self::isInternalReferrer($referrer, $knownHosts)) {
            return $path;
        }

        return $path === '/' ? $host : $host . $path;
    }
}
