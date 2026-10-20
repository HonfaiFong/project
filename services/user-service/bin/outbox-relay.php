<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Packages\Outbox\OutboxRelay;
use Packages\Database\EloquentConnection;
use Packages\Database\PostgresConnectionFactory;
use Packages\Outbox\OutboxRepository;
use Packages\Outbox\RabbitMqEventPublisher;
use Packages\Observability\MetricsHttpServer;
use PhpAmqpLib\Connection\AMQPStreamConnection;

MetricsHttpServer::startInBackground();

for (;;) {
    try {
        $pdo = PostgresConnectionFactory::fromEnvironment();
        $connection = new AMQPStreamConnection(
            getenv('RABBITMQ_HOST') ?: 'rabbitmq',
            (int)(getenv('RABBITMQ_PORT') ?: 5672),
            getenv('RABBITMQ_USER') ?: '',
            getenv('RABBITMQ_PASSWORD') ?: '',
        );
        $channel = $connection->channel();
        $relay = new OutboxRelay(new OutboxRepository($pdo), new RabbitMqEventPublisher($channel, 'user-service'));
        for (;;) {
            if ($relay->runOnce() === 0) {
                usleep(500_000);
            }
        }
    } catch (Throwable $exception) {
        error_log('Outbox relay failed: ' . $exception->getMessage());
        sleep(5);
    } finally {
        if (isset($pdo)) {
            EloquentConnection::forget($pdo);
            unset($relay, $pdo);
        }
        if (isset($channel)) {
            try { $channel->close(); } catch (Throwable) {}
            unset($channel);
        }
        if (isset($connection)) {
            try { $connection->close(); } catch (Throwable) {}
            unset($connection);
        }
    }
}
