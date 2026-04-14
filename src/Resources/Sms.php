<?php

declare(strict_types=1);

namespace QrCommunication\Withallo\Resources;

/**
 * SMS resource — send outbound SMS/MMS messages.
 *
 * Required scope: `SMS_SEND`.
 *
 * Two flavors:
 * - `send()` for US/international numbers where you send from one of your Allo numbers
 * - `sendFrance()` for French recipients, which requires a verified Sender ID
 *   (alphanumeric name or shortcode) — see
 *   https://help.withallo.com/en/api-reference/sms/send-sms-france
 */
final class Sms extends Resource
{
    /**
     * Send an SMS from one of your Allo numbers (non-FR destinations).
     *
     * @param  string  $from  Your Allo phone number (E.164)
     * @param  string  $to  Recipient phone number (E.164, same country as $from)
     * @param  string  $message  1–1000 chars
     * @return array<string, mixed> sent message details
     */
    public function send(string $from, string $to, string $message): array
    {
        $response = $this->http->post('/sms', [
            'from' => $from,
            'to' => $to,
            'message' => $message,
        ]);

        /** @var array<string, mixed> $data */
        $data = $response['data'] ?? [];

        return $data;
    }

    /**
     * Send an SMS to a French recipient using a verified Sender ID.
     *
     * @param  string  $senderId  Alphanumeric sender (3–11 chars) or short code
     * @param  string  $to  French recipient phone number (E.164, e.g. `+33612345678`)
     * @param  string  $message  Message body
     * @return array<string, mixed> sent message details
     */
    public function sendFrance(string $senderId, string $to, string $message): array
    {
        $response = $this->http->post('/sms', [
            'sender_id' => $senderId,
            'to' => $to,
            'message' => $message,
        ]);

        /** @var array<string, mixed> $data */
        $data = $response['data'] ?? [];

        return $data;
    }
}
