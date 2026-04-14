<?php

declare(strict_types=1);

namespace QrCommunication\Withallo\Tests\Resources;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use QrCommunication\Withallo\Resources\Contacts;
use QrCommunication\Withallo\Tests\Support\MockHttpClient;

final class ContactsTest extends TestCase
{
    public function test_get_uses_singular_contact_path(): void
    {
        $mock = new MockHttpClient([
            new Response(200, [], (string) json_encode(['data' => ['id' => 'cnt_abc']])),
        ]);

        $contacts = new Contacts($mock->client);
        $contacts->get('cnt_abc');

        $this->assertSame('GET', $mock->lastRequestMethod());
        $this->assertStringContainsString('/v1/api/contact/cnt_abc', $mock->lastRequestUri());
    }

    public function test_create_drops_null_fields_and_sends_required_numbers(): void
    {
        $mock = new MockHttpClient([
            new Response(201, [], (string) json_encode(['data' => ['id' => 'cnt_1']])),
        ]);

        $contacts = new Contacts($mock->client);
        $contacts->create(numbers: ['+15551234567'], name: 'John');

        $body = $mock->lastRequestBody();
        $this->assertSame(['+15551234567'], $body['numbers']);
        $this->assertSame('John', $body['name']);
        $this->assertArrayNotHasKey('last_name', $body);
        $this->assertArrayNotHasKey('emails', $body);
    }

    public function test_search_paginates_with_defaults(): void
    {
        $mock = new MockHttpClient([
            new Response(200, [], (string) json_encode([
                'data' => ['results' => [], 'metadata' => ['pagination' => ['total_pages' => 0, 'current_page' => 0]]],
            ])),
        ]);

        $contacts = new Contacts($mock->client);
        $contacts->search();

        $uri = $mock->lastRequestUri();
        $this->assertStringContainsString('page=0', $uri);
        $this->assertStringContainsString('size=10', $uri);
    }

    public function test_conversation_uses_contact_id_in_path(): void
    {
        $mock = new MockHttpClient([
            new Response(200, [], (string) json_encode(['data' => ['results' => []]])),
        ]);

        $contacts = new Contacts($mock->client);
        $contacts->searchConversation('cnt_abc', page: 2, size: 50);

        $this->assertStringContainsString('/v1/api/contact/cnt_abc/conversation', $mock->lastRequestUri());
        $this->assertStringContainsString('page=2', $mock->lastRequestUri());
        $this->assertStringContainsString('size=50', $mock->lastRequestUri());
    }
}
