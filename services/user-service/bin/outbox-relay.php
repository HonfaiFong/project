<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Packages\Outbox\OutboxRelay;
use Packages\Outbox\OutboxRepository;
use Packages\Outbox\RabbitMqEventPublisher;
use PhpAmqpLib\Connection\AMQPStreamConnection;

for (;;) {
    try {
        $pdo = new PDO(sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('MYSQL_HOST') ?: 'mysql',
            getenv('MYSQL_PORT') ?: '3306',
            getenv('USER_DB_NAME') ?: 'user_db',
        ), getenv('MYSQL_USER') ?: 'root', getenv('MYSQL_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $connection = new AMQPStreamConnection(
            getenv('RABBITMQ_HOST') ?: 'rabbitmq',
            (int)(getenv('RABBITMQ_PORT') ?: 5672),
            getenv('RABBITMQ_USER') ?: '',
            getenv('RABBITMQ_PASSWORD') ?: '',
        );
        $channel = $connection->channel();
        $relay = new OutboxRelay(new OutboxRepository($pdo), new RabbitMqEventPublisher($channel));
        for (;;) {
            if ($relay->runOnce() === 0) {
                usleep(500_000);
            }
        }
    } catch (Throwable $exception) {
        error_log('Outbox relay failed: ' . $exception->getMessage());
        sleep(5);
    } finally {
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
