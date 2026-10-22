<?php

declare(strict_types=1);

namespace Tests\Unit;

use Contracts\Gen\Chat\V1\AnonymousChatTarget;
use Contracts\Gen\Chat\V1\PublicChatTarget;
use PDO;
use Packages\Identity\AnonymousReferenceFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Services\ChatService\Application\ChatApplicationService;
use Services\ChatService\Domain\IdGenerator;
use Services\ChatService\Domain\Identity\UserIdentityClient;
use Services\ChatService\Domain\MessageSequenceReader;
use Services\ChatService\Domain\Social\AllowAllBlockStatusResolver;
use Services\ChatService\Domain\Social\BlockStatusResolver;
use Services\ChatService\Domain\Target\ContentTargetResolver;
use Services\ChatService\Infrastructure\PdoChatRepository;
use Tests\TestCase;

final class ChatConversationSecurityTest extends TestCase
{
    public function testBodyIdentityCannotOverrideAccountAndAliasKeepsItsOwnSession(): void
    {
        $users = $this->users();
        $users->method('resolvePersonaMode')->willReturnCallback(
            static fn (string $userId, int $mode, int $operation): array => ['persona' => [
                'id' => $mode === 2 ? '102' : '101',
                'user_id' => $userId,
                'type' => $mode,
                'nickname' => $mode === 2 ? 'Alias' : 'Main',
                'avatar_key' => null,
                'status' => 1,
                'is_internal_alias' => $mode === 2,
            ]],
        );
        $users->method('resolveMainPersona')->willReturn(['persona' => $this->mainPeer()]);
        $app = $this->app($users);
        $target = new PublicChatTarget(['main_persona_id' => '201']);

        $first = $app->getOrCreatePrivateConversation('100', [
            'user_id' => '999',
            'persona_id' => '999',
            'identity_mode' => 1,
            'target' => $target,
        ]);
        $second = $app->getOrCreatePrivateConversation('100', [
            'user_id' => '999',
            'persona_id' => '999',
            'identity_mode' => 2,
            'alias_display_name' => 'Chosen conversation alias',
            'target' => $target,
        ]);

        self::assertNotSame($first->getConversationId(), $second->getConversationId());
        $members = iterator_to_array($first->getMembers());
        self::assertSame('101', (string)$members[0]->getAuthor()->getPersonaId());
        self::assertSame('main', $members[0]->getAuthor()->getIdentityMode() === 1 ? 'main' : 'alias');
        $aliasMembers = iterator_to_array($second->getMembers());
        self::assertSame(2, $aliasMembers[0]->getAuthor()->getIdentityMode());
        self::assertSame('Chosen conversation alias', $aliasMembers[0]->getAuthor()->getDisplayName());
    }

    public function testZeroAliasNameAndZeroReadCursorRemainValid(): void
    {
        $users = $this->users();
        $users->method('resolvePersonaMode')->willReturn(['persona' => [
            ...$this->ownMain(), 'id' => '102', 'type' => 2, 'is_internal_alias' => true,
        ]]);
        $users->method('resolveMainPersona')->willReturn(['persona' => $this->mainPeer()]);
        $app = $this->app($users);
        $request = [
            'identity_mode' => 2, 'alias_display_name' => '0',
            'target' => new PublicChatTarget(['main_persona_id' => '201']),
        ];
        $first = $app->getOrCreatePrivateConversation('100', $request);
        $retry = $app->getOrCreatePrivateConversation('100', $request);
        self::assertSame($first->getConversationId(), $retry->getConversationId());
        self::assertSame('0', iterator_to_array($first->getMembers())[0]->getAuthor()->getDisplayName());
        self::assertTrue($app->markRead('100', (string)$first->getConversationId(), '0'));
    }

    public function testWhitespaceAliasNameIsRejected(): void
    {
        $users = $this->users();
        $users->method('resolvePersonaMode')->willReturn(['persona' => [
            ...$this->ownMain(), 'id' => '102', 'type' => 2, 'is_internal_alias' => true,
        ]]);
        $this->expectExceptionCode(\Contracts\Errors\ErrorCode::INVALID_ARGUMENT->value);
        $this->app($users)->getOrCreatePrivateConversation('100', [
            'identity_mode' => 2, 'alias_display_name' => '   ',
            'target' => new PublicChatTarget(['main_persona_id' => '201']),
        ]);
    }

