<?php

declare(strict_types=1);

namespace Tests\Integration;

use Contracts\Gen\Chat\V1\PublicChatTarget;
use Contracts\Events\EventEnvelope;
use Contracts\Events\EventTypes;
use PDO;
use Packages\Identity\AnonymousAuthorProjector;
use Packages\Identity\AnonymousReferenceFactory;
use Services\ChatService\Application\ChatApplicationService;
use Services\ChatService\Application\MessageCreatedHandler;
use Services\ChatService\Domain\RealtimeMessagePublisher;
use Services\ChatService\Domain\IdGenerator as ChatIdGenerator;
use Services\ChatService\Domain\Identity\UserIdentityClient;
use Services\ChatService\Domain\Social\AllowAllBlockStatusResolver;
use Services\ChatService\Infrastructure\PdoChatRepository;
use Packages\GrpcCommon\OperationBudget;
use Services\ChatService\Domain\MessageSequenceReader;
use Services\MessageService\Application\MessageApplicationService;
use Services\MessageService\Domain\ChatMemberSnapshotClient;
use Services\MessageService\Domain\Exception\MessageException;
use Services\MessageService\Domain\IdGenerator as MessageIdGenerator;
use Tests\Support\InMemoryMessageStore;
use Tests\TestCase;

final class ChatMessagePersistenceTest extends TestCase
{
    public function testChatSnapshotAndMessagePersistenceCrossTheServiceBoundary(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required; run PHP with -d extension=pdo_sqlite');
        }

        $chatPdo = new PDO('sqlite::memory:');
        $chatRepository = new PdoChatRepository($chatPdo);
        $chatRepository->ensureSchema();
        $users = $this->createMock(UserIdentityClient::class);
        $users->method('resolvePersonaMode')->willReturn(['persona' => [
            'id' => '101',
            'user_id' => '100',
            'type' => 1,
            'nickname' => 'Owner snapshot',
            'avatar_key' => null,
            'status' => 1,
            'is_internal_alias' => false,
            'identity_mode' => 1,
        ]]);
        $users->method('resolveMainPersona')->willReturn(['persona' => [
            'id' => '201',
            'user_id' => '200',
            'type' => 1,
            'nickname' => 'Peer snapshot',
            'avatar_key' => null,
            'status' => 1,
            'is_internal_alias' => false,
            'identity_mode' => 1,
        ]]);
        $state = new class {
            public bool $active = true;
            public bool $unavailable = false;
            public int $mode = 1;
        };
        $users->method('validatePersonaOwnership')->willReturnCallback(
            static function (string $userId, string $personaId, int $operation) use ($state): array {
                if ($state->unavailable) {
                    throw new \Packages\GrpcCommon\GrpcCallException(14, null, 'UserService unavailable');
                }
                if (!$state->active) {
                    throw new \RuntimeException('persona was deactivated', \Contracts\Errors\ErrorCode::PERSONA_FORBIDDEN->value);
                }

                return ['persona' => [
                    'id' => $personaId,
                    'user_id' => $userId,
                    'type' => 1,
                    'identity_mode' => $state->mode,
                    'status' => 1,
                ]];
            },
        );
        $chatIds = new class implements ChatIdGenerator {
            private int $next = 900;

            public function nextId(): string
            {
                return (string)$this->next++;
            }
        };
        $sequenceState = new class {
            public string $latest = '0';
            public int $calls = 0;
            public bool $unavailable = false;
        };
        $messageSequences = new class ($sequenceState) implements MessageSequenceReader {
            public function __construct(private readonly object $state)
            {
            }

            public function latestSequence(string $conversationId, OperationBudget $budget): string
            {
                $this->state->calls++;
                if ($this->state->unavailable) {
                    throw new \RuntimeException('MessageService unavailable', \Contracts\Errors\ErrorCode::SERVICE_UNAVAILABLE->value);
                }
                return $this->state->latest;
            }
        };
        $chat = new ChatApplicationService(
            repository: $chatRepository,
            users: $users,
            messageSequences: $messageSequences,
            blocks: new AllowAllBlockStatusResolver(),
            references: new AnonymousReferenceFactory(str_repeat('k', 32)),
            ids: $chatIds,
            clock: static fn (): string => '2026-10-11 00:00:00.000',
        );
        $conversation = $chat->getOrCreatePrivateConversation('100', [
            'identity_mode' => 1,
            'target' => new PublicChatTarget(['main_persona_id' => '201']),
        ]);

