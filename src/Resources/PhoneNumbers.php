<?php

declare(strict_types=1);

namespace QrCommunication\Withallo\Resources;

/**
 * PhoneNumbers resource — list the phone numbers connected to your account.
 *
 * Required scope: `CONVERSATIONS_READ`.
 */
final class PhoneNumbers extends Resource
{
    /**
     * List all phone numbers on the account.
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $response = $this->http->get('/numbers');
        $data = $response['data'] ?? [];

        return is_array($data) ? array_values($data) : [];
    }
}
