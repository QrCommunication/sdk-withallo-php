<?php

declare(strict_types=1);

namespace QrCommunication\Withallo\Enums;

enum CallType: string
{
    case INBOUND = 'INBOUND';
    case OUTBOUND = 'OUTBOUND';
}