        $chatClient = new class ($chat) implements ChatMemberSnapshotClient {
            public function __construct(private readonly ChatApplicationService $chat)
            {
            }

            public function getConversationMemberSnapshot(string $trustedUserId, string $conversationId, ?OperationBudget $budget = null): array
            {
                return $this->chat->getConversationMemberSnapshot($trustedUserId, $conversationId);
            }
        };
        $messageStore = new InMemoryMessageStore();
        $messageIds = new class implements MessageIdGenerator {
            private int $next = 1200;

            public function nextId(): string
            {
                return (string)$this->next++;
            }
        };
        $messages = new MessageApplicationService(
            repository: $messageStore,
            transactions: $messageStore,
            chat: $chatClient,
            ids: $messageIds,
            clock: static fn (): string => '2026-10-11 00:00:00.000',
            projector: new AnonymousAuthorProjector(new AnonymousReferenceFactory(str_repeat('k', 32))),
        );

        $written = $messages->sendMessage('100', [
            'client_message_id' => 'integration-1',
            'conversation_id' => $conversation->getConversationId(),
            'type' => 1,
            'content' => '{"body":"persisted"}',
        ]);
        $sequenceState->latest = '1';
        $conversationId = (string)$conversation->getConversationId();
        $synced = $messages->syncMessages('100', $conversationId, '0', 20);

        self::assertSame('1', (string)$written->getSeq());
        self::assertCount(1, $synced['messages']);
        self::assertSame($written->getMessageId(), $synced['messages'][0]->getMessageId());
        self::assertSame('101', (string)$synced['messages'][0]->getAuthor()->getPersonaId());

        $realtime = new class implements RealtimeMessagePublisher {
            /** @var list<array{recipients:list<string>,message:array<string,mixed>}> */
            public array $deliveries = [];
            public bool $fail = true;

            public function publish(string $eventId, array $recipients, array $message): void
            {
                if ($this->fail) {
                    throw new \RuntimeException('realtime unavailable');
                }
                $this->deliveries[] = ['recipients' => $recipients, 'message' => $message];
            }
        };
        $handler = new MessageCreatedHandler($chatRepository, $chat, $realtime);
        $eventRow = $messageStore->outbox[0];
        $event = new EventEnvelope(
            eventId: (string)$eventRow['event_id'], eventType: EventTypes::MESSAGE_CREATED,
            eventVersion: 1, occurredAt: '2026-10-11T00:00:00Z', traceId: '',
            producer: 'message-service', key: $conversationId,
            payload: (array)$eventRow['payload'],
        );
        try {
            $handler->handle($event);
            self::fail('A failed realtime publish must leave the delivery retryable');
        } catch (\RuntimeException $exception) {
            self::assertSame('realtime unavailable', $exception->getMessage());
        }
        self::assertSame('1', (string)$chatRepository->findById($conversationId)['last_message_seq']);
        $realtime->fail = false;
        $handler->handle($event);
        $handler->handle($event);
        self::assertCount(2, $realtime->deliveries);
        self::assertSame(['100', '200'], $realtime->deliveries[0]['recipients']);
        self::assertArrayNotHasKey('sender_user_id', $realtime->deliveries[0]['message']);
        self::assertArrayNotHasKey('sender_persona_id', $realtime->deliveries[0]['message']);
        self::assertSame((string)$written->getMessageId(), (string)$chat->getConversation('100', $conversationId)->getLastMessageId());
        try {
            $chat->markRead('200', $conversationId, '999999');
            self::fail('A future read cursor must be rejected');
        } catch (\Services\ChatService\Domain\Exception\ChatException $exception) {
            self::assertStringContainsString('latest persisted message', $exception->getMessage());
        }
        self::assertSame('0', (string)$chatRepository->findMember($conversationId, '200')['last_read_seq']);
        self::assertTrue($chat->markRead('200', $conversationId, '1'));
        self::assertTrue($chat->markRead('200', $conversationId, '1'));
        self::assertSame(1, $sequenceState->calls, 'A cursor covered by the PostgreSQL summary skips MessageService');

