<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use QrCommunication\Withallo\Exceptions\ApiException;
use QrCommunication\Withallo\Exceptions\ForbiddenException;
use QrCommunication\Withallo\Exceptions\RateLimitException;
use QrCommunication\Withallo\Exceptions\ValidationException;
use QrCommunication\Withallo\WithalloClient;

/**
 * Laravel service layer wrapping the Withallo SDK.
 *
 * Register it as a singleton in a ServiceProvider:
 *
 *   $this->app->singleton(WithalloClient::class, fn () => new WithalloClient(
 *       apiKey: config('services.withallo.api_key'),
 *   ));
 *
 * Usage in a controller:
 *
 *   public function sendReminder(SmsService $sms, Appointment $appointment) {
 *       $sms->sendReminder($appointment);
 *       return response()->noContent();
 *   }
 */
final readonly class SmsService
{
    public function __construct(
        private WithalloClient $client,
    ) {
    }

    /**
     * Send an appointment reminder with automatic retry on rate-limit.
     *
     * @throws \RuntimeException if all retries fail
     */
    public function sendFranceWithRetry(string $senderId, string $to, string $message, int $maxAttempts = 3): array
    {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $this->client->sms->sendFrance(
                    senderId: $senderId,
                    to: $to,
                    message: $message,
                );
            } catch (RateLimitException $e) {
                $wait = $e->retryAfterSeconds ?? 2 ** $attempt;
                Log::warning('Withallo rate limit hit', [
                    'attempt' => $attempt,
                    'retry_after_s' => $wait,
                ]);
                sleep($wait);
            } catch (ValidationException $e) {
                Log::error('Withallo rejected payload', ['errors' => $e->errors()]);
                throw $e; // do NOT retry — payload is invalid
            } catch (ForbiddenException $e) {
                Log::critical('Withallo API key lacks SMS_SEND scope', [
                    'required' => $e->requiredScopes(),
                ]);
                throw $e;
            } catch (ApiException $e) {
                Log::error('Withallo API error', [
                    'http' => $e->httpStatus,
                    'code' => $e->getErrorCode(),
                    'body' => $e->responseBody,
                ]);
                throw $e;
            }
        }

        throw new \RuntimeException("rate-limit retries exhausted after {$maxAttempts} attempts");
    }

    /**
     * Send to multiple recipients with basic back-pressure.
     *
     * @param  list<string>  $recipients  E.164 numbers
     * @return array<string, array{ok: bool, error?: string}>
     */
    public function broadcast(string $from, array $recipients, string $message): array
    {
        $results = [];

        foreach ($recipients as $to) {
            try {
                $this->client->sms->send(from: $from, to: $to, message: $message);
                $results[$to] = ['ok' => true];
            } catch (ApiException $e) {
                $results[$to] = [
                    'ok' => false,
                    'error' => $e->getErrorCode() ?? $e->getMessage(),
                ];
            }

            usleep(200_000); // 200 ms between sends — tune per your plan
        }

        return $results;
    }
}
