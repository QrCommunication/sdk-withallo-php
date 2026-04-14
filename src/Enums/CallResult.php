<?php

declare(strict_types=1);

namespace QrCommunication\Withallo\Enums;

enum CallResult: string
{
    case ANSWERED = 'ANSWERED';
    case VOICEMAIL = 'VOICEMAIL';
    case TRANSFERRED_AI = 'TRANSFERRED_AI';
    case TRANSFERRED_EXTERNAL = 'TRANSFERRED_EXTERNAL';
    case BLOCKED = 'BLOCKED';
    case FAILED = 'FAILED';

    public function isSuccessful(): bool
    {
        return match ($this) {
            self::ANSWERED, self::TRANSFERRED_AI, self::TRANSFERRED_EXTERNAL => true,
            default => false,
        };
    }
}
