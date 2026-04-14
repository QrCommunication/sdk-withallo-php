<?php

declare(strict_types=1);

namespace QrCommunication\Withallo\Enums;

enum SmsDirection: string
{
    case INBOUND = 'INBOUND';
    case OUTBOUND = 'OUTBOUND';
}
