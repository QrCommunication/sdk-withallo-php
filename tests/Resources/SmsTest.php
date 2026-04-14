<?php

declare(strict_types=1);

namespace QrCommunication\Withallo\Tests\Resources;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use QrCommunication\Withallo\Resources\Sms;
use QrCommunication\Withallo\Tests\Support\MockHttpClient;

final class SmsTest extends TestCase
{
    public function test_send_us_uses_from_field(): void
    {
        $mock = new MockHttpClient([
            new Response(200, [], (string) json_encode([
                'data' => [
                    'from_number' => '+1234567890',
                    'to_number' => '+0987654321',
                    'type' => 'OUTBOUND',
                    'content' => 'hi',
                ],
            ])),
        ]);

        $sms = new Sms($mock->client);
        $sms->send(from: '+1234567890', to: '+0987654321', message: 'hi');

        $body = $mock->lastRequestBody();
        $this->assertSame('+1234567890', $body['from']);
        $this->assertSame('+0987654321', $body['to']);
        $this->assertSame('hi', $body['message']);
        $this->assertArrayNotHasKey('sender_id', $body);
        $this->assertStringContainsString('/v1/api/sms', $mock->lastRequestUri());
    }

    public function test_send_france_uses_sender_id(): void
    {
        $mock = new MockHttpClient([
            new Response(200, [], (string) json_encode([
                'data' => [
                    'sender_id' => 'MyCompany',
                    'to_number' => '+33612345678',
                    'type' => 'OUTBOUND',
                    'content' => 'bonjour',
                ],
            ])),
        ]);

        $sms = new Sms($mock->client);
        $sms->sendFrance(senderId: 'MyCompany', to: '+33612345678', message: 'bonjour');

        $body = $mock->lastRequestBody();
        $this->assertSame('MyCompany', $body['sender_id']);
        $this->assertArrayNotHasKey('from', $body);
    }
}
