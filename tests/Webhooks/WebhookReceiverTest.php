<?php

declare(strict_types=1);

namespace QrCommunication\Withallo\Tests\Webhooks;

use PHPUnit\Framework\TestCase;
use QrCommunication\Withallo\Enums\WebhookTopic;
use QrCommunication\Withallo\Exceptions\InvalidWebhookPayloadException;
use QrCommunication\Withallo\Webhooks\WebhookEvent;
use QrCommunication\Withallo\Webhooks\WebhookReceiver;

final class WebhookReceiverTest extends TestCase
{
    public function test_parse_builds_typed_event(): void
    {
        $receiver = new WebhookReceiver;
        $event = $receiver->parse((string) json_encode([
            'topic' => 'CALL_RECEIVED',
            'data' => ['id' => 'call_1', 'result' => 'ANSWERED'],
        ]));

        $this->assertSame(WebhookTopic::CALL_RECEIVED, $event->topic);
        $this->assertTrue($event->isCall());
        $this->assertFalse($event->isSms());
        $this->assertSame('call_1', $event->get('id'));
    }

    public function test_parse_rejects_empty_body(): void
    {
        $this->expectException(InvalidWebhookPayloadException::class);
        (new WebhookReceiver)->parse('');
    }

    public function test_parse_rejects_invalid_json(): void
    {
        $this->expectException(InvalidWebhookPayloadException::class);
        (new WebhookReceiver)->parse('not-json');
    }

    public function test_parse_rejects_missing_topic(): void
    {
        $this->expectException(InvalidWebhookPayloadException::class);
        (new WebhookReceiver)->parse((string) json_encode(['data' => []]));
    }

    public function test_parse_rejects_missing_data(): void
    {
        $this->expectException(InvalidWebhookPayloadException::class);
        (new WebhookReceiver)->parse((string) json_encode(['topic' => 'CALL_RECEIVED']));
    }

    public function test_parse_rejects_unknown_topic(): void
    {
        $this->expectException(InvalidWebhookPayloadException::class);
        (new WebhookReceiver)->parse((string) json_encode([
            'topic' => 'WHATEVER',
            'data' => [],
        ]));
    }

    public function test_dispatch_invokes_registered_handlers_in_order(): void
    {
        $receiver = new WebhookReceiver;
        $calls = [];

        $receiver
            ->on(WebhookTopic::SMS_RECEIVED, function (WebhookEvent $e) use (&$calls) {
                $calls[] = 'first:'.$e->get('id');
            })
            ->on(WebhookTopic::SMS_RECEIVED, function (WebhookEvent $e) use (&$calls) {
                $calls[] = 'second:'.$e->get('id');
            });

        $count = $receiver->dispatch(new WebhookEvent(
            topic: WebhookTopic::SMS_RECEIVED,
            data: ['id' => 'sms_1'],
            raw: [],
        ));

        $this->assertSame(2, $count);
        $this->assertSame(['first:sms_1', 'second:sms_1'], $calls);
    }

    public function test_dispatch_returns_zero_when_no_handler_registered(): void
    {
        $receiver = new WebhookReceiver;

        $count = $receiver->dispatch(new WebhookEvent(
            topic: WebhookTopic::CONTACT_CREATED,
            data: [],
            raw: [],
        ));

        $this->assertSame(0, $count);
    }

    public function test_handle_parses_and_dispatches(): void
    {
        $receiver = new WebhookReceiver;
        $received = null;
        $receiver->on(WebhookTopic::CONTACT_UPDATED, function (WebhookEvent $e) use (&$received) {
            $received = $e->get('id');
        });

        $receiver->handle((string) json_encode([
            'topic' => 'CONTACT_UPDATED',
            'data' => ['id' => 'cnt_1'],
        ]));

        $this->assertSame('cnt_1', $received);
    }

    public function test_get_supports_dot_path(): void
    {
        $event = new WebhookEvent(
            topic: WebhookTopic::CALL_RECEIVED,
            data: ['transfer_from' => ['user_name' => 'Alice']],
            raw: [],
        );

        $this->assertSame('Alice', $event->get('transfer_from.user_name'));
        $this->assertNull($event->get('transfer_from.missing'));
        $this->assertSame('default', $event->get('missing.path', 'default'));
    }
}
