<?php

declare(strict_types=1);

namespace QrCommunication\Withallo\Enums;

enum SmsType: string
{
    case SMS = 'SMS';
    case MMS = 'MMS';
}
