<?php

declare(strict_types=1);

namespace QrCommunication\Withallo\Enums;

/**
 * Withallo API environment.
 *
 * Only production is publicly documented.
 */
enum Environment: string
{
    case PRODUCTION = 'production';

    public function apiUrl(): string
    {
        return match ($this) {
            self::PRODUCTION => 'https://api.withallo.com',
        };
    }

    public function basePath(): string
    {
        return '/v1/api';
    }
}
