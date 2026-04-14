<?php

declare(strict_types=1);

namespace QrCommunication\Withallo\Webhooks;

use QrCommunication\Withallo\Enums\WebhookTopic;
use QrCommunication\Withallo\Exceptions\InvalidWebhookPayloadException;

/**
 * Parse incoming Withallo webhook payloads and dispatch them to handlers.
 *
 * IMPORTANT — signature verification
 * ----------------------------------
 * As of April 2026, the public Withallo documentation does NOT specify any
 * HMAC or signature header for webhook authenticity verification. This SDK
 * therefore validates the envelope *shape* (topic + data) but cannot
 * cryptographically verify the origin of the request.
 *
 * Recommended hardening until Withallo publishes a signature scheme:
 *   - serve your webhook endpoint on HTTPS only
 *   - put the endpoint behind an unguessable path (secret-in-URL)
 *   - optionally, whitelist Withallo egress IPs at the firewall level
 *   - drop payloads whose `allo_number` / `from_number` does not match your
 *     own numbers (fetched via the `numbers` endpoint at boot)
 *
 * Basic usage:
 *
 *   $receiver = new WebhookReceiver();
 *   $receiver
 *       ->on(WebhookTopic::CALL_RECEIVED, fn (WebhookEvent $e) => handleCall($e))
 *       ->on(WebhookTopic::SMS_RECEIVED, fn (WebhookEvent $e) => handleSms($e));
 *
 *   $event = $receiver->parse(file_get_contents('php://input'));
 *   $receiver->dispatch($event);
 */
final class WebhookReceiver
{
    /**
     * @var array<string, list<callable(WebhookEvent): void>>
     */
    private array $handlers = [];

    /**
     * Parse a raw JSON body into a typed {@see WebhookEvent}.
     *
     * @throws InvalidWebhookPayloadException on malformed JSON or missing fields
     */
    public function parse(string $rawBody): WebhookEvent
    {
        if ($rawBody === '') {
            throw new InvalidWebhookPayloadException('Webhook body is empty.');
        }

        $decoded = json_decode($rawBody, true);

        if (! is_array($decoded)) {
            throw new InvalidWebhookPayloadException('Webhook body is not valid JSON.');
        }

        $topic = $decoded['topic'] ?? null;
        $data = $decoded['data'] ?? null;

        if (! is_string($topic) || $topic === '') {
            throw new InvalidWebhookPayloadException('Webhook payload is missing the `topic` field.');
        }

        if (! is_array($data)) {
            throw new InvalidWebhookPayloadException('Webhook payload is missing the `data` object.');
        }

        $parsedTopic = WebhookTopic::tryFrom($topic);

        if ($parsedTopic === null) {
            throw new InvalidWebhookPayloadException("Unknown webhook topic: {$topic}");
        }

        return new WebhookEvent(topic: $parsedTopic, data: $data, raw: $decoded);
    }

    /**
     * Register a handler for a topic. Multiple handlers per topic are allowed
     * and executed in registration order.
     *
     * @param  callable(WebhookEvent): void  $handler
     */
    public function on(WebhookTopic|string $topic, callable $handler): self
    {
        $key = $topic instanceof WebhookTopic ? $topic->value : $topic;
        $this->handlers[$key] ??= [];
        $this->handlers[$key][] = $handler;

        return $this;
    }

    /**
     * Invoke all handlers registered for the event's topic.
     * Returns the number of handlers that ran (0 if no handler was registered).
     */
    public function dispatch(WebhookEvent $event): int
    {
        $handlers = $this->handlers[$event->topic->value] ?? [];

        foreach ($handlers as $handler) {
            $handler($event);
        }

        return count($handlers);
    }

    /**
     * Convenience: parse + dispatch in one shot.
     *
     * @throws InvalidWebhookPayloadException
     */
    public function handle(string $rawBody): WebhookEvent
    {
        $event = $this->parse($rawBody);
        $this->dispatch($event);

        return $event;
    }
}
