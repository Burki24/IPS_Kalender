<?php

declare(strict_types=1);

namespace IPSKalender;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

require_once __DIR__ . '/CalendarProviderInterface.php';
require_once __DIR__ . '/CalendarHttpClient.php';

final class CalDAVProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 0)
    {
        parent::__construct($message);
    }
}

final class CalDAVProvider implements CalendarProviderInterface
{
    private const DAV_NAMESPACE = 'DAV:';
    private const CALDAV_NAMESPACE = 'urn:ietf:params:xml:ns:caldav';
    private const APPLE_NAMESPACE = 'http://apple.com/ns/ical/';

    public function __construct(
        private readonly CalendarHttpClientInterface $httpClient,
        private readonly string $serverUrl
    ) {
    }

    public function testConnection(): array
    {
        $calendars = $this->getCalendars();

        return [
            'success'       => true,
            'calendarCount' => count($calendars),
            'message'       => 'Connection successful.'
        ];
    }

    public function getCalendars(): array
    {
        $entryUrls = $this->getEntryUrls($this->serverUrl);
        $lastException = null;

        foreach ($entryUrls as $entryUrl) {
            try {
                $principalUrl = $this->discoverPrincipal($entryUrl);
                $homeSetUrl = $this->discoverCalendarHomeSet($principalUrl);

                return $this->discoverCalendars($homeSetUrl);
            } catch (CalDAVProviderException $exception) {
                $lastException = $exception;
            }
        }

        throw $lastException ?? new CalDAVProviderException('CalDAV discovery failed.');
    }

    private function discoverPrincipal(string $url): string
    {
        $response = $this->propfind(
            $url,
            0,
            '<?xml version="1.0" encoding="utf-8" ?>' .
            '<d:propfind xmlns:d="DAV:"><d:prop><d:current-user-principal/></d:prop></d:propfind>'
        );

        $document = $this->parseXml($response->body);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('d', self::DAV_NAMESPACE);
        $href = $this->firstNodeValue($xpath, '//d:current-user-principal/d:href');

        if ($href === '') {
            throw new CalDAVProviderException('The CalDAV server did not return a current-user-principal.');
        }

        return $this->resolveUrl($response->effectiveUrl, $href);
    }

