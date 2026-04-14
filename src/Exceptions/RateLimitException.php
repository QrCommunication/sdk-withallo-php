<?php

declare(strict_types=1);

namespace QrCommunication\Withallo\Exceptions;

/**
 * Raised on HTTP 429 — too many requests.
 */
final class RateLimitException extends ApiException
{
    /**
     * Number of seconds the caller should wait before retrying, if provided
     * in the `Retry-After` response header.
     */
    public ?int $retryAfterSeconds = null;
}
