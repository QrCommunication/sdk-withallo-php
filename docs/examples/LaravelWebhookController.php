<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use QrCommunication\Withallo\Enums\WebhookTopic;
use QrCommunication\Withallo\Exceptions\InvalidWebhookPayloadException;
use QrCommunication\Withallo\Webhooks\WebhookEvent;
use QrCommunication\Withallo\Webhooks\WebhookReceiver;

/**
 * Laravel controller — receives Withallo webhook events and dispatches them.
 *
 * Route definition (routes/web.php or routes/api.php):
 *
 *   Route::post('/webhooks/allo/{token}', [WithalloWebhookController::class, 'handle'])
 *       ->where('token', '[A-Za-z0-9]{32,}');
 *
 * The `{token}` path segment provides a lightweight origin filter since Withallo
 * does not (yet) publish an HMAC signature for outgoing webhooks.
 */
final class WithalloWebhookController extends Controller
{
    public function handle(Request $request, string $token): JsonResponse
    {
        // Constant-time compare against an env-stored secret.
        if (! hash_equals(config('withallo.webhook_secret', ''), $token)) {
            return response()->json([], 404);
        }

        $receiver = $this->buildReceiver();

        try {
            $receiver->handle($request->getContent());
        } catch (InvalidWebhookPayloadException $e) {
            Log::warning('Withallo webhook rejected (malformed payload)', [
                'reason' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'invalid_payload'], 400);
        } catch (\Throwable $e) {
            Log::error('Withallo webhook handler failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            // Return 200 OK anyway when the failure is *inside* our handlers:
            // Withallo will otherwise retry and keep the queue backed up.
            // For truly invalid payloads (caught above), 400 is correct.
            return response()->json(['ok' => false], 200);
        }

        return response()->json(['ok' => true], 200);
    }

    private function buildReceiver(): WebhookReceiver
    {
        $receiver = new WebhookReceiver;

        $receiver
            ->on(WebhookTopic::CALL_RECEIVED, function (WebhookEvent $event): void {
                /** @var string $callId */
                $callId = $event->get('id');

                // Persist the call, update CRM, enqueue an AI summary job, ...
                dispatch(new \App\Jobs\ProcessAlloCall($callId, $event->data));
            })
            ->on(WebhookTopic::SMS_RECEIVED, function (WebhookEvent $event): void {
                if ($event->get('direction') === 'INBOUND') {
                    dispatch(new \App\Jobs\ProcessAlloInboundSms($event->data));
                }
            })
            ->on(WebhookTopic::CONTACT_CREATED, function (WebhookEvent $event): void {
                dispatch(new \App\Jobs\SyncAlloContact($event->data, 'created'));
            })
            ->on(WebhookTopic::CONTACT_UPDATED, function (WebhookEvent $event): void {
                dispatch(new \App\Jobs\SyncAlloContact($event->data, 'updated'));
            });

        return $receiver;
    }
}
