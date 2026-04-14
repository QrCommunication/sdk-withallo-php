<?php

declare(strict_types=1);

namespace QrCommunication\Withallo\Enums;

/**
 * Webhook topics emitted by Withallo.
 *
 * Sent in the `topic` field of every webhook envelope.
 * See: https://help.withallo.com/en/api-reference/guides/webhooks
 */
enum WebhookTopic: string
{
    case CALL_RECEIVED = 'CALL_RECEIVED';
    case SMS_RECEIVED = 'SMS_RECEIVED';
    case CONTACT_CREATED = 'CONTACT_CREATED';
    case CONTACT_UPDATED = 'CONTACT_UPDATED';

    public function description(): string
    {
        return match ($this) {
            self::CALL_RECEIVED => 'Triggered after a call ends',
            self::SMS_RECEIVED => 'Triggered after an SMS is sent or received',
            self::CONTACT_CREATED => 'Triggered after a contact is created',
            self::CONTACT_UPDATED => 'Triggered after a contact is updated',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
