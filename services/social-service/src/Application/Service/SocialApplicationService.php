<?php

declare(strict_types=1);

namespace Services\SocialService\Application\Service;

use Contracts\Errors\ErrorCode;
use Contracts\Events\EventTypes;
use Packages\Identity\PersonaOperation;
use Packages\IdGenerator\Snowflake;
use Packages\Outbox\EventOutboxInterface;
use Packages\Tracing\TraceContext;
use RuntimeException;
use Services\SocialService\Domain\Entity\MainPersona;
use Services\SocialService\Domain\Repository\SocialRepositoryInterface;
use Services\SocialService\Domain\Service\PersonaDirectoryInterface;
use Services\SocialService\Domain\Service\ResourceAccessInterface;

final readonly class SocialApplicationService
{
    private const MIN_TARGET_TYPE = 1;
    private const MAX_TARGET_TYPE = 2;
    private const MAX_BATCH_SIZE = 100;

    public function __construct(
        private SocialRepositoryInterface $repository,
        private PersonaDirectoryInterface $personas,
        private ResourceAccessInterface $resources,
        private ?Snowflake $snowflake = null,
        private ?EventOutboxInterface $outbox = null,
    ) {
    }

    public function like(
        string $actorUserId,
        string $personaId,
        int $targetType,
        string $targetId,
    ): bool {
        $actor = $this->mainActor($actorUserId, $personaId, PersonaOperation::LIKE->value);
        $this->target($actorUserId, $targetType, $targetId);

        $this->repository->transaction(function () use ($actor, $targetType, $targetId): void {
            // A repeated like is idempotent. insertLike reports whether this
            // call actually created the row: the unique key rejects a
            // replayed or racing like, which must not emit a second event.
            if (!$this->repository->insertLike($this->nextId(), $actor->id, $targetType, $targetId)) {
                return;
            }

            $this->recordEvent(
                EventTypes::LIKE_CREATED,
                aggregateType: 'like',
                aggregateId: $targetId,
                eventKey: $actor->userId,
                payload: [
                    'persona_id' => $actor->id,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'user_id' => $actor->userId,
                ],
            );
        });

        return true;
    }

    public function unlike(
        string $actorUserId,
        string $personaId,
        int $targetType,
        string $targetId,
    ): bool {
        $actor = $this->mainActor($actorUserId, $personaId, PersonaOperation::UNLIKE->value);
        $this->target($actorUserId, $targetType, $targetId);
        $this->repository->deleteLike($actor->id, $targetType, $targetId);

        return true;
    }

    public function follow(
        string $actorUserId,
        string $followerPersonaId,
        string $followeePersonaId,
    ): bool {
        $follower = $this->mainActor($actorUserId, $followerPersonaId, PersonaOperation::FOLLOW->value);
        $followee = $this->persona($followeePersonaId);

        if ($follower->userId === $followee->userId) {
            throw $this->error(ErrorCode::SOCIAL_CANNOT_FOLLOW_SELF);
        }
        if ($this->repository->hasBidirectionalBlock($follower->userId, $followee->userId)) {
            throw $this->error(ErrorCode::SOCIAL_USER_BLOCKED);
        }
        if ($this->repository->hasFollow($follower->id, $followee->id)) {
            throw $this->error(ErrorCode::SOCIAL_ALREADY_FOLLOWED);
        }

        $this->repository->transaction(function () use ($follower, $followee): void {
            $this->repository->insertFollow($this->nextId(), $follower->id, $followee->id);
            $this->recordEvent(
                EventTypes::FOLLOW_CREATED,
                aggregateType: 'follow',
                aggregateId: $followee->id,
                eventKey: $follower->userId,
                payload: [
                    'follower_persona_id' => $follower->id,
                    'followee_persona_id' => $followee->id,
                    'follower_user_id' => $follower->userId,
                    'followee_user_id' => $followee->userId,
                ],
            );
        });

        return true;
    }

    public function unfollow(
        string $actorUserId,
        string $followerPersonaId,
        string $followeePersonaId,
    ): bool {
        $follower = $this->mainActor($actorUserId, $followerPersonaId, PersonaOperation::UNFOLLOW->value);
        $followee = $this->persona($followeePersonaId);
        if ($follower->userId === $followee->userId) {
            throw $this->error(ErrorCode::SOCIAL_CANNOT_FOLLOW_SELF);
        }

        $this->repository->deleteFollow($follower->id, $followee->id);

        return true;
    }

    public function block(string $actorUserId, string $blockedUserId): bool
    {
        $actorUserId = $this->id($actorUserId, 'actor_user_id');
        $blockedUserId = $this->id($blockedUserId, 'blocked_user_id');
        $this->personas->assertActiveMainUser($actorUserId, PersonaOperation::BLOCK->value);
        $this->personas->assertActiveUser($blockedUserId);
        if ($actorUserId === $blockedUserId) {
            throw $this->error(ErrorCode::SOCIAL_CANNOT_BLOCK_SELF);
        }

        $this->repository->insertBlock($this->nextId(), $actorUserId, $blockedUserId);

        return true;
    }

    public function unblock(string $actorUserId, string $blockedUserId): bool
    {
        $actorUserId = $this->id($actorUserId, 'actor_user_id');
        $blockedUserId = $this->id($blockedUserId, 'blocked_user_id');
        $this->personas->assertActiveMainUser($actorUserId, PersonaOperation::UNBLOCK->value);
        if ($actorUserId === $blockedUserId) {
            throw $this->error(ErrorCode::SOCIAL_CANNOT_BLOCK_SELF);
        }

        // Only remove the caller's directed edge. If the other account also
        // blocks the caller, the bidirectional query must remain true.
        $this->repository->deleteBlock($actorUserId, $blockedUserId);

        return true;
    }

    /** @return array<string, array{liked: bool, followed: bool}> */
    public function batchInteractionStates(
        string $actorUserId,
        string $personaId,
        int $targetType,
        array $targetIds,
    ): array {
        $actor = $this->mainActor($actorUserId, $personaId, PersonaOperation::LIKE->value);
        $this->assertTargetType($targetType);
        if (count($targetIds) > self::MAX_BATCH_SIZE) {
            throw $this->error(ErrorCode::INVALID_ARGUMENT, 'too many target_ids');
        }

        $normalized = [];
        foreach ($targetIds as $targetId) {
            $normalized[] = $this->id($targetId, 'target_ids');
            $this->target($actorUserId, $targetType, (string)$targetId);
        }

        return $this->repository->interactionStates($actor->id, $targetType, array_values(array_unique($normalized)));
    }

    public function checkBidirectionalBlock(string $userIdA, string $userIdB): bool
    {
        $userIdA = $this->id($userIdA, 'user_id_a');
        $userIdB = $this->id($userIdB, 'user_id_b');

        return $userIdA !== $userIdB
            && $this->repository->hasBidirectionalBlock($userIdA, $userIdB);
    }

    /** @param list<string> $targets @return list<string> */
    public function blockedUserIds(string $actor, array $targets): array
    {
        $actor = $this->id($actor, 'user_id_a');
        if (count($targets) > self::MAX_BATCH_SIZE) {
            throw $this->error(ErrorCode::INVALID_ARGUMENT, 'Too many block targets');
        }
        $targets = array_values(array_unique(array_map(fn (string $id): string => $this->id($id, 'target_user_id'), $targets)));
        return $this->repository->blockedUserIds($actor, array_values(array_filter($targets, static fn (string $id): bool => $id !== $actor)));
    }

    private function mainActor(string $userId, string $personaId, int $operation): MainPersona
    {
        $userId = $this->id($userId, 'actor_user_id');
        $personaId = $this->id($personaId, 'persona_id');

        return $this->personas->assertOwnedMainPersona($userId, $personaId, $operation);
    }

    private function persona(string $personaId): MainPersona
    {
        return $this->personas->findActiveMainPersona($this->id($personaId, 'followee_persona_id'));
    }

    private function target(string $actorUserId, int $targetType, string $targetId): void
    {
        $this->assertTargetType($targetType);
        $targetId = $this->id($targetId, 'target_id');
        $owners = $this->resources->assertAccessible($actorUserId, $targetType, $targetId);
        foreach (array_unique([
            $owners['resource_author_user_id'],
            $owners['root_author_user_id'],
        ]) as $ownerUserId) {
            if ($this->repository->hasBidirectionalBlock($actorUserId, $ownerUserId)) {
                throw $this->error(ErrorCode::SOCIAL_USER_BLOCKED);
            }
        }
    }

    private function assertTargetType(int $targetType): void
    {
        if ($targetType < self::MIN_TARGET_TYPE || $targetType > self::MAX_TARGET_TYPE) {
            throw $this->error(ErrorCode::INVALID_ARGUMENT, 'target_type is invalid');
        }
    }

    private function id(int|string $value, string $field): string
    {
        $value = (string)$value;
        if (empty($value) || strspn($value, '0123456789') !== strlen($value)) {
            throw $this->error(ErrorCode::INVALID_ARGUMENT, $field . ' is required');
        }

        return $value;
    }

    private function nextId(): string
    {
        return (string)($this->snowflake?->nextId() ?? random_int(1, PHP_INT_MAX));
    }

    /** @param array<string, mixed> $payload */
    private function recordEvent(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        string $eventKey,
        array $payload,
    ): void {
        if ($this->outbox === null) {
            return;
        }

        $this->outbox->recordEvent(
            eventId: $this->nextId(),
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            eventType: $eventType,
            eventVersion: 1,
            payload: $payload,
            eventKey: $eventKey,
            traceId: TraceContext::current()->traceId,
        );
    }

    private function error(ErrorCode $code, ?string $message = null): RuntimeException
    {
        return new RuntimeException($message ?? $code->message(), $code->value);
    }
}
