<?php

declare(strict_types=1);

namespace QrCommunication\Withallo;

use QrCommunication\Withallo\Enums\Environment;
use QrCommunication\Withallo\Resources\Calls;
use QrCommunication\Withallo\Resources\Contacts;
use QrCommunication\Withallo\Resources\PhoneNumbers;
use QrCommunication\Withallo\Resources\Sms;
use QrCommunication\Withallo\Resources\Webhooks;
use QrCommunication\Withallo\Webhooks\WebhookReceiver;

/**
 * Withallo SDK — entry point.
 *
 *   $client = new WithalloClient(apiKey: 'your-api-key');
 *
 *   // Webhook management
 *   $webhooks = $client->webhooks->list();
 *   $client->webhooks->create(
 *       alloNumber: '+1234567890',
 *       url: 'https://example.com/webhooks/allo',
 *       topics: [WebhookTopic::CALL_RECEIVED, WebhookTopic::SMS_RECEIVED],
 *   );
 *
 *   // Send SMS
 *   $client->sms->send(from: '+1234567890', to: '+0987654321', message: 'Hello');
 *
 *   // Contacts
 *   $client->contacts->create(numbers: ['+15551234567'], name: 'John');
 *
 *   // Incoming webhook payload processing (use WebhookReceiver directly)
 *   $receiver = $client->webhookReceiver();
 *   $receiver->on(WebhookTopic::CALL_RECEIVED, fn ($e) => handleCall($e));
 *   $receiver->handle(file_get_contents('php://input'));
 */
final class WithalloClient
{
    public readonly Webhooks $webhooks;

    public readonly Calls $calls;

    public readonly Contacts $contacts;

    public readonly Sms $sms;

    public readonly PhoneNumbers $phoneNumbers;

    private readonly Config $config;

    private readonly HttpClient $http;

    public function __construct(
        string $apiKey,
        string|Environment $environment = Environment::PRODUCTION,
        int $timeout = 30,
        int $connectTimeout = 10,
        ?string $userAgent = null,
        ?HttpClient $httpClient = null,
    ) {
        $this->config = new Config(
            apiKey: $apiKey,
            environment: $environment,
            timeout: $timeout,
            connectTimeout: $connectTimeout,
            userAgent: $userAgent,
        );

        $this->http = $httpClient ?? new HttpClient($this->config);

        $this->webhooks = new Webhooks($this->http);
        $this->calls = new Calls($this->http);
        $this->contacts = new Contacts($this->http);
        $this->sms = new Sms($this->http);
        $this->phoneNumbers = new PhoneNumbers($this->http);
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * Return a fresh {@see WebhookReceiver} for parsing/dispatching incoming
     * webhook payloads. Receivers are stateless relative to the HTTP client;
     * a new one per request is fine.
     */
    public function webhookReceiver(): WebhookReceiver
    {
        return new WebhookReceiver;
    }

    /**
     * Quick connectivity / credentials check.
     *
     * Hits `GET /numbers` (scope `CONVERSATIONS_READ`). If your API key does
     * not hold that scope, use another scope-appropriate endpoint instead.
     */
    public function testConnection(): bool
    {
        try {
            $this->phoneNumbers->list();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