        $second = $messages->sendMessage('100', [
            'client_message_id' => 'integration-2',
            'conversation_id' => $conversationId,
            'type' => 1,
            'content' => '{"body":"persisted before summary"}',
        ]);
        self::assertSame('2', (string)$second->getSeq());
        self::assertSame('1', $chatRepository->latestMessageSequence($conversationId));
        $sequenceState->latest = '2';
        self::assertTrue($chat->markRead('200', $conversationId, '2'));
        self::assertSame('2', (string)$chatRepository->findMember($conversationId, '200')['last_read_seq']);
        self::assertSame(2, $sequenceState->calls);

        $sequenceState->unavailable = true;
        $lookupCount = $sequenceState->calls;
        self::assertTrue($chat->markRead('200', $conversationId, '2'));
        self::assertTrue($chat->markRead('200', $conversationId, '1'));
        self::assertSame($lookupCount, $sequenceState->calls, 'Already confirmed reads skip an unavailable MessageService');
        self::assertSame('2', (string)$chatRepository->findMember($conversationId, '200')['last_read_seq']);
        try {
            $chat->markRead('200', $conversationId, '3');
            self::fail('A temporary sequence lookup failure must reject the read without writing');
        } catch (\RuntimeException $exception) {
            self::assertSame(\Contracts\Errors\ErrorCode::SERVICE_UNAVAILABLE->value, $exception->getCode());
        }
        self::assertSame('2', (string)$chatRepository->findMember($conversationId, '200')['last_read_seq']);
        $sequenceState->unavailable = false;
        try {
            $chat->markRead('200', $conversationId, '3');
            self::fail('A cursor beyond the MongoDB sequence must be rejected');
        } catch (\Services\ChatService\Domain\Exception\ChatException $exception) {
            self::assertStringContainsString('latest persisted message', $exception->getMessage());
        }
        self::assertSame('2', (string)$chatRepository->findMember($conversationId, '200')['last_read_seq']);

        try {
            $chat->markRead('300', $conversationId, '1');
            self::fail('A non-member must not update the read cursor');
        } catch (\Services\ChatService\Domain\Exception\ChatException $exception) {
            self::assertSame(5001002, $exception->getCode());
        }

        $state->unavailable = true;
        try {
            $handler->handle($event);
            self::fail('A temporary identity dependency failure must keep the event retryable');
        } catch (\Packages\GrpcCommon\GrpcCallException $exception) {
            self::assertSame(14, $exception->grpcStatus);
        }
        self::assertCount(2, $realtime->deliveries);
        $state->unavailable = false;
        $handler->handle($event);
        self::assertCount(3, $realtime->deliveries);

        $state->active = false;
        $handler->handle($event);
        self::assertCount(3, $realtime->deliveries, 'Deactivated member identities must not receive pushes');
        $state->active = true;

        $state->mode = 2;
        try {
            $messages->sendMessage('100', [
                'client_message_id' => 'integration-mode-switch',
                'conversation_id' => $conversationId,
                'type' => 1,
                'content' => '{"body":"must be denied"}',
            ]);
            self::fail('A changed persona mode must not authorize the fixed snapshot');
        } catch (MessageException $e) {
            self::assertStringContainsString('conversation identity is unavailable', $e->getMessage());
        }

        $state->mode = 1;
        $state->active = false;
        try {
            $messages->sendMessage('100', [
                'client_message_id' => 'integration-disabled',
                'conversation_id' => $conversationId,
                'type' => 1,
                'content' => '{"body":"must be denied"}',
            ]);
            self::fail('A disabled persona must not send messages');
        } catch (MessageException) {
            self::assertCount(3, $realtime->deliveries);
        }

        $leave = $chatPdo->prepare(
            'UPDATE conversation_members SET left_at = CURRENT_TIMESTAMP WHERE conversation_id = :conversation_id AND user_id = :user_id',
        );
        $leave->execute(['conversation_id' => $conversationId, 'user_id' => '200']);
        try {
            $chat->markRead('200', $conversationId, '2');
            self::fail('A member who left must not update the read cursor');
        } catch (\Services\ChatService\Domain\Exception\ChatException $exception) {
            self::assertSame(5001002, $exception->getCode());
        }
    }
}
