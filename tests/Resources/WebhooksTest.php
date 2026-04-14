<?php

declare(strict_types=1);

namespace QrCommunication\Withallo\Tests\Resources;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use QrCommunication\Withallo\Enums\WebhookTopic;
use QrCommunication\Withallo\Exceptions\AuthenticationException;
use QrCommunication\Withallo\Exceptions\ForbiddenException;
use QrCommunication\Withallo\Resources\Webhooks;
use QrCommunication\Withallo\Tests\Support\MockHttpClient;

final class WebhooksTest extends TestCase
{
    public function test_list_returns_data_array(): void
    {
        $mock = new MockHttpClient([
            new Response(200, [], (string) json_encode([
                'data' => [
                    [
                        'allo_number' => '+1234567890',
                        'enabled' => true,
                        'url' => 'https://example.com/hook',
                        'topics' => ['CALL_RECEIVED'],
                    ],
                ],
            ])),
        ]);

        $webhooks = new Webhooks($mock->client);
        $result = $webhooks->list();

        $this->assertCount(1, $result);
        $this->assertSame('+1234567890', $result[0]['allo_number']);
        $this->assertSame('GET', $mock->lastRequestMethod());
        $this->assertStringContainsString('/v1/api/webhooks', $mock->lastRequestUri());
    }

    public function test_create_sends_body_with_enum_topics_converted(): void
    {
        $mock = new MockHttpClient([
            new Response(201, [], (string) json_encode([
                'data' => [
                    'id' => 'web-xyz',
                    'alloNumber' => '+1234567890',
                    'enabled' => true,
                    'url' => 'https://example.com/hook',
                    'topics' => ['CALL_RECEIVED', 'SMS_RECEIVED'],
                ],
            ])),
        ]);

        $webhooks = new Webhooks($mock->client);
        $result = $webhooks->create(
            alloNumber: '+1234567890',
            url: 'https://example.com/hook',
            topics: [WebhookTopic::CALL_RECEIVED, WebhookTopic::SMS_RECEIVED],
        );

        $this->assertSame('web-xyz', $result['id']);

        $body = $mock->lastRequestBody();
        $this->assertSame('+1234567890', $body['allo_number']);
        $this->assertSame('https://example.com/hook', $body['url']);
        $this->assertTrue($body['enabled']);
        $this->assertSame(['CALL_RECEIVED', 'SMS_RECEIVED'], $body['topics']);
    }

    public function test_create_accepts_string_topics(): void
    {
        $mock = new MockHttpClient([
            new Response(201, [], (string) json_encode(['data' => ['id' => 'web-1']])),
        ]);

        $webhooks = new Webhooks($mock->client);
        $webhooks->create(
            alloNumber: '+1234567890',
            url: 'https://example.com/hook',
            topics: ['CONTACT_CREATED'],
        );

        $this->assertSame(['CONTACT_CREATED'], $mock->lastRequestBody()['topics']);
    }

    public function test_delete_encodes_webhook_id(): void
    {
        $mock = new MockHttpClient([new Response(204, [], '')]);

        $webhooks = new Webhooks($mock->client);
        $webhooks->delete('web abc/123');

        $this->assertSame('DELETE', $mock->lastRequestMethod());
        $this->assertStringContainsString('/v1/api/webhooks/web%20abc%2F123', $mock->lastRequestUri());
    }

    public function test_api_key_is_sent_without_bearer_prefix(): void
    {
        $mock = new MockHttpClient([
            new Response(200, [], (string) json_encode(['data' => []])),
        ], apiKey: 'my-secret-key');

        $webhooks = new Webhooks($mock->client);
        $webhooks->list();

        $this->assertSame('my-secret-key', $mock->lastRequestHeader('Authorization'));
    }

    public function test_401_raises_authentication_exception(): void
    {
        $mock = new MockHttpClient([
            new Response(401, [], (string) json_encode(['code' => 'API_KEY_INVALID', 'details' => null])),
        ]);

        $webhooks = new Webhooks($mock->client);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('API_KEY_INVALID');

        $webhooks->list();
    }

    public function test_403_raises_forbidden_exception_with_scopes(): void
    {
        $mock = new MockHttpClient([
            new Response(403, [], (string) json_encode([
                'code' => 'API_KEY_INSUFFICIENT_SCOPE',
                'details' => [
                    ['message' => 'required=WEBHOOKS_READ_WRITE', 'field' => 'scope'],
                ],
            ])),
        ]);

        $webhooks = new Webhooks($mock->client);

        try {
            $webhooks->list();
            $this->fail('Expected ForbiddenException');
        } catch (ForbiddenException $e) {
            $this->assertSame('API_KEY_INSUFFICIENT_SCOPE', $e->getErrorCode());
            $this->assertSame(['WEBHOOKS_READ_WRITE'], $e->requiredScopes());
        }
    }
}
