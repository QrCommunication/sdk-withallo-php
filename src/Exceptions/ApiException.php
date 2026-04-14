<?php

declare(strict_types=1);

namespace QrCommunication\Withallo\Exceptions;

use Throwable;

/**
 * Raised when the Withallo API returns an HTTP error (4xx/5xx) outside
 * of a more specific condition (auth, scope, validation, not-found, rate-limit).
 */
class ApiException extends WithalloException
{
    /**
     * @param  array<string, mixed>|null  $responseBody
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly ?array $responseBody = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    /**
     * Withallo-specific error code (e.g. `API_KEY_INVALID`).
     */
    public function getErrorCode(): ?string
    {
        return $this->responseBody['code'] ?? null;
    }

    /**
     * Raw `details` from the Withallo error payload (usually a list of field errors).
     *
     * @return list<array<string, mixed>>|null
     */
    public function getDetails(): ?array
    {
        $details = $this->responseBody['details'] ?? null;

        return is_array($details) ? array_values($details) : null;
    }
}
