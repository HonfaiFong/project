<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Contracts\Events\EventEnvelope;
use Packages\Database\EloquentConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Services\ChatService\Application\MessageCreatedHandler;
use Services\ChatService\Infrastructure\RedisRealtimeMessagePublisher;

[$application, $repository, $logger, $pdo] = require __DIR__ . '/bootstrap.php';

for (;;) {
    try {
        $redis = new Redis();
        $host = getenv('REDIS_HOST') ?: 'redis';
        if (filter_var(getenv('REDIS_TLS') ?: 'false', FILTER_VALIDATE_BOOLEAN)) {
            $host = 'tls://' . $host;
        }
        if (!$redis->connect($host, (int)(getenv('REDIS_PORT') ?: 6379), 2.0)) {
            throw new RuntimeException('Realtime Redis connection failed');
        }
        $redis->setOption(Redis::OPT_READ_TIMEOUT, 2.0);
        $password = getenv('REDIS_PASSWORD') ?: '';
        $username = getenv('REDIS_USERNAME') ?: '';
        if ($password !== '' && !$redis->auth($username !== '' ? [$username, $password] : $password)) {
            throw new RuntimeException('Realtime Redis authentication failed');
        }
        $connection = new AMQPStreamConnection(
            getenv('RABBITMQ_HOST') ?: 'rabbitmq', (int)(getenv('RABBITMQ_PORT') ?: 5672),
            getenv('RABBITMQ_USER') ?: '', getenv('RABBITMQ_PASSWORD') ?: '',
        );
        $channel = $connection->channel();
        $channel->queue_declare('chat.message.created', false, true, false, false, false, new AMQPTable([
            'x-queue-type' => 'quorum', 'x-delivery-limit' => 5, 'x-dead-letter-exchange' => 'jobs.dead',
        ]));
        $channel->queue_bind('chat.message.created', 'domain.events', 'message.created.v1');
        $channel->basic_qos(0, 1, false);
        $handler = new MessageCreatedHandler($repository, $application, new RedisRealtimeMessagePublisher($redis));
        $channel->basic_consume('chat.message.created', '', false, false, false, false,
            static function (AMQPMessage $delivery) use ($handler): void {
                try {
                    $data = json_decode($delivery->getBody(), true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($data)) {
                        throw new RuntimeException('Invalid event envelope');
                    }
                    $handler->handle(EventEnvelope::fromArray($data));
                    $delivery->ack();
                } catch (Throwable $exception) {
                    $delivery->nack(false, true);
                    throw $exception;
                }
            },
        );
        while ($channel->is_consuming()) {
            $channel->wait();
        }
    } catch (Throwable $exception) {
        $logger->error('Message consumer failed', ['exception' => $exception::class]);
        // Exit so the supervisor recreates PDO, repositories and RPC clients as well as broker connections.
        // Retrying with a disconnected PDO would repeatedly dead-letter otherwise valid events.
        throw $exception;
    } finally {
        if (isset($channel)) {
            try { $channel->close(); } catch (Throwable) {}
            unset($channel);
        }
        if (isset($connection)) {
            try { $connection->close(); } catch (Throwable) {}
            unset($connection);
        }
        if (isset($redis)) {
            try { $redis->close(); } catch (Throwable) {}
            unset($redis);
        }
        EloquentConnection::forget($pdo);
    }
}
