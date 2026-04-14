<?php

declare(strict_types=1);

namespace QrCommunication\Withallo\Resources;

use QrCommunication\Withallo\Enums\WebhookTopic;

/**
 * Webhooks resource — manage webhook configurations.
 *
 * Required scope: `WEBHOOKS_READ_WRITE`.
 *
 *   $client->webhooks->list();
 *   $client->webhooks->create(alloNumber: '+1234567890', url: 'https://...', topics: [...]);
 *   $client->webhooks->delete('web-abc123');
 *
 * To process incoming webhook payloads, use {@see \QrCommunication\Withallo\Webhooks\WebhookReceiver}.
 */
final class Webhooks extends Resource
{
    /**
     * List all webhook configurations on the account.
     *
     * @return list<array<string, mixed>> the `data` array from the response
     */
    public function list(): array
    {
        $response = $this->http->get('/webhooks');
        $data = $response['data'] ?? [];

        return is_array($data) ? array_values($data) : [];
    }

    /**
     * Create a new webhook configuration.
     *
     * @param  string  $alloNumber  E.164 Allo phone number (e.g. `+1234567890`)
     * @param  string  $url  Public HTTPS endpoint that will receive events
     * @param  list<WebhookTopic|string>  $topics  Topics to subscribe to (defaults to all)
     * @param  bool  $enabled  Whether the webhook is enabled (default true)
     * @return array<string, mixed> the `data` object from the response
     */
    public function create(
        string $alloNumber,
        string $url,
        array $topics,
        bool $enabled = true,
    ): array {
        $response = $this->http->post('/webhooks', [
            'allo_number' => $alloNumber,
            'url' => $url,
            'enabled' => $enabled,
            'topics' => $this->normalizeTopics($topics),
        ]);

        /** @var array<string, mixed> $data */
        $data = $response['data'] ?? [];

        return $data;
    }

    /**
     * Delete a webhook configuration by its ID (e.g. `web-abc123`).
     *
     * @return array<string, mixed> the raw response body (typically empty on success)
     */
    public function delete(string $webhookId): array
    {
        return $this->http->delete('/webhooks/'.rawurlencode($webhookId));
    }

    /**
     * @param  list<WebhookTopic|string>  $topics
     * @return list<string>
     */
    private function normalizeTopics(array $topics): array
    {
        return array_values(array_map(
            static fn (WebhookTopic|string $topic): string => $topic instanceof WebhookTopic ? $topic->value : $topic,
            $topics,
        ));
    }
}
