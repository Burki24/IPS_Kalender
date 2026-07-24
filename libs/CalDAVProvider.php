<?php

declare(strict_types=1);

namespace IPSKalender;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

require_once __DIR__ . '/CalendarProviderInterface.php';
require_once __DIR__ . '/CalendarHttpClient.php';
require_once __DIR__ . '/CalDAVOriginPolicy.php';
require_once __DIR__ . '/ICalendarCodec.php';

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

    private readonly CalDAVOriginPolicy $originPolicy;

    public function __construct(
        private readonly CalendarHttpClientInterface $httpClient,
        string $serverUrl,
        ?CalDAVOriginPolicy $originPolicy = null
    ) {
        $this->originPolicy = $originPolicy ?? new CalDAVOriginPolicy($serverUrl);
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
        $entryUrls = $this->getEntryUrls();
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

    public function getEvents(string $calendarUrl, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        if ($end <= $start) {
            throw new CalDAVProviderException('The event query end must be later than the start.');
        }

        $calendarUrl = $this->normalizeAbsoluteUrl($calendarUrl);
        $utc = new DateTimeZone('UTC');
        $startValue = $start->setTimezone($utc)->format('Ymd\THis\Z');
        $endValue = $end->setTimezone($utc)->format('Ymd\THis\Z');
        $body = '<?xml version="1.0" encoding="utf-8" ?>' .
            '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">' .
            '<d:prop><d:getetag/><c:calendar-data><c:expand start="' . $startValue . '" end="' . $endValue . '"/>' .
            '</c:calendar-data></d:prop>' .
            '<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT">' .
            '<c:time-range start="' . $startValue . '" end="' . $endValue . '"/>' .
            '</c:comp-filter></c:comp-filter></c:filter></c:calendar-query>';

        $response = $this->httpClient->request(
            'REPORT',
            $calendarUrl,
            [
                'Accept'       => 'application/xml, text/xml',
                'Content-Type' => 'application/xml; charset=utf-8',
                'Depth'        => '1'
            ],
            $body
        );
        $this->assertResponseStatus($response, [207], 'calendar query');
        $effectiveCalendarUrl = $this->trustedEffectiveUrl($response, $calendarUrl);

        $document = $this->parseXml($response->body);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('d', self::DAV_NAMESPACE);
        $xpath->registerNamespace('c', self::CALDAV_NAMESPACE);
        $events = [];
        $responses = $xpath->query('//d:multistatus/d:response');
        if ($responses === false) {
            return [];
        }

        foreach ($responses as $eventResponse) {
            if (!$eventResponse instanceof DOMElement) {
                continue;
            }
            $href = $this->firstNodeValue($xpath, './d:href', $eventResponse);
            $calendarData = $this->firstNodeValue($xpath, './/c:calendar-data', $eventResponse);
            if ($href === '' || $calendarData === '') {
                continue;
            }
            $resourceUrl = $this->resolveUrl($effectiveCalendarUrl, $href);
            $this->assertResourceBelongsToCalendar($calendarUrl, $resourceUrl);
            $etag = $this->firstNodeValue($xpath, './/d:getetag', $eventResponse);
            array_push($events, ...ICalendarCodec::parseEvents($calendarData, $resourceUrl, $etag));
        }

        usort(
            $events,
            static fn(array $left, array $right): int => ($left['startTimestamp'] <=> $right['startTimestamp'])
                ?: strcasecmp((string) $left['summary'], (string) $right['summary'])
        );

        return $events;
    }

    public function createEvent(string $calendarUrl, array $event): array
    {
        $calendarUrl = $this->normalizeAbsoluteUrl($calendarUrl);
        $created = ICalendarCodec::createEvent($event);
        $resourceUrl = rtrim($calendarUrl, '/') . '/' . rawurlencode($created['uid']) . '.ics';
        $response = $this->httpClient->request(
            'PUT',
            $resourceUrl,
            [
                'Content-Type'  => 'text/calendar; charset=utf-8',
                'If-None-Match' => '*'
            ],
            $created['ical']
        );
        $this->assertResponseStatus($response, [200, 201, 204], 'event creation');
        $effectiveResourceUrl = $this->trustedEffectiveUrl($response, $resourceUrl);
        $this->assertResourceBelongsToCalendar($calendarUrl, $effectiveResourceUrl);

        return [
            'uid'         => $created['uid'],
            'resourceUrl' => $effectiveResourceUrl,
            'etag'        => (string) ($response->headers['etag'] ?? '')
        ];
    }

    public function updateEvent(
        string $calendarUrl,
        string $resourceUrl,
        string $etag,
        string $uid,
        array $event
    ): array {
        $calendarUrl = $this->normalizeAbsoluteUrl($calendarUrl);
        $resourceUrl = $this->normalizeAbsoluteUrl($resourceUrl);
        $this->assertResourceBelongsToCalendar($calendarUrl, $resourceUrl);
        if ($uid === '') {
            throw new CalDAVProviderException('The event UID is missing.');
        }

        $getResponse = $this->httpClient->request('GET', $resourceUrl, ['Accept' => 'text/calendar']);
        $this->assertResponseStatus($getResponse, [200], 'event retrieval');
        $effectiveResourceUrl = $this->trustedEffectiveUrl($getResponse, $resourceUrl);
        $this->assertResourceBelongsToCalendar($calendarUrl, $effectiveResourceUrl);
        $updatedIcal = ICalendarCodec::updateEvent($getResponse->body, $uid, $event);
        $currentEtag = $etag !== '' ? $etag : (string) ($getResponse->headers['etag'] ?? '');
        $headers = ['Content-Type' => 'text/calendar; charset=utf-8'];
        if ($currentEtag !== '') {
            $headers['If-Match'] = $currentEtag;
        }

        $putResponse = $this->httpClient->request('PUT', $effectiveResourceUrl, $headers, $updatedIcal);
        $this->assertResponseStatus($putResponse, [200, 201, 204], 'event update');
        $updatedResourceUrl = $this->trustedEffectiveUrl($putResponse, $effectiveResourceUrl);
        $this->assertResourceBelongsToCalendar($calendarUrl, $updatedResourceUrl);

        return [
            'uid'         => $uid,
            'resourceUrl' => $updatedResourceUrl,
            'etag'        => (string) ($putResponse->headers['etag'] ?? '')
        ];
    }

    public function deleteEvent(
        string $calendarUrl,
        string $resourceUrl,
        string $etag,
        string $recurrenceId = ''
    ): bool {
        if ($recurrenceId !== '') {
            throw new CalDAVProviderException('Individual occurrences of recurring events cannot be deleted yet.');
        }

        $calendarUrl = $this->normalizeAbsoluteUrl($calendarUrl);
        $resourceUrl = $this->normalizeAbsoluteUrl($resourceUrl);
        $this->assertResourceBelongsToCalendar($calendarUrl, $resourceUrl);
        $headers = [];
        if ($etag !== '') {
            $headers['If-Match'] = $etag;
        }
        $response = $this->httpClient->request('DELETE', $resourceUrl, $headers);
        $this->assertResponseStatus($response, [200, 204], 'event deletion');
        $effectiveResourceUrl = $this->trustedEffectiveUrl($response, $resourceUrl);
        $this->assertResourceBelongsToCalendar($calendarUrl, $effectiveResourceUrl);

        return true;
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

        return $this->resolveUrl($this->trustedEffectiveUrl($response, $url), $href);
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

        return $this->resolveUrl($this->trustedEffectiveUrl($response, $principalUrl), $href);
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
            $url = $this->resolveUrl($this->trustedEffectiveUrl($response, $homeSetUrl), $href);

            $calendars[] = [
                'id'           => hash('sha256', $url),
                'providerId'   => $url,
                'reference'    => $url,
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

        $effectiveUrl = $this->trustedEffectiveUrl($response, $url);

        return new CalendarHttpResponse(
            $response->statusCode,
            $response->headers,
            $response->body,
            $effectiveUrl
        );
    }

    /**
     * @param list<int> $expectedStatusCodes
     */
    private function assertResponseStatus(
        CalendarHttpResponse $response,
        array $expectedStatusCodes,
        string $operation
    ): void {
        if (in_array($response->statusCode, [401, 403], true)) {
            throw new CalDAVProviderException('Authentication failed or calendar access was denied.', $response->statusCode);
        }
        if ($response->statusCode === 412) {
            throw new CalDAVProviderException(
                'The event was changed by another client. Synchronize the calendar and try again.',
                412
            );
        }
        if (!in_array($response->statusCode, $expectedStatusCodes, true)) {
            throw new CalDAVProviderException(
                sprintf('Unexpected CalDAV response during %s: HTTP %d.', $operation, $response->statusCode),
                $response->statusCode
            );
        }
    }

    private function normalizeAbsoluteUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new CalDAVProviderException('The CalDAV resource URL is invalid.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new CalDAVProviderException('Credentials and fragments are not allowed in CalDAV resource URLs.');
        }
        if (!$this->originPolicy->isAllowedUrl($url)) {
            throw new CalDAVProviderException('The CalDAV resource URL belongs to an untrusted origin.');
        }

        return $url;
    }

    private function assertResourceBelongsToCalendar(string $calendarUrl, string $resourceUrl): void
    {
        $calendar = parse_url($calendarUrl);
        $resource = parse_url($resourceUrl);
        if ($calendar === false || $resource === false) {
            throw new CalDAVProviderException('The CalDAV resource URL is invalid.');
        }

        $calendarPort = $calendar['port'] ?? (strtolower((string) ($calendar['scheme'] ?? '')) === 'https' ? 443 : 80);
        $resourcePort = $resource['port'] ?? (strtolower((string) ($resource['scheme'] ?? '')) === 'https' ? 443 : 80);
        $calendarPath = rtrim($this->normalizePath((string) ($calendar['path'] ?? '/')), '/') . '/';
        $resourcePath = $this->normalizePath((string) ($resource['path'] ?? '/'));

        if (strcasecmp((string) ($calendar['scheme'] ?? ''), (string) ($resource['scheme'] ?? '')) !== 0
            || strcasecmp((string) ($calendar['host'] ?? ''), (string) ($resource['host'] ?? '')) !== 0
            || $calendarPort !== $resourcePort
            || !str_starts_with($resourcePath, $calendarPath)) {
            throw new CalDAVProviderException('The event resource does not belong to the configured calendar.');
        }
    }

    private function trustedEffectiveUrl(CalendarHttpResponse $response, string $requestedUrl): string
    {
        $effectiveUrl = trim($response->effectiveUrl) !== '' ? $response->effectiveUrl : $requestedUrl;

        return $this->normalizeAbsoluteUrl($effectiveUrl);
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
    private function getEntryUrls(): array
    {
        $url = $this->originPolicy->getServerUrl();
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new CalDAVProviderException('The CalDAV server URL is invalid.');
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
        try {
            $url = $this->originPolicy->resolveUrl($baseUrl, $reference);
        } catch (\InvalidArgumentException) {
            throw new CalDAVProviderException('Could not resolve a CalDAV URL.');
        }

        return $this->normalizeAbsoluteUrl($url);
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
