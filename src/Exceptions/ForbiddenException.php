<?php

declare(strict_types=1);

namespace QrCommunication\Withallo\Exceptions;

/**
 * Raised on HTTP 403 — API key lacks the required scope.
 *
 * Withallo returns: `{"code":"API_KEY_INSUFFICIENT_SCOPE","details":[{"message":"required=CONTACTS_READ","field":"scope"}]}`
 */
final class ForbiddenException extends ApiException
{
    /**
     * Extract the scope name(s) required by the endpoint.
     *
     * @return list<string>
     */
    public function requiredScopes(): array
    {
        $scopes = [];

        foreach ($this->getDetails() ?? [] as $detail) {
            $message = $detail['message'] ?? null;

            if (is_string($message) && str_starts_with($message, 'required=')) {
                $scopes[] = substr($message, strlen('required='));
            }
        }

        return $scopes;
    }
}
