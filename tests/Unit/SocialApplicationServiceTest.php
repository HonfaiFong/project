<?php

declare(strict_types=1);

namespace Tests\Unit;

use Contracts\Errors\ErrorCode;
use Packages\Identity\PersonaOperation;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Services\SocialService\Application\Service\SocialApplicationService;
use Services\SocialService\Domain\Entity\MainPersona;
use Services\SocialService\Domain\Repository\SocialRepositoryInterface;
use Services\SocialService\Domain\Service\PersonaDirectoryInterface;
use Services\SocialService\Domain\Service\ResourceAccessInterface;

final class SocialApplicationServiceTest extends TestCase
{
    public function testLikeAndUnlikeUseAuthenticatedMainAndResourceAuthorization(): void
    {
        $personas = new FakePersonaDirectory();
        $repository = new FakeSocialRepository();
        $resources = new FakeResourceAccess();
        $service = $this->service($repository, $personas, $resources);

        $this->assertTrue($service->like('10', '100', 1, '900'));
        $this->assertTrue($service->like('10', '100', 1, '900'));
        $this->assertTrue($service->unlike('10', '100', 1, '900'));

        $this->assertSame([], $repository->likes);
        $this->assertSame([['10', 1, '900'], ['10', 1, '900'], ['10', 1, '900']], $resources->calls);
        $this->assertSame(
            [PersonaOperation::LIKE->value, PersonaOperation::LIKE->value, PersonaOperation::UNLIKE->value],
            $personas->mainOperations,
        );
    }

    public function testAliasOrForeignPersonaIsRejectedBeforeResourceOrRepositoryAccess(): void
    {
        $personas = new FakePersonaDirectory();
        $personas->rejectOwnedPersona = true;
        $repository = new FakeSocialRepository();
        $resources = new FakeResourceAccess();
        $service = $this->service($repository, $personas, $resources);

        $this->expectExceptionCode(ErrorCode::PERSONA_FORBIDDEN->value);
        try {
            $service->like('10', '101', 1, '900');
        } finally {
            $this->assertSame([], $repository->likes);
            $this->assertSame([], $resources->calls);
        }
    }

    public function testLikeRejectsAResourceOwnerBlockedInEitherDirection(): void
    {
        $personas = new FakePersonaDirectory();
        $repository = new FakeSocialRepository();
        $repository->blocks['99|10'] = true;
        $resources = new FakeResourceAccess();
        $service = $this->service($repository, $personas, $resources);

        $this->expectExceptionCode(ErrorCode::SOCIAL_USER_BLOCKED->value);
        $service->like('10', '100', 1, '900');
    }

    public function testFollowRejectsEitherDirectionOfBlockAndSupportsReverseOperation(): void
    {
        $personas = new FakePersonaDirectory();
        $personas->targets['200'] = new MainPersona('200', '20');
        $repository = new FakeSocialRepository();
        $repository->blocks['20|10'] = true;
        $resources = new FakeResourceAccess();
        $service = $this->service($repository, $personas, $resources);

        $this->expectExceptionCode(ErrorCode::SOCIAL_USER_BLOCKED->value);
        try {
            $service->follow('10', '100', '200');
        } catch (RuntimeException $exception) {
            $this->assertSame(ErrorCode::SOCIAL_USER_BLOCKED->value, $exception->getCode());
            $repository->blocks = [];
            $this->assertTrue($service->follow('10', '100', '200'));
            $this->assertTrue($service->unfollow('10', '100', '200'));
            $this->assertSame([], $repository->follows);
            throw $exception;
        }
    }

    public function testBlockAndUnblockResolveTheAuthenticatedMainOperationAndPreserveReverseEdge(): void
    {
        $personas = new FakePersonaDirectory();
        $personas->activeUsers = ['10' => true, '20' => true];
        $repository = new FakeSocialRepository();
        $resources = new FakeResourceAccess();
        $service = $this->service($repository, $personas, $resources);

        $this->assertTrue($service->block('10', '20'));
        $this->assertTrue($service->block('20', '10'));
        $this->assertTrue($service->checkBidirectionalBlock('10', '20'));
        $this->assertTrue($service->unblock('10', '20'));
        $this->assertTrue($service->checkBidirectionalBlock('10', '20'));
        $this->assertTrue($service->unblock('20', '10'));
        $this->assertFalse($service->checkBidirectionalBlock('10', '20'));

        $this->assertSame(
            [PersonaOperation::BLOCK->value, PersonaOperation::BLOCK->value,
                PersonaOperation::UNBLOCK->value, PersonaOperation::UNBLOCK->value],
            $personas->mainOperations,
        );
    }

