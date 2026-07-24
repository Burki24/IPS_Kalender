<?php

declare(strict_types=1);

namespace IPSKalender;

interface CalendarHttpOriginPolicyInterface
{
    /**
     * Checks whether an absolute request URL belongs to a trusted origin.
     */
    public function isAllowedUrl(string $url): bool;

    /**
     * Resolves an absolute or relative redirect reference against an absolute base URL.
     */
    public function resolveUrl(string $baseUrl, string $reference): string;

    /**
     * Returns the error message used when the initial request URL is not trusted.
     */
    public function requestBlockedMessage(): string;

    /**
     * Returns the error message used when a redirect target cannot be resolved.
     */
    public function redirectInvalidMessage(): string;

    /**
     * Returns the error message used when a redirect leaves the trusted origin set.
     */
    public function redirectBlockedMessage(): string;
}