    private function discoverCalendarHomeSet(string $principalUrl): string
    {
        $response = $this->propfind(
            $principalUrl,
            0,
            '<?xml version="1.0" encoding="utf-8" ?>' .
            '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">' .
            '<d:prop><c:calendar-home-set/></d:prop></d:propfind>'
        );

        $document = $this->parseXml($response->body);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('d', self::DAV_NAMESPACE);
        $xpath->registerNamespace('c', self::CALDAV_NAMESPACE);
        $href = $this->firstNodeValue($xpath, '//c:calendar-home-set/d:href');

        if ($href === '') {
            throw new CalDAVProviderException('The CalDAV server did not return a calendar-home-set.');
        }

        return $this->resolveUrl($response->effectiveUrl, $href);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function discoverCalendars(string $homeSetUrl): array
    {
        $response = $this->propfind(
            $homeSetUrl,
            1,
            '<?xml version="1.0" encoding="utf-8" ?>' .
            '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:a="http://apple.com/ns/ical/">' .
            '<d:prop>' .
            '<d:resourcetype/><d:displayname/><d:getetag/><d:sync-token/><d:current-user-privilege-set/>' .
            '<c:calendar-description/><c:supported-calendar-component-set/><a:calendar-color/>' .
            '</d:prop></d:propfind>'
        );

        $document = $this->parseXml($response->body);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('d', self::DAV_NAMESPACE);
        $xpath->registerNamespace('c', self::CALDAV_NAMESPACE);
        $xpath->registerNamespace('a', self::APPLE_NAMESPACE);

        $calendars = [];
        $responses = $xpath->query('//d:multistatus/d:response');
        if ($responses === false) {
            return [];
        }

        foreach ($responses as $calendarResponse) {
            if (!$calendarResponse instanceof DOMElement) {
                continue;
            }

            $calendarTypeNodes = $xpath->query('.//d:resourcetype/c:calendar', $calendarResponse);
            if ($calendarTypeNodes === false || $calendarTypeNodes->length === 0) {
                continue;
            }

            $href = $this->firstNodeValue($xpath, './d:href', $calendarResponse);
            if ($href === '') {
                continue;
            }

            $components = [];
            $componentNodes = $xpath->query('.//c:supported-calendar-component-set/c:comp', $calendarResponse);
            if ($componentNodes !== false) {
                foreach ($componentNodes as $componentNode) {
                    if ($componentNode instanceof DOMElement) {
                        $name = strtoupper($componentNode->getAttribute('name'));
                        if ($name !== '') {
                            $components[] = $name;
                        }
                    }
                }
            }

            if ($components !== [] && !in_array('VEVENT', $components, true)) {
                continue;
            }

            $privileges = [];
            $privilegeNodes = $xpath->query('.//d:current-user-privilege-set/d:privilege/*', $calendarResponse);
            if ($privilegeNodes !== false) {
                foreach ($privilegeNodes as $privilegeNode) {
                    $privileges[] = $privilegeNode->localName;
                }
            }

            $canWrite = count(array_intersect($privileges, ['write', 'write-content', 'bind', 'unbind'])) > 0;
            $name = $this->firstNodeValue($xpath, './/d:displayname', $calendarResponse);
            $url = $this->resolveUrl($response->effectiveUrl, $href);

            $calendars[] = [
                'id'           => hash('sha256', $url),
                'providerId'   => $url,
                'url'          => $url,
                'name'         => $name !== '' ? $name : basename(rtrim(rawurldecode($href), '/')),
                'description'  => $this->firstNodeValue($xpath, './/c:calendar-description', $calendarResponse),
                'color'        => $this->normalizeColor($this->firstNodeValue($xpath, './/a:calendar-color', $calendarResponse)),
                'etag'         => $this->firstNodeValue($xpath, './/d:getetag', $calendarResponse),
                'syncToken'    => $this->firstNodeValue($xpath, './/d:sync-token', $calendarResponse),
                'components'   => array_values(array_unique($components)),
                'capabilities' => [
                    'read'   => true,
                    'create' => $canWrite,
                    'update' => $canWrite,
                    'delete' => $canWrite
                ]
            ];
        }

        usort($calendars, static fn(array $left, array $right): int => strcasecmp((string) $left['name'], (string) $right['name']));

        return $calendars;
    }

    private function propfind(string $url, int $depth, string $body): CalendarHttpResponse
    {
        $response = $this->httpClient->request(
            'PROPFIND',
            $url,
            [
                'Accept'       => 'application/xml, text/xml',
                'Content-Type' => 'application/xml; charset=utf-8',
                'Depth'        => (string) $depth
            ],
            $body
        );

        if ($response->statusCode === 401 || $response->statusCode === 403) {
            throw new CalDAVProviderException('Authentication failed or calendar access was denied.', $response->statusCode);
        }

        if ($response->statusCode !== 207) {
            throw new CalDAVProviderException(
                sprintf('Unexpected CalDAV response: HTTP %d.', $response->statusCode),
                $response->statusCode
            );
        }

        return $response;
    }

    private function parseXml(string $xml): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new CalDAVProviderException('The CalDAV server returned invalid XML.');
        }

        return $document;
    }

    private function firstNodeValue(DOMXPath $xpath, string $expression, ?DOMNode $contextNode = null): string
    {
        $nodes = $xpath->query($expression, $contextNode);
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }

        return trim((string) $nodes->item(0)?->textContent);
    }

    /**
     * @return list<string>
     */
    private function getEntryUrls(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            throw new CalDAVProviderException('No CalDAV server URL was configured.');
        }

        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new CalDAVProviderException('The CalDAV server URL is invalid.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new CalDAVProviderException('Credentials and fragments are not allowed in the CalDAV server URL.');
        }

        $path = $parts['path'] ?? '';
        if ($path === '' || $path === '/') {
            $origin = $parts['scheme'] . '://' . $parts['host'];
            if (isset($parts['port'])) {
                $origin .= ':' . $parts['port'];
            }

            return [$origin . '/.well-known/caldav', $origin . '/'];
        }

        return [$url];
    }

    private function resolveUrl(string $baseUrl, string $reference): string
    {
        if (preg_match('#^https?://#i', $reference)) {
            return $reference;
        }

        $base = parse_url($baseUrl);
        if ($base === false || !isset($base['scheme'], $base['host'])) {
            throw new CalDAVProviderException('Could not resolve a CalDAV URL.');
        }

        $authority = $base['scheme'] . '://' . $base['host'];
        if (isset($base['port'])) {
            $authority .= ':' . $base['port'];
        }

        if (str_starts_with($reference, '/')) {
            return $authority . $reference;
        }

        $basePath = $base['path'] ?? '/';
        $directory = str_ends_with($basePath, '/') ? $basePath : dirname($basePath) . '/';

        return $authority . $this->normalizePath($directory . $reference);
    }

    private function normalizePath(string $path): string
    {
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

        return '/' . implode('/', $segments) . (str_ends_with($path, '/') ? '/' : '');
    }

    private function normalizeColor(string $color): string
    {
        $color = trim($color);
        if (preg_match('/^#[0-9a-f]{6}/i', $color, $matches)) {
            return strtoupper($matches[0]);
        }

        return '';
    }
}
