<?php

declare(strict_types=1);

namespace QrCommunication\Withallo\Resources;

use QrCommunication\Withallo\HttpClient;

/**
 * Base class for every API resource (Webhooks, Calls, Contacts, SMS, PhoneNumbers).
 */
abstract class Resource
{
    public function __construct(
        protected readonly HttpClient $http,
    ) {}
}
