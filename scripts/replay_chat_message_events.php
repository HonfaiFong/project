<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Contracts\Events\EventTypes;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Services\ChatService\Infrastructure\RabbitMqMessageRecovery;

// Bounded snapshot: do not cycle over requeued, unrelated jobs indefinitely.
$queue = $argv[1] ?? 'chat.message.invalid';
if (!in_array($queue, ['chat.message.invalid', 'jobs.failed'], true)) {
    throw new RuntimeException('Replay supports chat.message.invalid or legacy jobs.failed only');
}
$connection = new AMQPStreamConnection(
    getenv('RABBITMQ_HOST') ?: 'rabbitmq', (int)(getenv('RABBITMQ_PORT') ?: 5672),
    getenv('RABBITMQ_USER') ?: '', getenv('RABBITMQ_PASSWORD') ?: '',
);
$channel = $connection->channel();
$publisher = $connection->channel();
try {
    [, $count] = $channel->queue_declare($queue, true);
    $recovery = new RabbitMqMessageRecovery($publisher);
    $replayed = 0;
    for ($i = 0; $i < $count; $i++) {
        $delivery = $channel->basic_get($queue);
        if ($delivery === null) {
            break;
        }
        $data = json_decode($delivery->getBody(), true);
        if (is_array($data) && ($data['event_type'] ?? '') === EventTypes::MESSAGE_CREATED) {
            $recovery->replay($delivery);
            $replayed++;
        }
        // Leave unrelated/malformed deliveries unacked until close requeues them.
    }
    fwrite(STDOUT, "Replayed {$replayed} message events\n");
} finally {
    try { $publisher->close(); } finally {
        try { $channel->close(); } finally { $connection->close(); }
    }
}