    public function testMainConversationIsUniqueInBothDirections(): void
    {
        $users = $this->users();
        $users->method('resolvePersonaMode')->willReturnCallback(
            static fn (string $userId, int $mode, int $operation): array => ['persona' => [
                'id' => $userId === '100' ? '101' : '201',
                'user_id' => $userId,
                'type' => 1,
                'nickname' => $userId === '100' ? 'A' : 'B',
                'avatar_key' => null,
                'status' => 1,
                'is_internal_alias' => false,
            ]],
        );
        $users->method('resolveMainPersona')->willReturnCallback(
            static fn (string $requesterUserId, string $personaId, int $operation): array => [
                'persona' => $requesterUserId === '100' ? [
                    'id' => '201',
                    'user_id' => '200',
                    'type' => 1,
                    'nickname' => 'B',
                    'avatar_key' => null,
                    'status' => 1,
                    'is_internal_alias' => false,
                ] : [
                    'id' => '101',
                    'user_id' => '100',
                    'type' => 1,
                    'nickname' => 'A',
                    'avatar_key' => null,
                    'status' => 1,
                    'is_internal_alias' => false,
                ],
            ],
        );
        $app = $this->app($users);

        $fromA = $app->getOrCreatePrivateConversation('100', [
            'identity_mode' => 1,
            'target' => new PublicChatTarget(['main_persona_id' => '201']),
        ]);
        $fromB = $app->getOrCreatePrivateConversation('200', [
            'identity_mode' => 1,
            'target' => new PublicChatTarget(['main_persona_id' => '101']),
        ]);

        self::assertSame($fromA->getConversationId(), $fromB->getConversationId());
    }

    public function testAliasSourceCannotBeReusedByPeerMainIdentity(): void
    {
        $users = $this->users();
        $users->method('resolvePersonaMode')->willReturnCallback(
            static fn (string $userId, int $mode, int $operation): array => ['persona' => [
                'id' => $userId === '100' ? ($mode === 2 ? '102' : '101') : '201',
                'user_id' => $userId,
                'type' => $userId === '100' && $mode === 2 ? 2 : 1,
                'nickname' => $mode === 2 ? 'A alias' : 'Main',
                'avatar_key' => null,
                'status' => 1,
                'is_internal_alias' => $userId === '100' && $mode === 2,
            ]],
        );
        $users->method('resolveMainPersona')->willReturnCallback(
            static fn (string $requesterUserId, string $personaId, int $operation): array => ['persona' => [
                'id' => $requesterUserId === '100' ? '201' : '101',
                'user_id' => $requesterUserId === '100' ? '200' : '100',
                'type' => 1,
                'nickname' => 'Peer main',
                'avatar_key' => null,
                'status' => 1,
                'is_internal_alias' => false,
            ]],
        );
        $app = $this->app($users);

        $aliasSource = $app->getOrCreatePrivateConversation('100', [
            'identity_mode' => 2,
            'target' => new PublicChatTarget(['main_persona_id' => '201']),
        ]);
        $peerMain = $app->getOrCreatePrivateConversation('200', [
            'identity_mode' => 1,
            'target' => new PublicChatTarget(['main_persona_id' => '101']),
        ]);

        self::assertNotSame($aliasSource->getConversationId(), $peerMain->getConversationId());
    }

    public function testAnonymousTargetMustResolveTheExactAccessibleReference(): void
    {
        $users = $this->users();
        $users->method('resolvePersonaMode')->willReturn(['persona' => $this->ownMain()]);
        $users->method('validatePersonaOwnership')->willReturn(['persona' => $this->aliasPeer()]);
        $targets = $this->createMock(ContentTargetResolver::class);
        $targets->method('resolveAnonymousTarget')->willReturn([
            'allowed' => true,
            'scope_type' => 'post',
            'scope_id' => 'other-post',
            'anonymous_ref' => 'anon_expected',
            'owner_user_id' => '200',
            'owner_persona_id' => '202',
            'display_name' => 'Frozen alias',
        ]);
        $app = $this->app($users, $targets);
        $target = new PublicChatTarget([
            'anonymous' => new AnonymousChatTarget([
                'scope_type' => 'post',
                'scope_id' => 'post-1',
                'anonymous_ref' => 'anon_expected',
            ]),
        ]);

        $this->expectExceptionMessage('anonymous target is unavailable');
        $app->getOrCreatePrivateConversation('100', ['identity_mode' => 1, 'target' => $target]);
    }

    public function testAnonymousTargetUsesContentSnapshotAndConversationScopedReference(): void
    {
        $users = $this->users();
        $users->method('resolvePersonaMode')->willReturn(['persona' => $this->ownMain()]);
        $users->method('validatePersonaOwnership')->willReturn(['persona' => $this->aliasPeer()]);
        $targets = $this->createMock(ContentTargetResolver::class);
        $targets->method('resolveAnonymousTarget')->willReturn([
            'allowed' => true,
            'scope_type' => 'post',
            'scope_id' => 'post-1',
            'anonymous_ref' => 'anon_expected',
            'owner_user_id' => '200',
            'owner_persona_id' => '202',
            'display_name' => 'Frozen alias',
            'avatar_key' => 'frozen:avatar',
        ]);
        $app = $this->app($users, $targets);
        $target = new PublicChatTarget([
            'anonymous' => new AnonymousChatTarget([
                'scope_type' => 'post',
                'scope_id' => 'post-1',
                'anonymous_ref' => 'anon_expected',
            ]),
        ]);

        $conversation = $app->getOrCreatePrivateConversation('100', [
            'identity_mode' => 1,
            'target' => $target,
        ]);
        $members = iterator_to_array($conversation->getMembers());
        $alias = $members[1]->getAuthor();

        self::assertSame('alias', $alias->getIdentityMode() === 2 ? 'alias' : 'main');
        self::assertSame('Frozen alias', $alias->getDisplayName());
        self::assertSame('system:anonymous', $alias->getAvatarKey());
        self::assertMatchesRegularExpression('/\Aanon_[A-Za-z0-9_-]+\z/', $alias->getAnonymousRef());
        self::assertSame('0', (string)$alias->getPersonaId());
        self::assertFalse($alias->getProfileAccessible());
    }

