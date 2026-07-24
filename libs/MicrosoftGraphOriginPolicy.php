<?php

declare(strict_types=1);

namespace IPSKalender;

require_once __DIR__ . '/CalendarHttpOriginPolicyInterface.php';
require_once __DIR__ . '/CalDAVOriginPolicy.php';

/**
 * Restricts authenticated Microsoft Graph requests and redirects to graph.microsoft.com.
 */
final class MicrosoftGraphOriginPolicy implements CalendarHttpOriginPolicyInterface
{
    private readonly CalDAVOriginPolicy $originPolicy;

    /**
     * Creates the fixed Microsoft Graph origin policy.
     */
    public function __construct()
    {
        $this->originPolicy = new CalDAVOriginPolicy('https://graph.microsoft.com');
    }

    /** @inheritDoc */
    public function isAllowedUrl(string $url): bool
    {
        return $this->originPolicy->isAllowedUrl($url);
    }

    /** @inheritDoc */
    public function resolveUrl(string $baseUrl, string $reference): string
    {
        return $this->originPolicy->resolveUrl($baseUrl, $reference);
    }

    /** @inheritDoc */
    public function requestBlockedMessage(): string
    {
        return 'The Microsoft Graph request URL belongs to an untrusted origin.';
    }

    /** @inheritDoc */
    public function redirectInvalidMessage(): string
    {
        return 'The Microsoft Graph redirect URL is invalid.';
    }

    /** @inheritDoc */
    public function redirectBlockedMessage(): string
    {
        return 'A Microsoft Graph redirect to an untrusted origin was blocked.';
    }
}
