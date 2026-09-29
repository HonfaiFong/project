<?php

declare(strict_types=1);

namespace Packages\Outbox;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;

final class RabbitMqEventPublisher implements EventPublisher
{
    public function __construct(private readonly AMQPChannel $channel)
    {
        $this->channel->confirm_select();
        $this->channel->set_return_listener(static function (): never {
            throw new RuntimeException('Domain event was not routed to a stream');
        });
    }

    public function publish(OutboxRecord $event): void
    {
        $body = json_encode([
            'event_id' => $event->eventId,
            'aggregate_type' => $event->aggregateType,
            'aggregate_id' => $event->aggregateId,
            'event_type' => $event->eventType,
            'event_version' => $event->eventVersion,
            'payload' => $event->payload,
            'created_at' => $event->createdAt,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $message = new AMQPMessage($body, [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'message_id' => $event->eventId,
        ]);

        $this->channel->basic_publish($message, 'domain.events', $event->eventType, true);
        $this->channel->wait_for_pending_acks_returns(5.0);
    }
}