    public function testAnonymousTargetSessionIsBoundToSourceIdentity(): void
    {
        $users = $this->users();
        $users->method('resolvePersonaMode')->willReturnCallback(
            static fn (string $userId, int $mode, int $operation): array => ['persona' => [
                'id' => $mode === 2 ? '102' : '101',
                'user_id' => $userId,
                'type' => $mode,
                'nickname' => $mode === 2 ? 'Owner alias' : 'Owner main',
                'avatar_key' => null,
                'status' => 1,
                'is_internal_alias' => $mode === 2,
            ]],
        );
        $users->method('validatePersonaOwnership')->willReturn(['persona' => $this->aliasPeer()]);
        $targets = $this->createMock(ContentTargetResolver::class);
        $targets->method('resolveAnonymousTarget')->willReturn([
            'allowed' => true,
            'scope_type' => 'post',
            'scope_id' => 'post-1',
            'anonymous_ref' => 'anon_expected',
            'owner_user_id' => '200',
            'owner_persona_id' => '202',
            'display_name' => 'Frozen alias',
        ]);
        $app = $this->app($users, $targets);
        $target = new PublicChatTarget([
            'anonymous' => new AnonymousChatTarget([
                'scope_type' => 'post',
                'scope_id' => 'post-1',
                'anonymous_ref' => 'anon_expected',
            ]),
        ]);

        $mainSource = $app->getOrCreatePrivateConversation('100', [
            'identity_mode' => 1,
            'target' => $target,
        ]);
        $aliasSource = $app->getOrCreatePrivateConversation('100', [
            'identity_mode' => 2,
            'target' => $target,
        ]);

        self::assertNotSame($mainSource->getConversationId(), $aliasSource->getConversationId());
    }

    public function testBlockedTargetIsRejectedBeforePersistence(): void
    {
        $users = $this->users();
        $users->method('resolvePersonaMode')->willReturn(['persona' => $this->ownMain()]);
        $users->method('resolveMainPersona')->willReturn(['persona' => $this->mainPeer()]);
        $blocks = $this->createMock(BlockStatusResolver::class);
        $blocks->expects($this->once())->method('isBlockedEitherWay')->with('100', '200')->willReturn(true);
        $app = $this->app($users, null, $blocks);

        $this->expectExceptionMessage('chat cannot be initiated');
        $app->getOrCreatePrivateConversation('100', [
            'identity_mode' => 1,
            'target' => new PublicChatTarget(['main_persona_id' => '201']),
        ]);
    }

    /** @return MockObject&UserIdentityClient */
    private function users(): MockObject
    {
        return $this->createMock(UserIdentityClient::class);
    }

    /** @return array<string,mixed> */
    private function ownMain(): array
    {
        return [
            'id' => '101',
            'user_id' => '100',
            'type' => 1,
            'nickname' => 'Owner',
            'avatar_key' => null,
            'status' => 1,
            'is_internal_alias' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function mainPeer(): array
    {
        return [
            'id' => '201',
            'user_id' => '200',
            'type' => 1,
            'nickname' => 'Peer',
            'avatar_key' => null,
            'status' => 1,
            'is_internal_alias' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function aliasPeer(): array
    {
        return [
            'id' => '202',
            'user_id' => '200',
            'type' => 2,
            'nickname' => 'Current alias name',
            'avatar_key' => 'current:avatar',
            'status' => 1,
            'is_internal_alias' => true,
        ];
    }

    private function app(
        UserIdentityClient $users,
        ?ContentTargetResolver $targets = null,
        ?BlockStatusResolver $blocks = null,
    ): ChatApplicationService {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required; run PHP with -d extension=pdo_sqlite');
        }
        $pdo = new PDO('sqlite::memory:');
        $repository = new PdoChatRepository($pdo);
        $repository->ensureSchema();
        $ids = new class implements IdGenerator {
            private int $next = 1000;

            public function nextId(): string
            {
                return (string)$this->next++;
            }
        };

        return new ChatApplicationService(
            repository: $repository,
            users: $users,
            messageSequences: $this->createMock(MessageSequenceReader::class),
            targets: $targets,
            blocks: $blocks ?? new AllowAllBlockStatusResolver(),
            references: new AnonymousReferenceFactory(str_repeat('k', 32)),
            ids: $ids,
            clock: static fn (): string => '2026-10-11 00:00:00.000',
        );
    }
}
