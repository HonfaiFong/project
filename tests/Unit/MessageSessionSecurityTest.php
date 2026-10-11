<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use Packages\Identity\AnonymousAuthorProjector;
use Packages\Identity\AnonymousReferenceFactory;
use Services\MessageService\Application\MessageApplicationService;
use Services\MessageService\Domain\ChatMemberSnapshotClient;
use Services\MessageService\Domain\Exception\MessageException;
use Services\MessageService\Domain\IdGenerator;
use Services\MessageService\Infrastructure\PdoMessageRepository;
use Tests\TestCase;

final class MessageSessionSecurityTest extends TestCase
{
    public function testSendIgnoresBodyIdentityAndIsIdempotent(): void
    {
        $chat = $this->chatClient([
            'allowed' => true,
            'denial_reason' => '',
            'member' => [
                'user_id' => '100',
                'persona_id' => '101',
                'identity_mode' => 1,
                'display_name_snapshot' => 'Frozen main',
                'avatar_key_snapshot' => 'main:avatar',
                'scope_type' => '',
                'scope_id' => '',
                'anonymous_ref' => '',
            ],
        ]);
        [$app] = $this->app($chat);

        $first = $app->sendMessage('100', [
            'client_message_id' => 'client-1',
            'conversation_id' => '900',
            'type' => 1,
            'content' => '{"outer":{"z":1,"a":2},"items":[{"z":3,"a":4}]}',
            'sender_user_id' => '999',
            'sender_persona_id' => '999',
            'sender_identity_mode' => 2,
        ]);
        $second = $app->sendMessage('100', [
            'client_message_id' => 'client-1',
            'conversation_id' => '900',
            'type' => 1,
            // MySQL JSON columns may reorder object keys on read. The
            // idempotency key compares recursively canonicalized JSON values.
            'content' => '{"items":[{"a":4,"z":3}],"outer":{"a":2,"z":1}}',
            'sender_user_id' => '999',
            'sender_persona_id' => '999',
            'sender_identity_mode' => 2,
        ]);

        self::assertSame($first->getMessageId(), $second->getMessageId());
        self::assertSame(1, $first->getSeq());
        self::assertSame('101', (string)$first->getAuthor()->getPersonaId());
        self::assertSame('Frozen main', $first->getAuthor()->getDisplayName());
        self::assertTrue($first->getAuthor()->getProfileAccessible());
    }

    public function testJsonObjectAndArrayRemainDifferentForIdempotency(): void
    {
        $chat = $this->chatClient([
            'allowed' => true,
            'denial_reason' => '',
            'member' => [
                'user_id' => '100',
                'persona_id' => '101',
                'identity_mode' => 1,
                'display_name_snapshot' => 'Frozen main',
                'avatar_key_snapshot' => 'main:avatar',
                'scope_type' => '',
                'scope_id' => '',
                'anonymous_ref' => '',
            ],
        ]);
        [$app] = $this->app($chat);

        $app->sendMessage('100', [
            'client_message_id' => 'client-json-shape',
            'conversation_id' => '900',
            'type' => 1,
            'content' => '{"value":{}}',
        ]);

        $this->expectException(MessageException::class);
        $app->sendMessage('100', [
            'client_message_id' => 'client-json-shape',
            'conversation_id' => '900',
            'type' => 1,
            'content' => '{"value":[]}',
        ]);
    }

    public function testAliasMessageUsesConversationScopedSnapshotOnly(): void
    {
        $chat = $this->chatClient([
            'allowed' => true,
            'denial_reason' => '',
            'member' => [
                'user_id' => '100',
                'persona_id' => '202',
                'identity_mode' => 2,
                'display_name_snapshot' => 'Frozen alias',
                'avatar_key_snapshot' => 'system:anonymous',
                'scope_type' => 'conversation',
                'scope_id' => '900',
                'anonymous_ref' => 'anon_session_ref',
            ],
        ]);
        [$app, $repository] = $this->app($chat);

        $message = $app->sendMessage('100', [
            'client_message_id' => 'client-alias',
            'conversation_id' => '900',
            'type' => 1,
            'content' => '{"body":"alias body"}',
        ]);
        self::assertSame('0', (string)$message->getAuthor()->getPersonaId());
        self::assertSame('anon_session_ref', $message->getAuthor()->getAnonymousRef());
        self::assertFalse($message->getAuthor()->getProfileAccessible());

        // A corrupted scope is projected as an unlinked anonymous author.
        $repository->pdo()->exec("UPDATE messages SET sender_scope_id = 'other' WHERE message_id = 1000");
        $synced = $app->syncMessages('100', '900', '0', 10);
        self::assertSame('', $synced['messages'][0]->getAuthor()->getAnonymousRef());
        self::assertSame('0', (string)$synced['messages'][0]->getAuthor()->getPersonaId());
    }

    public function testMembershipOrBlockDenialStopsReadsAndWrites(): void
    {
        $chat = $this->chatClient([
            'allowed' => false,
            'denial_reason' => 'conversation is blocked',
        ]);
        [$app] = $this->app($chat);

        $this->expectExceptionMessage('conversation is blocked');
        $app->sendMessage('100', [
            'client_message_id' => 'blocked',
            'conversation_id' => '900',
            'type' => 1,
            'content' => '{"body":"blocked body"}',
        ]);
    }

    /** @param array<string,mixed> $snapshot */
    private function chatClient(array $snapshot): ChatMemberSnapshotClient
    {
        $chat = $this->createMock(ChatMemberSnapshotClient::class);
        $chat->method('getConversationMemberSnapshot')->willReturn($snapshot);

        return $chat;
    }

    /** @return array{0:MessageApplicationService,1:PdoMessageRepository} */
    private function app(ChatMemberSnapshotClient $chat): array
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required; run PHP with -d extension=pdo_sqlite');
        }
        $pdo = new PDO('sqlite::memory:');
        $repository = new PdoMessageRepository($pdo);
        $repository->ensureSchema();
        $ids = new class implements IdGenerator {
            private int $next = 1000;

            public function nextId(): string
            {
                return (string)$this->next++;
            }
        };
        $projector = new AnonymousAuthorProjector(new AnonymousReferenceFactory(str_repeat('k', 32)));

        return [
            new MessageApplicationService(
                repository: $repository,
                chat: $chat,
                ids: $ids,
                clock: static fn (): string => '2026-10-11 00:00:00.000',
                projector: $projector,
            ),
            $repository,
        ];
    }
}
