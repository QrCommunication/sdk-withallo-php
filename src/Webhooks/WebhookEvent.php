<?php

declare(strict_types=1);

namespace QrCommunication\Withallo\Webhooks;

use QrCommunication\Withallo\Enums\WebhookTopic;

/**
 * Immutable value object representing a parsed webhook payload.
 *
 * Envelope format (Withallo):
 *   { "topic": "CALL_RECEIVED", "data": { ... } }
 *
 * This class does NOT attempt to validate the shape of `data` beyond the envelope.
 * The full field list for each topic is documented in the guide:
 *   https://help.withallo.com/en/api-reference/guides/webhooks
 */
final class WebhookEvent
{
    /**
     * @param  array<string, mixed>  $data  topic-specific payload
     * @param  array<string, mixed>  $raw  entire decoded envelope
     */
    public function __construct(
        public readonly WebhookTopic $topic,
        public readonly array $data,
        public readonly array $raw,
    ) {
    }

    public function isCall(): bool
    {
        return $this->topic === WebhookTopic::CALL_RECEIVED;
    }

    public function isSms(): bool
    {
        return $this->topic === WebhookTopic::SMS_RECEIVED;
    }

    public function isContactCreated(): bool
    {
        return $this->topic === WebhookTopic::CONTACT_CREATED;
    }

    public function isContactUpdated(): bool
    {
        return $this->topic === WebhookTopic::CONTACT_UPDATED;
    }

    /**
     * Shortcut: read a field from `data` with dot notation.
     * Returns `$default` if the path does not resolve.
     */
    public function get(string $path, mixed $default = null): mixed
    {
        $segments = explode('.', $path);
        $current = $this->data;

        foreach ($segments as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return $default;
            }

            $current = $current[$segment];
        }

        return $current;
    }
}
