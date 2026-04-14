<?php

declare(strict_types=1);

namespace QrCommunication\Withallo\Exceptions;

/**
 * Raised on HTTP 400 / 422 — invalid request payload.
 */
final class ValidationException extends ApiException
{
    /**
     * Field-level validation errors keyed by field name.
     *
     * @return array<string, string>
     */
    public function errors(): array
    {
        $errors = [];

        foreach ($this->getDetails() ?? [] as $detail) {
            $field = $detail['field'] ?? null;
            $message = $detail['message'] ?? null;

            if (is_string($field) && is_string($message)) {
                $errors[$field] = $message;
            }
        }

        return $errors;
    }
}
