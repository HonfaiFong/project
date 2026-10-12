<?php

declare(strict_types=1);

namespace Services\SocialService\Domain\Repository;

interface SocialRepositoryInterface
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transaction(callable $operation): mixed;

    /**
     * Insert a like row. Returns true only when this call created the row;
     * when (persona_id, target_type, target_id) already exists the unique
     * key rejects the write and this returns false.
     */
    public function insertLike(string $id, string $personaId, int $targetType, string $targetId): bool;

    public function deleteLike(string $personaId, int $targetType, string $targetId): void;

    public function hasLike(string $personaId, int $targetType, string $targetId): bool;

    /** @return array<string, array{liked: bool, followed: bool}> */
    public function interactionStates(string $personaId, int $targetType, array $targetIds): array;

    public function hasFollow(string $followerPersonaId, string $followeePersonaId): bool;

    public function insertFollow(string $id, string $followerPersonaId, string $followeePersonaId): void;

    public function deleteFollow(string $followerPersonaId, string $followeePersonaId): void;

    public function hasBlock(string $blockerUserId, string $blockedUserId): bool;

    public function insertBlock(string $id, string $blockerUserId, string $blockedUserId): void;

    public function deleteBlock(string $blockerUserId, string $blockedUserId): void;

    /** True when either account has blocked the other. */
    public function hasBidirectionalBlock(string $userIdA, string $userIdB): bool;
}
