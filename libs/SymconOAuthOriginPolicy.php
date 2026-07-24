<?php

declare(strict_types=1);

namespace IPSKalender;

require_once __DIR__ . '/CalendarHttpOriginPolicyInterface.php';
require_once __DIR__ . '/CalDAVOriginPolicy.php';

/**
 * Restricts OAuth token requests to the Symcon OAuth service origin.
 */
final class SymconOAuthOriginPolicy implements CalendarHttpOriginPolicyInterface
{
    private readonly CalDAVOriginPolicy $originPolicy;

    /**
     * Creates the fixed Symcon OAuth origin policy.
     */
    public function __construct()
    {
        $this->originPolicy = new CalDAVOriginPolicy('https://oauth.ipmagic.de');
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
        return 'The Symcon OAuth request URL belongs to an untrusted origin.';
    }

    /** @inheritDoc */
    public function redirectInvalidMessage(): string
    {
        return 'The Symcon OAuth redirect URL is invalid.';
    }

    /** @inheritDoc */
    public function redirectBlockedMessage(): string
    {
        return 'A Symcon OAuth redirect to an untrusted origin was blocked.';
    }
}
