<?php

declare(strict_types=1);

namespace QrCommunication\Withallo\Resources;

/**
 * Calls resource — search your Allo call history.
 *
 * Required scope: `CONVERSATIONS_READ`.
 *
 *   $client->calls->search(alloNumber: '+1234567890', page: 0, size: 50);
 */
final class Calls extends Resource
{
    /**
     * Search call history for a given Allo number.
     *
     * @param  string  $alloNumber  Your Allo phone number (E.164) — REQUIRED filter
     * @param  string|null  $contactNumber  Optional contact number to restrict to
     * @param  int  $page  0-indexed page number (default 0)
     * @param  int  $size  Results per page (1–100, default 10)
     * @return array<string, mixed> `data` containing `results` + `metadata`
     */
    public function search(
        string $alloNumber,
        ?string $contactNumber = null,
        int $page = 0,
        int $size = 10,
    ): array {
        $query = [
            'allo_number' => $alloNumber,
            'page' => $page,
            'size' => $size,
        ];

        if ($contactNumber !== null) {
            $query['contact_number'] = $contactNumber;
        }

        $response = $this->http->get('/calls', $query);

        /** @var array<string, mixed> $data */
        $data = $response['data'] ?? [];

        return $data;
    }
}
