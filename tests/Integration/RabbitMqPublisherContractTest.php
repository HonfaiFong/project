<?php

declare(strict_types=1);

namespace Tests\Integration;

use Contracts\Events\EventEnvelope;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Packages\Outbox\OutboxRecord;
use Packages\Outbox\RabbitMqEventPublisher;
use RuntimeException;
use Tests\TestCase;

final class RabbitMqPublisherContractTest extends TestCase
{
    public function testDefinitionsRouteAConfirmedEnvelopeAndReturnAnUnknownEvent(): void
    {
        if (getenv('RABBITMQ_INTEGRATION') !== '1') {
            $this->markTestSkipped('Set RABBITMQ_INTEGRATION=1 with a broker loaded from definitions.json');
        }

        $connection = new AMQPStreamConnection(
            getenv('RABBITMQ_HOST') ?: '127.0.0.1',
            (int)(getenv('RABBITMQ_PORT') ?: 5672),
            getenv('RABBITMQ_USER') ?: 'guest',
            getenv('RABBITMQ_PASSWORD') ?: 'guest',
        );
        $consumer = $connection->channel();
        $publisherChannel = $connection->channel();
        try {
            $consumer->basic_qos(0, 10, false);
            $received = null;
            $eventId = bin2hex(random_bytes(16));
            $consumer->basic_consume(
                'user.events',
                '',
                false,
                false,
                false,
                false,
                static function (AMQPMessage $message) use (&$received, $eventId): void {
                    $body = json_decode($message->getBody(), true, flags: JSON_THROW_ON_ERROR);
                    if ($body['event_id'] === $eventId) {
                        $received = EventEnvelope::fromArray($body);
                    }
                    $message->ack();
                },
                null,
                new AMQPTable(['x-stream-offset' => 'next']),
            );

            $publisher = new RabbitMqEventPublisher($publisherChannel, 'user-service');
            $publisher->publish(new OutboxRecord(
                $eventId, 'user', 'user-1', 'user.registered.v1', 1,
                ['id' => 'user-1'], 'user-1', 'trace-1',
                createdAt: '2026-09-28 08:00:00.000',
            ));

            $deadline = microtime(true) + 5;
            while ($received === null && microtime(true) < $deadline) {
                try {
                    $consumer->wait(null, false, 1.0);
                } catch (AMQPTimeoutException) {
                    // 继续执行，直到限定截止时间。
                }
            }
            $this->assertInstanceOf(EventEnvelope::class, $received);
            $this->assertSame($eventId, $received->eventId);
            $this->assertSame('user.registered.v1', $received->eventType);
            $this->assertSame(['id' => 'user-1'], $received->payload);
            $this->assertSame('user-1', $received->key);
            $this->assertSame('trace-1', $received->traceId);
            $this->assertSame('user-service', $received->producer);

            try {
                $publisher->publish(new OutboxRecord(
                    bin2hex(random_bytes(16)), 'user', 'user-1', 'unbound.contract-test.v1', 1,
                    [], 'user-1', 'trace-1',
                ));
                $this->fail('Expected a mandatory return for an unbound routing key');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('not routed', $exception->getMessage());
            }
        } finally {
            $publisherChannel->close();
            $consumer->close();
            $connection->close();
        }
    }
}
