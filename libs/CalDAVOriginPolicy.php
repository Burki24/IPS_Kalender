<?php

declare(strict_types=1);

namespace IPSKalender;

use InvalidArgumentException;

final class CalDAVOriginPolicy
{
    private const ICLOUD_CALDAV_HOST = 'caldav.icloud.com';

    private readonly string $serverUrl;
    private readonly string $scheme;
    private readonly string $host;
    private readonly int $port;
    private readonly bool $allowICloudShards;

    /**
     * Defines the trusted origin for a CalDAV account.
     *
     * iCloud shard hosts are permitted only when the configured server itself belongs to
     * the official iCloud CalDAV host family over HTTPS on port 443.
     *
     * @param string $serverUrl Configured CalDAV server URL.
     */
    public function __construct(string $serverUrl)
    {
        $serverUrl = trim($serverUrl);
        if ($serverUrl === '') {
            throw new InvalidArgumentException('No CalDAV server URL was configured.');
        }
        if (!preg_match('#^https?://#i', $serverUrl)) {
            $serverUrl = 'https://' . $serverUrl;
        }

        $parts = parse_url($serverUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('The CalDAV server URL is invalid.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('The CalDAV server URL is invalid.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Credentials and fragments are not allowed in the CalDAV server URL.');
        }

        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : self::defaultPort($scheme);

        $this->serverUrl = $serverUrl;
        $this->scheme = $scheme;
        $this->host = $host;
        $this->port = $port;
        $this->allowICloudShards = $scheme === 'https'
            && $port === 443
            && self::isICloudCalDAVHost($host);
    }

    /**
     * Returns the normalized server URL used to establish the trust boundary.
     */
    public function getServerUrl(): string
    {
        return $this->serverUrl;
    }

    /**
     * Checks whether an absolute URL belongs to an origin trusted for this account.
     */
    public function isAllowedUrl(string $url): bool
    {
        $parts = parse_url(trim($url));
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : self::defaultPort($scheme);
        if ($scheme === $this->scheme && $host === $this->host && $port === $this->port) {
            return true;
        }

        return $this->allowICloudShards
            && $scheme === 'https'
            && $port === 443
            && self::isICloudCalDAVHost($host);
    }

    /**
     * Resolves a relative DAV reference against an absolute base URL.
     *
     * The returned URL is not implicitly trusted; callers must validate it with isAllowedUrl().
     *
     * @param string $baseUrl   Absolute base URL.
     * @param string $reference Absolute or relative DAV reference.
     */
    public function resolveUrl(string $baseUrl, string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            throw new InvalidArgumentException('Could not resolve a CalDAV URL.');
        }

        if (preg_match('#^https?://#i', $reference)) {
            return $reference;
        }
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $reference) === 1) {
            throw new InvalidArgumentException('Could not resolve a CalDAV URL.');
        }

        $base = parse_url($baseUrl);
        if ($base === false || !isset($base['scheme'], $base['host'])) {
            throw new InvalidArgumentException('Could not resolve a CalDAV URL.');
        }

        $authority = strtolower((string) $base['scheme']) . '://' . (string) $base['host'];
        if (isset($base['port'])) {
            $authority .= ':' . (int) $base['port'];
        }

        if (str_starts_with($reference, '//')) {
            return strtolower((string) $base['scheme']) . ':' . $reference;
        }
        if (str_starts_with($reference, '/')) {
            return $authority . self::normalizePath($reference);
        }
        if (str_starts_with($reference, '?')) {
            return $authority . (string) ($base['path'] ?? '/') . $reference;
        }

        $basePath = (string) ($base['path'] ?? '/');
        $directory = str_ends_with($basePath, '/') ? $basePath : dirname($basePath) . '/';

        return $authority . self::normalizePath($directory . $reference);
    }

    private static function defaultPort(string $scheme): int
    {
        return $scheme === 'https' ? 443 : 80;
    }

    private static function isICloudCalDAVHost(string $host): bool
    {
        return $host === self::ICLOUD_CALDAV_HOST
            || preg_match('/^p\d+-caldav\.icloud\.com$/i', $host) === 1;
    }

    private static function normalizePath(string $path): string
    {
        $query = '';
        $queryPosition = strpos($path, '?');
        if ($queryPosition !== false) {
            $query = substr($path, $queryPosition);
            $path = substr($path, 0, $queryPosition);
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        $normalized = '/' . implode('/', $segments);
        if (str_ends_with($path, '/') && $normalized !== '/') {
            $normalized .= '/';
        }

        return $normalized . $query;
    }
}
