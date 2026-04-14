<?php

declare(strict_types=1);

/**
 * Live smoke test against the real Withallo API.
 *
 * Usage:
 *   WITHALLO_API_KEY=xxx php docs/examples/live-smoke-test.php
 *
 *   # Or with an SMS target (may fail if your sender ID is not pre-verified):
 *   WITHALLO_API_KEY=xxx WITHALLO_SMS_TO=+33612345678 \
 *     php docs/examples/live-smoke-test.php
 *
 * What it does:
 *   1. testConnection()
 *   2. list phone numbers
 *   3. list webhooks
 *   4. create + delete a disabled test webhook
 *   5. search contacts (page 0)
 *   6. (optional) send one SMS to WITHALLO_SMS_TO
 */

require __DIR__.'/../../vendor/autoload.php';

use QrCommunication\Withallo\Enums\WebhookTopic;
use QrCommunication\Withallo\Exceptions\ApiException;
use QrCommunication\Withallo\Exceptions\ForbiddenException;
use QrCommunication\Withallo\WithalloClient;

$apiKey = getenv('WITHALLO_API_KEY');
if (! is_string($apiKey) || $apiKey === '') {
    fwrite(STDERR, "WITHALLO_API_KEY is not set\n");
    exit(1);
}

$client = new WithalloClient(apiKey: $apiKey);

echo "=== Withallo SDK live smoke test ===\n\n";

echo '[1] testConnection()... ';
echo $client->testConnection() ? "OK\n" : "FAILED\n";

$reportError = function (\Throwable $err): void {
    if ($err instanceof ForbiddenException) {
        echo '    FORBIDDEN: missing='.implode(',', $err->requiredScopes())."\n";
    } elseif ($err instanceof ApiException) {
        echo '    ERROR '.$err->httpStatus.': '.$err->getMessage();
        echo ' body='.json_encode($err->responseBody)."\n";
    } else {
        echo '    '.$err->getMessage()."\n";
    }
};

echo "\n[2] phoneNumbers->list()\n";
$firstNumber = null;
try {
    $numbers = $client->phoneNumbers->list();
    echo '    count: '.count($numbers)."\n";
    foreach ($numbers as $n) {
        echo '    - '.($n['number'] ?? '?').' ('.($n['name'] ?? '?').', '.($n['country'] ?? '?').")\n";
    }
    $firstNumber = $numbers[0]['number'] ?? null;
} catch (\Throwable $err) {
    $reportError($err);
}

echo "\n[3] webhooks->list()\n";
try {
    $webhooks = $client->webhooks->list();
    echo '    count: '.count($webhooks)."\n";
} catch (\Throwable $err) {
    $reportError($err);
}

echo "\n[4] webhooks->create() + delete()\n";
if ($firstNumber === null) {
    echo "    skipped (no phone numbers on the account)\n";
} else {
    try {
        $webhook = $client->webhooks->create(
            alloNumber: $firstNumber,
            url: 'https://example.invalid/hook/'.bin2hex(random_bytes(4)),
            topics: [WebhookTopic::CALL_RECEIVED],
            enabled: false,
        );
        $webhookId = $webhook['id'] ?? null;
        echo '    created id='.($webhookId ?? 'n/a')."\n";
        if ($webhookId !== null) {
            $client->webhooks->delete($webhookId);
            echo "    deleted OK\n";
        }
    } catch (\Throwable $err) {
        $reportError($err);
    }
}

echo "\n[5] contacts->search(page=0, size=5)\n";
try {
    $result = $client->contacts->search(page: 0, size: 5);
    $count = count($result['results'] ?? []);
    $total = $result['metadata']['pagination']['total_pages'] ?? '?';
    echo "    results={$count} total_pages={$total}\n";
} catch (\Throwable $err) {
    $reportError($err);
}

$smsTarget = getenv('WITHALLO_SMS_TO');
if (is_string($smsTarget) && $smsTarget !== '') {
    echo "\n[6] sms->sendFrance() -> {$smsTarget}\n";
    try {
        $result = $client->sms->sendFrance(
            senderId: 'QrCom',
            to: $smsTarget,
            message: 'Withallo SDK smoke test '.date('H:i:s'),
        );
        echo '    sent: '.json_encode($result)."\n";
    } catch (\Throwable $err) {
        $reportError($err);
    }
}

echo "\n=== DONE ===\n";
