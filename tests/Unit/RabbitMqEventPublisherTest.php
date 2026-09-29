<?php

declare(strict_types=1);

namespace Tests\Unit;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Packages\Outbox\OutboxRecord;
use Packages\Outbox\RabbitMqEventPublisher;
use RuntimeException;
use Tests\TestCase;

final class RabbitMqEventPublisherTest extends TestCase
{
    public function testPublishesTheSharedEnvelopeAfterAck(): void
    {
        $channel = $this->createMock(AMQPChannel::class);
        $channel->expects($this->once())->method('confirm_select');
        $channel->expects($this->once())->method('set_nack_handler');
        $channel->expects($this->once())->method('set_return_listener');
        $channel->expects($this->once())->method('basic_publish')
            ->with($this->callback(function (AMQPMessage $message): bool {
                $body = json_decode($message->getBody(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame(
                    ['event_id', 'event_type', 'event_version', 'occurred_at', 'trace_id', 'producer', 'key', 'payload'],
                    array_keys($body),
                );
                $this->assertSame('user-service', $body['producer']);
                $this->assertSame('user-1', $body['key']);
                $this->assertSame('2026-09-28T08:00:00Z', $body['occurred_at']);
                return true;
            }), 'domain.events', 'user.registered.v1', true);
        $channel->expects($this->once())->method('wait_for_pending_acks_returns')->with(5.0);

        (new RabbitMqEventPublisher($channel))->publish($this->event());
    }

    public function testNackFailsPublication(): void
    {
        $nackHandler = null;
        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('set_nack_handler')->willReturnCallback(static function (callable $callback) use (&$nackHandler): void {
            $nackHandler = $callback;
        });
        $channel->method('wait_for_pending_acks_returns')->willReturnCallback(static function () use (&$nackHandler): void {
            $nackHandler(new AMQPMessage(''));
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NACKed');
        (new RabbitMqEventPublisher($channel))->publish($this->event());
    }

    public function testMandatoryReturnFailsPublication(): void
    {
        $returnHandler = null;
        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('set_return_listener')->willReturnCallback(static function (callable $callback) use (&$returnHandler): void {
            $returnHandler = $callback;
        });
        $channel->method('wait_for_pending_acks_returns')->willReturnCallback(static function () use (&$returnHandler): void {
            $returnHandler();
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not routed');
        (new RabbitMqEventPublisher($channel))->publish($this->event());
    }

    private function event(): OutboxRecord
    {
        return new OutboxRecord('event-1', 'user', 'user-1', 'user.registered.v1', 1, ['id' => 'user-1'], createdAt: '2026-09-28 08:00:00.000');
    }
}
