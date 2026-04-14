<?php

declare(strict_types=1);

namespace QrCommunication\Withallo\Resources;

/**
 * Contacts resource — read/search contacts and create/update them.
 *
 * Required scopes:
 * - `CONTACTS_READ` for get() and search()
 * - `CONTACTS_READ_WRITE` for create() and update()
 * - `CONVERSATIONS_READ` for searchConversation()
 *
 * Note: the GET endpoint uses the singular path `/contact/{id}` while
 * POST/PUT/LIST use the plural `/contacts`. That is Withallo's convention,
 * not a typo.
 */
final class Contacts extends Resource
{
    /**
     * Get a single contact by its PID (e.g. `cnt_abc123`).
     *
     * @return array<string, mixed>
     */
    public function get(string $contactId): array
    {
        $response = $this->http->get('/contact/'.rawurlencode($contactId));

        /** @var array<string, mixed> $data */
        $data = $response['data'] ?? [];

        return $data;
    }

    /**
     * Paginated list/search of contacts.
     *
     * @return array<string, mixed> `data` containing `results` + `metadata.pagination`
     */
    public function search(int $page = 0, int $size = 10): array
    {
        $response = $this->http->get('/contacts', [
            'page' => $page,
            'size' => $size,
        ]);

        /** @var array<string, mixed> $data */
        $data = $response['data'] ?? [];

        return $data;
    }

    /**
     * Paginated conversation (calls + SMS) between your account and a contact.
     *
     * @return array<string, mixed> `data` containing `results` + `metadata.pagination`
     */
    public function searchConversation(string $contactId, int $page = 0, int $size = 10): array
    {
        $response = $this->http->get(
            '/contact/'.rawurlencode($contactId).'/conversation',
            ['page' => $page, 'size' => $size],
        );

        /** @var array<string, mixed> $data */
        $data = $response['data'] ?? [];

        return $data;
    }

    /**
     * Create a new contact. At least one phone number is required.
     *
     * @param  list<string>  $numbers  Phone numbers in E.164 format
     * @param  list<string>|null  $emails  Email addresses
     * @return array<string, mixed> the created contact
     */
    public function create(
        array $numbers,
        ?string $name = null,
        ?string $lastName = null,
        ?string $jobTitle = null,
        ?string $website = null,
        ?array $emails = null,
        ?string $company = null,
    ): array {
        $body = array_filter([
            'name' => $name,
            'last_name' => $lastName,
            'job_title' => $jobTitle,
            'website' => $website,
            'emails' => $emails,
            'numbers' => $numbers,
            'company' => $company,
        ], static fn (mixed $value): bool => $value !== null);

        $response = $this->http->post('/contacts', $body);

        /** @var array<string, mixed> $data */
        $data = $response['data'] ?? [];

        return $data;
    }

    /**
     * Update an existing contact. Only provided fields are updated;
     * `emails` and `numbers` REPLACE the existing arrays wholesale.
     *
     * @param  array<string, mixed>  $fields  Any subset of: name, last_name, job_title, website, emails, numbers
     * @return array<string, mixed> the updated contact
     */
    public function update(string $contactId, array $fields): array
    {
        $response = $this->http->put('/contacts/'.rawurlencode($contactId), $fields);

        /** @var array<string, mixed> $data */
        $data = $response['data'] ?? [];

        return $data;
    }
}
