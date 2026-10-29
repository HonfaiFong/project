<?php

declare(strict_types=1);

namespace Services\ChatService\Infrastructure;

use Packages\Observability\MetricsRegistry;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;

/** Transfer ownership only after a persistent, mandatory publication is confirmed. */
final readonly class RabbitMqMessageRecovery
{
    public function __construct(private AMQPChannel $channel)
    {
        $channel->confirm_select();
        $channel->set_nack_handler(static function (): never {
            throw new RuntimeException('RabbitMQ rejected message recovery publication');
        });
        $channel->set_return_listener(static function (): never {
            throw new RuntimeException('Message recovery publication was not routed');
        });
    }

    public function retry(AMQPMessage $delivery): void
    {
        $headers = $delivery->has('application_headers')
            ? $delivery->get('application_headers')->getNativeData() : [];
        $attempt = min(1000, max(0, (int)($headers['x-chat-retry-count'] ?? 0)) + 1);
        $headers['x-chat-retry-count'] = $attempt;
        $tier = $attempt === 1 ? '5s' : ($attempt <= 5 ? '30s' : '300s');
        $this->transfer($delivery, 'chat.message.retry.' . $tier, $headers);
        MetricsRegistry::instance()->inc('chat_message_retries_total', ['tier' => $tier]);
    }

    public function quarantine(AMQPMessage $delivery): void
    {
        $this->transfer($delivery, 'chat.message.invalid', []);
        MetricsRegistry::instance()->inc('chat_message_invalid_total');
    }

    public function replay(AMQPMessage $delivery): void
    {
        $this->transfer($delivery, 'chat.message.created', []);
    }

    /** @param array<string,mixed> $headers */
    private function transfer(AMQPMessage $delivery, string $queue, array $headers): void
    {
        // Do not copy broker x-death history or expiration into a fresh retry cycle.
        foreach (array_keys($headers) as $name) {
            if ($name === 'x-death' || str_starts_with($name, 'x-first-death-') || str_starts_with($name, 'x-last-death-')) {
                unset($headers[$name]);
            }
        }
        $message = new AMQPMessage($delivery->getBody(), [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'application_headers' => new AMQPTable($headers),
            'message_id' => $delivery->has('message_id') ? $delivery->get('message_id') : '',
        ]);
        $this->channel->basic_publish($message, '', $queue, true);
        $this->channel->wait_for_pending_acks_returns(5.0);
        $delivery->ack();
    }
}
