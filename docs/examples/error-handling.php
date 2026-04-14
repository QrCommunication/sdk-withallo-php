<?php

declare(strict_types=1);

/**
 * Exhaustive error-handling example.
 *
 * Demonstrates:
 *   - graceful rate-limit handling with Retry-After
 *   - scope-aware UX for 403
 *   - field-level form errors from 400/422
 *   - fail-fast vs. degrade-gracefully patterns
 */

require __DIR__.'/../../vendor/autoload.php';

use QrCommunication\Withallo\Enums\WebhookTopic;
use QrCommunication\Withallo\Exceptions\ApiException;
use QrCommunication\Withallo\Exceptions\AuthenticationException;
use QrCommunication\Withallo\Exceptions\ForbiddenException;
use QrCommunication\Withallo\Exceptions\NotFoundException;
use QrCommunication\Withallo\Exceptions\RateLimitException;
use QrCommunication\Withallo\Exceptions\ValidationException;
use QrCommunication\Withallo\WithalloClient;

$client = new WithalloClient(apiKey: getenv('WITHALLO_API_KEY') ?: '');

/**
 * Create a webhook with exponential-backoff retry on 429.
 *
 * @return array<string, mixed>
 */
function createWithRetry(WithalloClient $client, string $alloNumber, string $url, int $maxAttempts = 3): array
{
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            return $client->webhooks->create(
                alloNumber: $alloNumber,
                url: $url,
                topics: [WebhookTopic::CALL_RECEIVED],
            );
        } catch (RateLimitException $e) {
            $wait = $e->retryAfterSeconds ?? 2 ** $attempt;
            fwrite(STDERR, "rate-limited, retrying in {$wait}s ({$attempt}/{$maxAttempts})\n");
            sleep($wait);
        }
    }

    throw new RuntimeException('rate-limit retries exhausted');
}

try {
    $webhook = createWithRetry($client, '+33188833451', 'https://example.com/hook');
    fwrite(STDOUT, "Created webhook id={$webhook['id']}\n");
} catch (AuthenticationException $e) {
    fwrite(STDERR, "Your API key is invalid or revoked.\n");
    exit(1);
} catch (ForbiddenException $e) {
    $missing = implode(', ', $e->requiredScopes());
    fwrite(STDERR, "Missing scopes: {$missing}\n");
    fwrite(STDERR, "Go to https://web.withallo.com/settings/api to re-scope the key.\n");
    exit(1);
} catch (ValidationException $e) {
    fwrite(STDERR, "Payload rejected:\n");
    foreach ($e->errors() as $field => $message) {
        fwrite(STDERR, "  - {$field}: {$message}\n");
    }
    exit(2);
} catch (NotFoundException $e) {
    fwrite(STDERR, "Resource not found — did Withallo remove the Allo number?\n");
    exit(3);
} catch (ApiException $e) {
    fwrite(STDERR, sprintf(
        "Unhandled API error (HTTP %d): %s\nBody: %s\n",
        $e->httpStatus,
        $e->getErrorCode() ?? $e->getMessage(),
        json_encode($e->responseBody),
    ));
    exit(10);
}
