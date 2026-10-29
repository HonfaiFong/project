<?php

declare(strict_types=1);

namespace Tests\Integration;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Services\ChatService\Infrastructure\RabbitMqMessageRecovery;
use Tests\TestCase;

final class RabbitMqMessageRecoveryTest extends TestCase
{
    private AMQPStreamConnection $connection;
    private AMQPChannel $consumer;
    private AMQPChannel $publisher;
    private AMQPChannel $recoveryChannel;
    private RabbitMqMessageRecovery $recovery;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RABBITMQ_INTEGRATION') !== '1') {
            self::markTestSkipped('RABBITMQ_INTEGRATION=1 and a disposable broker are required');
        }
        $this->connection = new AMQPStreamConnection(
            getenv('RABBITMQ_HOST') ?: '127.0.0.1', (int)(getenv('RABBITMQ_PORT') ?: 5672),
            getenv('RABBITMQ_USER') ?: 'guest', getenv('RABBITMQ_PASSWORD') ?: 'guest',
        );
        $this->consumer = $this->connection->channel();
        $this->publisher = $this->connection->channel();
        $this->publisher->confirm_select();
        $this->recoveryChannel = $this->connection->channel();
        $this->recovery = new RabbitMqMessageRecovery($this->recoveryChannel);
        foreach (['chat.message.created', 'chat.message.retry.5s', 'chat.message.retry.30s',
            'chat.message.retry.300s', 'chat.message.invalid', 'chat.message.recovery'] as $queue) {
            $this->consumer->queue_purge($queue);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->close();
        }
        parent::tearDown();
    }

    public function testTransientFailuresRemainReplayableBeyondQuorumDeliveryLimit(): void
    {
        $body = '{"event_id":"same-event"}';
        $this->send(new AMQPMessage($body));
        for ($attempt = 1; $attempt <= 7; $attempt++) {
            $this->recovery->retry($this->receive('chat.message.created'));
            $tier = $attempt === 1 ? '5s' : ($attempt <= 5 ? '30s' : '300s');
            $delayed = $this->receive('chat.message.retry.' . $tier);
            self::assertSame($body, $delayed->getBody());
            self::assertSame($attempt, $delayed->get('application_headers')->getNativeData()['x-chat-retry-count']);
            self::assertSame(AMQPMessage::DELIVERY_MODE_PERSISTENT, $delayed->get('delivery_mode'));
            // Fast-forward the delay while retaining the real broker acknowledgement boundary.
            $this->send(new AMQPMessage($body, $delayed->get_properties()));
            $delayed->ack();
        }
        $this->receive('chat.message.created')->ack();
        self::assertNull($this->consumer->basic_get('chat.message.invalid'));
    }

    public function testQueueTtlReturnsTheSameEventToTheConsumer(): void
    {
        $this->send(new AMQPMessage('unchanged-body'));
        $this->recovery->retry($this->receive('chat.message.created'));
        $redelivery = $this->receive('chat.message.created', 10);
        self::assertSame('unchanged-body', $redelivery->getBody());
        $redelivery->ack();
    }

    public function testExpiredRetrySurvivesAnUnavailableDeadLetterTarget(): void
    {
        $this->send(new AMQPMessage('survives-missing-target'));
        $this->recovery->retry($this->receive('chat.message.created'));
        $this->consumer->queue_delete('chat.message.created');
        try {
            // The retry expires while the destination is absent. at-most-once DLX would lose it.
            usleep(6_000_000);
            $this->declarePrimary();
            // RabbitMQ 4.1 defaults to a 180s internal dead-letter confirm/retry interval.
            $delivery = $this->receive('chat.message.created', 200);
            self::assertSame('survives-missing-target', $delivery->getBody());
            $delivery->ack();
        } finally {
            $this->declarePrimary();
        }
    }

    public function testBrokerDeadLettersCanBeAutomaticallyReplayedAndPoisonIsIsolated(): void
    {
        $this->publisher->basic_publish(new AMQPMessage('crashed-worker'), 'domain.events', 'message.created.v1', true);
        $this->publisher->wait_for_pending_acks_returns(5.0);
        $this->receive('chat.message.created')->nack(false, false);
        $this->recovery->replay($this->receive('chat.message.recovery'));
        $event = $this->receive('chat.message.created');
        self::assertSame('crashed-worker', $event->getBody());
        self::assertArrayNotHasKey('x-death', $event->get('application_headers')->getNativeData());
        $this->recovery->quarantine($event);
        $invalid = $this->receive('chat.message.invalid');
        self::assertSame('crashed-worker', $invalid->getBody());
        $invalid->ack();
    }

    public function testFailedRecoveryPublicationLeavesTheOriginalUnacknowledged(): void
    {
        $this->send(new AMQPMessage('must-survive'));
        $delivery = $this->receive('chat.message.created');
        $this->recoveryChannel->close();
        try {
            $this->recovery->retry($delivery);
            self::fail('Closed publisher must fail');
        } catch (\PhpAmqpLib\Exception\AMQPChannelClosedException) {
            $this->consumer->close();
        }
        $this->consumer = $this->connection->channel();
        $retried = $this->receive('chat.message.created');
        self::assertSame('must-survive', $retried->getBody());
        $retried->ack();
    }

    public function testLegacyReplayLeavesUnrelatedJobsInTheFailedQueue(): void
    {
        [, $before] = $this->consumer->queue_declare('jobs.failed', true);
        $body = '{"event_type":"message.created.v1","event_id":"legacy-event"}';
        foreach ([$body, '{"event_type":"image.resize","job_id":"unrelated"}'] as $payload) {
            $this->publisher->basic_publish(new AMQPMessage($payload), '', 'jobs.failed', true);
            $this->publisher->wait_for_pending_acks_returns(5.0);
        }
        $process = proc_open([PHP_BINARY, __DIR__ . '/../../scripts/replay_chat_message_events.php', 'jobs.failed'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $output . $error);
        self::assertSame("Replayed 1 message events\n", $output);
        $delivery = $this->receive('chat.message.created');
        self::assertSame($body, $delivery->getBody());
        $delivery->ack();
        [, $remaining] = $this->consumer->queue_declare('jobs.failed', true);
        self::assertSame($before + 1, $remaining);
    }

    private function send(AMQPMessage $message): void
    {
        $this->publisher->basic_publish($message, '', 'chat.message.created', true);
        $this->publisher->wait_for_pending_acks_returns(5.0);
    }

    private function declarePrimary(): void
    {
        $this->consumer->queue_declare('chat.message.created', false, true, false, false, false, new AMQPTable([
            'x-queue-type' => 'quorum', 'x-delivery-limit' => 5, 'x-dead-letter-exchange' => 'jobs.dead',
        ]));
        $this->consumer->queue_bind('chat.message.created', 'domain.events', 'message.created.v1');
    }

    private function receive(string $queue, int $timeoutSeconds = 3): AMQPMessage
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $delivery = $this->consumer->basic_get($queue);
            if ($delivery !== null) {
                return $delivery;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        self::fail('No delivery received from ' . $queue);
    }
}
