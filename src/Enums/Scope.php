<?php

declare(strict_types=1);

namespace QrCommunication\Withallo\Enums;

/**
 * API key scopes defined by Withallo.
 *
 * A 403 response with `API_KEY_INSUFFICIENT_SCOPE` is returned when a scope is missing.
 */
enum Scope: string
{
    case WEBHOOKS_READ_WRITE = 'WEBHOOKS_READ_WRITE';
    case CONVERSATIONS_READ = 'CONVERSATIONS_READ';
    case CONTACTS_READ = 'CONTACTS_READ';
    case CONTACTS_READ_WRITE = 'CONTACTS_READ_WRITE';
    case SMS_SEND = 'SMS_SEND';

    public function description(): string
    {
        return match ($this) {
            self::WEBHOOKS_READ_WRITE => 'Create, read, and update webhook configurations',
            self::CONVERSATIONS_READ => 'Read call records and history',
            self::CONTACTS_READ => 'Read contact information',
            self::CONTACTS_READ_WRITE => 'Create and update contacts in addition to reading them',
            self::SMS_SEND => 'Send SMS and MMS messages',
        };
    }
}
