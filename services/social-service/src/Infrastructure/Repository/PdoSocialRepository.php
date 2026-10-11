<?php

declare(strict_types=1);

namespace Services\SocialService\Infrastructure\Repository;

use PDO;
use Services\SocialService\Domain\Repository\SocialRepositoryInterface;

final readonly class PdoSocialRepository implements SocialRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function transaction(callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $operation();
        }

        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            try {
                $this->pdo->rollBack();
            } catch (\Throwable) {
                // Preserve the original operation/commit failure when the
                // driver has already closed the transaction.
            }
            throw $e;
        }
    }

    public function insertLike(string $id, string $personaId, int $targetType, string $targetId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO `likes` (`id`, `persona_id`, `target_type`, `target_id`, `created_at`) '
            . 'VALUES (:id, :persona_id, :target_type, :target_id, :created_at) '
            . 'ON DUPLICATE KEY UPDATE `id` = `id`',
        );
        $statement->execute([
            'id' => $id,
            'persona_id' => $personaId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'created_at' => $this->now(),
        ]);
    }

    public function deleteLike(string $personaId, int $targetType, string $targetId): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM `likes` WHERE `persona_id` = :persona_id '
            . 'AND `target_type` = :target_type AND `target_id` = :target_id',
        );
        $statement->execute([
            'persona_id' => $personaId,
            'target_type' => $targetType,
            'target_id' => $targetId,
        ]);
    }

    public function hasLike(string $personaId, int $targetType, string $targetId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM `likes` WHERE `persona_id` = :persona_id '
            . 'AND `target_type` = :target_type AND `target_id` = :target_id LIMIT 1',
        );
        $statement->execute([
            'persona_id' => $personaId,
            'target_type' => $targetType,
            'target_id' => $targetId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function interactionStates(string $personaId, int $targetType, array $targetIds): array
    {
        $states = [];
        foreach ($targetIds as $targetId) {
            $states[(string)$targetId] = ['liked' => false, 'followed' => false];
        }
        if ($targetIds === []) {
            return $states;
        }

        $placeholders = implode(', ', array_fill(0, count($targetIds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT `target_id` FROM `likes` WHERE `persona_id` = ? '
            . 'AND `target_type` = ? AND `target_id` IN (' . $placeholders . ')',
        );
        $parameters = [$personaId, $targetType, ...array_values($targetIds)];
        $statement->execute($parameters);
        while (($targetId = $statement->fetchColumn()) !== false) {
            $key = (string)$targetId;
            if (isset($states[$key])) {
                $states[$key]['liked'] = true;
            }
        }

        return $states;
    }

    public function hasFollow(string $followerPersonaId, string $followeePersonaId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM `follows` WHERE `follower_persona_id` = :follower '
            . 'AND `followee_persona_id` = :followee LIMIT 1',
        );
        $statement->execute(['follower' => $followerPersonaId, 'followee' => $followeePersonaId]);

        return $statement->fetchColumn() !== false;
    }

    public function insertFollow(string $id, string $followerPersonaId, string $followeePersonaId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO `follows` (`id`, `follower_persona_id`, `followee_persona_id`, `created_at`) '
            . 'VALUES (:id, :follower, :followee, :created_at)',
        );
        $statement->execute([
            'id' => $id,
            'follower' => $followerPersonaId,
            'followee' => $followeePersonaId,
            'created_at' => $this->now(),
        ]);
    }

    public function deleteFollow(string $followerPersonaId, string $followeePersonaId): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM `follows` WHERE `follower_persona_id` = :follower '
            . 'AND `followee_persona_id` = :followee',
        );
        $statement->execute(['follower' => $followerPersonaId, 'followee' => $followeePersonaId]);
    }

    public function hasBlock(string $blockerUserId, string $blockedUserId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM `blocks` WHERE `blocker_user_id` = :blocker '
            . 'AND `blocked_user_id` = :blocked LIMIT 1',
        );
        $statement->execute(['blocker' => $blockerUserId, 'blocked' => $blockedUserId]);

        return $statement->fetchColumn() !== false;
    }

    public function insertBlock(string $id, string $blockerUserId, string $blockedUserId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO `blocks` (`id`, `blocker_user_id`, `blocked_user_id`, `created_at`) '
            . 'VALUES (:id, :blocker, :blocked, :created_at) '
            . 'ON DUPLICATE KEY UPDATE `id` = `id`',
        );
        $statement->execute([
            'id' => $id,
            'blocker' => $blockerUserId,
            'blocked' => $blockedUserId,
            'created_at' => $this->now(),
        ]);
    }

    public function deleteBlock(string $blockerUserId, string $blockedUserId): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM `blocks` WHERE `blocker_user_id` = :blocker '
            . 'AND `blocked_user_id` = :blocked',
        );
        $statement->execute(['blocker' => $blockerUserId, 'blocked' => $blockedUserId]);
    }

    public function hasBidirectionalBlock(string $userIdA, string $userIdB): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM `blocks` WHERE '
            . '(`blocker_user_id` = ? AND `blocked_user_id` = ?) '
            . 'OR (`blocker_user_id` = ? AND `blocked_user_id` = ?) LIMIT 1',
        );
        // MySQL native prepares reject reusing a named placeholder in the
        // same statement. Positional parameters keep both directions bound
        // explicitly and work with ATTR_EMULATE_PREPARES=false.
        $statement->execute([$userIdA, $userIdB, $userIdB, $userIdA]);

        return $statement->fetchColumn() !== false;
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s.v');
    }
}
