<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Packages\Database\PostgresConnectionFactory;
use Packages\Observability\MetricsHttpServer;
use Packages\Outbox\OutboxRelay;
use Packages\Outbox\OutboxRepository;
use Packages\Outbox\RabbitMqEventPublisher;
use PhpAmqpLib\Connection\AMQPStreamConnection;

MetricsHttpServer::startInBackground();

for (;;) {
    $connection = null;
    $channel = null;
    try {
        $pdo = PostgresConnectionFactory::fromEnvironment();
        $pdo->query('SELECT 1');
        $connection = new AMQPStreamConnection(
            getenv('RABBITMQ_HOST') ?: 'rabbitmq',
            (int)(getenv('RABBITMQ_PORT') ?: 5672),
            getenv('RABBITMQ_USER') ?: '',
            getenv('RABBITMQ_PASSWORD') ?: '',
        );
        $channel = $connection->channel();
        $relay = new OutboxRelay(
            new OutboxRepository($pdo, orderingColumn: 'message_seq'),
            new RabbitMqEventPublisher($channel, 'message-service'),
        );
        for (;;) {
            if ($relay->runOnce() === 0) {
                usleep(500_000);
            }
        }
    } catch (Throwable $exception) {
        error_log('Message outbox relay failed: ' . $exception::class);
        sleep(5);
    } finally {
        if ($channel !== null) {
            try { $channel->close(); } catch (Throwable) {}
        }
        if ($connection !== null) {
            try { $connection->close(); } catch (Throwable) {}
        }
    }
}