    public function testBatchInteractionStateUsesTheSameMainAuthorizationForEveryTarget(): void
    {
        $personas = new FakePersonaDirectory();
        $repository = new FakeSocialRepository();
        $resources = new FakeResourceAccess();
        $repository->likes['100|1|900'] = true;
        $service = $this->service($repository, $personas, $resources);

        $states = $service->batchInteractionStates('10', '100', 1, ['900', '901', '900']);

        $this->assertSame(['900' => ['liked' => true, 'followed' => false], '901' => ['liked' => false, 'followed' => false]], $states);
        $this->assertSame([PersonaOperation::LIKE->value], $personas->mainOperations);
        $this->assertCount(3, $resources->calls);
    }

    private function service(
        FakeSocialRepository $repository,
        FakePersonaDirectory $personas,
        FakeResourceAccess $resources,
    ): SocialApplicationService {
        return new SocialApplicationService($repository, $personas, $resources);
    }
}

final class FakePersonaDirectory implements PersonaDirectoryInterface
{
    /** @var array<int|string, MainPersona> */
    public array $targets = [];
    /** @var array<int|string, bool> */
    public array $activeUsers = [];
    /** @var list<int> */
    public array $mainOperations = [];
    public bool $rejectOwnedPersona = false;

    public function assertOwnedMainPersona(string $userId, string $personaId, int $operation): MainPersona
    {
        $this->mainOperations[] = $operation;
        if ($this->rejectOwnedPersona || $personaId === '') {
            throw new RuntimeException(ErrorCode::PERSONA_FORBIDDEN->message(), ErrorCode::PERSONA_FORBIDDEN->value);
        }

        return new MainPersona($personaId, $userId);
    }

    public function findActiveMainPersona(string $personaId): MainPersona
    {
        return $this->targets[$personaId] ?? new MainPersona($personaId, '20');
    }

    public function assertActiveUser(string $userId): void
    {
        if (isset($this->activeUsers[$userId]) && !$this->activeUsers[$userId]) {
            throw new RuntimeException(ErrorCode::USER_BANNED->message(), ErrorCode::USER_BANNED->value);
        }
    }

    public function assertActiveMainUser(string $userId, int $operation): MainPersona
    {
        $this->mainOperations[] = $operation;
        $this->assertActiveUser($userId);

        return new MainPersona($userId . '-main', $userId);
    }
}

final class FakeResourceAccess implements ResourceAccessInterface
{
    /** @var list<array{string,int,string}> */
    public array $calls = [];

    /** @return array{resource_author_user_id:string,root_author_user_id:string} */
    public function assertAccessible(string $actorUserId, int $targetType, string $targetId): array
    {
        $this->calls[] = [$actorUserId, $targetType, $targetId];

        return [
            'resource_author_user_id' => '99',
            'root_author_user_id' => '99',
        ];
    }
}

final class FakeSocialRepository implements SocialRepositoryInterface
{
    /** @var array<string, bool> */
    public array $likes = [];
    /** @var array<string, bool> */
    public array $follows = [];
    /** @var array<string, bool> */
    public array $blocks = [];

    public function transaction(callable $operation): mixed
    {
        return $operation();
    }

    public function insertLike(string $id, string $personaId, int $targetType, string $targetId): void
    {
        $this->likes[$personaId . '|' . $targetType . '|' . $targetId] = true;
    }

    public function deleteLike(string $personaId, int $targetType, string $targetId): void
    {
        unset($this->likes[$personaId . '|' . $targetType . '|' . $targetId]);
    }

    public function hasLike(string $personaId, int $targetType, string $targetId): bool
    {
        return $this->likes[$personaId . '|' . $targetType . '|' . $targetId] ?? false;
    }

    public function interactionStates(string $personaId, int $targetType, array $targetIds): array
    {
        $states = [];
        foreach ($targetIds as $targetId) {
            $key = (string)$targetId;
            $states[$key] = [
                'liked' => $this->hasLike($personaId, $targetType, $key),
                'followed' => false,
            ];
        }

        return $states;
    }

    public function hasFollow(string $followerPersonaId, string $followeePersonaId): bool
    {
        return $this->follows[$followerPersonaId . '|' . $followeePersonaId] ?? false;
    }

    public function insertFollow(string $id, string $followerPersonaId, string $followeePersonaId): void
    {
        $this->follows[$followerPersonaId . '|' . $followeePersonaId] = true;
    }

    public function deleteFollow(string $followerPersonaId, string $followeePersonaId): void
    {
        unset($this->follows[$followerPersonaId . '|' . $followeePersonaId]);
    }

    public function hasBlock(string $blockerUserId, string $blockedUserId): bool
    {
        return $this->blocks[$blockerUserId . '|' . $blockedUserId] ?? false;
    }

    public function insertBlock(string $id, string $blockerUserId, string $blockedUserId): void
    {
        $this->blocks[$blockerUserId . '|' . $blockedUserId] = true;
    }

    public function deleteBlock(string $blockerUserId, string $blockedUserId): void
    {
        unset($this->blocks[$blockerUserId . '|' . $blockedUserId]);
    }

    public function hasBidirectionalBlock(string $userIdA, string $userIdB): bool
    {
        return $this->hasBlock($userIdA, $userIdB) || $this->hasBlock($userIdB, $userIdA);
    }
}
