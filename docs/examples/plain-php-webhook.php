<?php

declare(strict_types=1);

/**
 * Plain-PHP webhook receiver — no framework required.
 *
 * Drop this file at a secret URL under your web-root, e.g.:
 *   https://example.com/hook-a1b2c3d4e5f6/withallo.php
 *
 * In your Apache / Nginx setup, only this path should be publicly reachable.
 * The secret URL acts as a lightweight origin filter until Withallo ships an
 * HMAC signature scheme.
 */

require __DIR__.'/../../vendor/autoload.php';

use QrCommunication\Withallo\Enums\WebhookTopic;
use QrCommunication\Withallo\Exceptions\InvalidWebhookPayloadException;
use QrCommunication\Withallo\Webhooks\WebhookEvent;
use QrCommunication\Withallo\Webhooks\WebhookReceiver;

// Only accept POST.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$logFile = __DIR__.'/withallo.log';
$log = function (string $message, array $context = []) use ($logFile): void {
    file_put_contents(
        $logFile,
        sprintf("[%s] %s %s\n", date('c'), $message, json_encode($context)),
        FILE_APPEND,
    );
};

$receiver = new WebhookReceiver;

$receiver
    ->on(WebhookTopic::CALL_RECEIVED, function (WebhookEvent $event) use ($log): void {
        $log('call_received', [
            'id' => $event->get('id'),
            'length' => $event->get('length'),
            'result' => $event->get('result'),
        ]);
    })
    ->on(WebhookTopic::SMS_RECEIVED, function (WebhookEvent $event) use ($log): void {
        if ($event->get('direction') === 'INBOUND') {
            $log('sms_inbound', [
                'from' => $event->get('from_number'),
                'content' => $event->get('content'),
            ]);
        }
    })
    ->on(WebhookTopic::CONTACT_CREATED, function (WebhookEvent $event) use ($log): void {
        $log('contact_created', ['id' => $event->get('id')]);
    })
    ->on(WebhookTopic::CONTACT_UPDATED, function (WebhookEvent $event) use ($log): void {
        $log('contact_updated', ['id' => $event->get('id')]);
    });

$body = file_get_contents('php://input') ?: '';

try {
    $receiver->handle($body);
    http_response_code(200);
    echo '{"ok":true}';
} catch (InvalidWebhookPayloadException $e) {
    $log('invalid_payload', ['reason' => $e->getMessage()]);
    http_response_code(400);
    echo '{"error":"invalid_payload"}';
} catch (\Throwable $e) {
    $log('handler_failed', ['type' => $e::class, 'message' => $e->getMessage()]);
    // Still reply 200 to avoid Withallo auto-disabling the webhook for a
    // transient error inside our own handler code.
    http_response_code(200);
    echo '{"ok":false}';
}
