<?php

declare(strict_types=1);

namespace Packages\Outbox;

use Contracts\Events\EventEnvelope;
use DateTimeImmutable;
use DateTimeZone;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;

final class RabbitMqEventPublisher implements EventPublisher
{
    public function __construct(private readonly AMQPChannel $channel)
    {
        $this->channel->confirm_select();
        $this->channel->set_nack_handler(static function (AMQPMessage $message): never {
            throw new RuntimeException('RabbitMQ NACKed domain event publication');
        });
        $this->channel->set_return_listener(static function (): never {
            throw new RuntimeException('Domain event was not routed to a stream');
        });
    }

    public function publish(OutboxRecord $event): void
    {
        $body = (new EventEnvelope(
            eventId: $event->eventId,
            eventType: $event->eventType,
            eventVersion: $event->eventVersion,
            occurredAt: $event->createdAt === null
                ? gmdate('Y-m-d\TH:i:s\Z')
                : (new DateTimeImmutable($event->createdAt, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            traceId: '',
            producer: 'user-service',
            key: $event->aggregateId,
            payload: $event->payload,
        ))->toJson();

        $message = new AMQPMessage($body, [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'message_id' => $event->eventId,
        ]);

        $this->channel->basic_publish($message, 'domain.events', $event->eventType, true);
        $this->channel->wait_for_pending_acks_returns(5.0);
    }
}
