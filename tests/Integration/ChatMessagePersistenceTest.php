<?php

declare(strict_types=1);

namespace Tests\Integration;

use Contracts\Gen\Chat\V1\PublicChatTarget;
use PDO;
use Packages\Identity\AnonymousAuthorProjector;
use Packages\Identity\AnonymousReferenceFactory;
use Services\ChatService\Application\ChatApplicationService;
use Services\ChatService\Domain\IdGenerator as ChatIdGenerator;
use Services\ChatService\Domain\Identity\UserIdentityClient;
use Services\ChatService\Domain\Social\AllowAllBlockStatusResolver;
use Services\ChatService\Infrastructure\PdoChatRepository;
use Services\MessageService\Application\MessageApplicationService;
use Services\MessageService\Domain\ChatMemberSnapshotClient;
use Services\MessageService\Domain\Exception\MessageException;
use Services\MessageService\Domain\IdGenerator as MessageIdGenerator;
use Services\MessageService\Infrastructure\PdoMessageRepository;
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
            public int $mode = 1;
        };
        $users->method('validatePersonaOwnership')->willReturnCallback(
            static function (string $userId, string $personaId, int $operation) use ($state): array {
                if (!$state->active) {
                    throw new \RuntimeException('persona was deactivated');
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
        $chat = new ChatApplicationService(
            repository: $chatRepository,
            users: $users,
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

            public function getConversationMemberSnapshot(string $trustedUserId, string $conversationId): array
            {
                return $this->chat->getConversationMemberSnapshot($trustedUserId, $conversationId);
            }
        };
        $messagePdo = new PDO('sqlite::memory:');
        $messageRepository = new PdoMessageRepository($messagePdo);
        $messageRepository->ensureSchema();
        $messageIds = new class implements MessageIdGenerator {
            private int $next = 1200;

            public function nextId(): string
            {
                return (string)$this->next++;
            }
        };
        $messages = new MessageApplicationService(
            repository: $messageRepository,
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
        $conversationId = (string)$conversation->getConversationId();
        $synced = $messages->syncMessages('100', $conversationId, '0', 20);

        self::assertSame('1', (string)$written->getSeq());
        self::assertCount(1, $synced['messages']);
        self::assertSame($written->getMessageId(), $synced['messages'][0]->getMessageId());
        self::assertSame('101', (string)$synced['messages'][0]->getAuthor()->getPersonaId());

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
        $this->expectException(MessageException::class);
        $messages->sendMessage('100', [
            'client_message_id' => 'integration-disabled',
            'conversation_id' => $conversationId,
            'type' => 1,
            'content' => '{"body":"must be denied"}',
        ]);
    }
}
