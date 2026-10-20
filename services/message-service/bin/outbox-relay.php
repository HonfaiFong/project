<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Packages\Database\MongoConnectionFactory;
use Packages\Observability\MetricsHttpServer;
use Packages\Outbox\OutboxRelay;
use Packages\Outbox\RabbitMqEventPublisher;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Services\MessageService\Infrastructure\Mongo\MongoOutboxRelayStore;

MetricsHttpServer::startInBackground();
$database = getenv('MONGODB_DATABASE') ?: 'message_db';

for (;;) {
    $connection = null;
    $channel = null;
    try {
        $mongo = MongoConnectionFactory::fromEnvironment([
            'MONGODB_URI' => getenv('MONGODB_RELAY_URI') ?: getenv('MONGODB_URI'),
        ]);
        $mongo->selectDatabase($database)->command(['ping' => 1]);
        $connection = new AMQPStreamConnection(
            getenv('RABBITMQ_HOST') ?: 'rabbitmq',
            (int)(getenv('RABBITMQ_PORT') ?: 5672),
            getenv('RABBITMQ_USER') ?: '',
            getenv('RABBITMQ_PASSWORD') ?: '',
        );
        $channel = $connection->channel();
        $relay = new OutboxRelay(
            new MongoOutboxRelayStore($mongo, $database),
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
