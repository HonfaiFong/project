<?php

declare(strict_types=1);

namespace Services\UserService\Infrastructure\Migration;

use InvalidArgumentException;
use Packages\Phone\PhoneNormalizer;
use PDO;
use RuntimeException;
use Throwable;

/**
 * 规划用户手机号规范化，并在明确请求时执行。
 *
 * 方案保存在连接作用域的临时表中，限制 PHP 内存使用，同时允许在修改任何用户行之前检查完整数据集。
 */
final class UserPhoneMigration
{
    private const PLAN_TABLE = 'user_phone_migration_plan';
    private const COLLISION_TABLE = 'user_phone_migration_collisions';
    private const REPORT_SAMPLE_LIMIT = 20;

    public function __construct(
        private readonly PhoneNormalizer $phoneNormalizer,
        private readonly int $batchSize = 500,
    ) {
        if ($this->batchSize < 1) {
            throw new InvalidArgumentException('Phone migration batch size must be positive');
        }
    }

    /**
     * @return array{
     *     total_count: int,
     *     change_count: int,
     *     invalid_count: int,
     *     collision_count: int,
     *     change_user_ids: list<string>,
     *     invalid_user_ids: list<string>,
     *     collision_user_ids: list<string>,
     * }
     */
    public function preflight(PDO $pdo, bool $lockRows = false): array
    {
        $this->createPlanTable($pdo);

        try {
            $this->scanIntoPlan($pdo, $lockRows);

            return $this->summarizePlan($pdo);
        } finally {
            $this->dropPlanTable($pdo);
        }
    }

    /**
     * @return array{updated: int}
     * @throws Throwable 扫描或更新失败时抛出；自行开启的事务尝试回滚，调用方开启的事务仍由调用方负责
     */
    public function apply(PDO $pdo): array
    {
        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $this->createPlanTable($pdo);
            $this->scanIntoPlan($pdo, true);
            $report = $this->summarizePlan($pdo);

            if ($report['invalid_count'] > 0) {
                throw new RuntimeException($this->invalidMessage($report['invalid_user_ids'], $report['invalid_count']));
            }
            if ($report['collision_count'] > 0) {
                throw new RuntimeException(
                    $this->collisionMessage($report['collision_user_ids'], $report['collision_count'])
                );
            }

            $updated = $this->updateFromPlan($pdo);
            $this->dropPlanTable($pdo);

            if ($startedTransaction) {
                $pdo->commit();
            }

            return ['updated' => $updated];
        } catch (Throwable $e) {
            $this->dropPlanTable($pdo);
            if ($startedTransaction) {
                try {
                    $pdo->rollBack();
                } catch (Throwable) {
                    // 提交失败可能已关闭事务。
                }
            }
            throw $e;
        }
    }

    private function createPlanTable(PDO $pdo): void
    {
        $this->dropPlanTable($pdo);
        $pdo->exec('CREATE TEMPORARY TABLE ' . self::PLAN_TABLE . ' (
                user_id VARCHAR(64) NOT NULL PRIMARY KEY,
                original_phone VARCHAR(64) NOT NULL,
                normalized_phone VARCHAR(64) NULL,
                error_code VARCHAR(32) NULL
            )'
        );
        $pdo->exec('CREATE INDEX idx_normalized_phone ON ' . self::PLAN_TABLE . ' (normalized_phone)');
    }

    private function dropPlanTable(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS ' . self::PLAN_TABLE);
        $pdo->exec('DROP TABLE IF EXISTS ' . self::COLLISION_TABLE);
    }

    private function createCollisionTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TEMPORARY TABLE ' . self::COLLISION_TABLE . ' (
                normalized_phone VARCHAR(64) NOT NULL PRIMARY KEY
            )'
        );
        $pdo->exec(
            'INSERT INTO ' . self::COLLISION_TABLE . ' (normalized_phone)
             SELECT normalized_phone
             FROM ' . self::PLAN_TABLE . '
             WHERE error_code IS NULL AND normalized_phone IS NOT NULL
             GROUP BY normalized_phone
             HAVING COUNT(*) > 1'
        );
    }

    private function scanIntoPlan(PDO $pdo, bool $lockRows): void
    {
        $lastId = '0';
        $insert = $pdo->prepare(
            'INSERT INTO ' . self::PLAN_TABLE . '
             (user_id, original_phone, normalized_phone, error_code)
             VALUES (:user_id, :original_phone, :normalized_phone, :error_code)'
        );

        while (true) {
            $sql = 'SELECT id, phone
                    FROM users
                    WHERE phone IS NOT NULL AND id > :last_id
                    ORDER BY id
                    LIMIT ' . $this->batchSize;
            if ($lockRows && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
                $sql .= ' FOR UPDATE';
            }

            $select = $pdo->prepare($sql);
            $select->execute(['last_id' => $lastId]);
            $rowsRead = 0;
            while ($row = $select->fetch(PDO::FETCH_ASSOC)) {
                $userId = (string)$row['id'];
                $phone = (string)$row['phone'];
                $lastId = $userId;
                $rowsRead++;

                try {
                    $normalized = $this->phoneNormalizer->normalize($phone);
                    $errorCode = null;
                } catch (RuntimeException) {
                    $normalized = null;
                    $errorCode = 'invalid_phone';
                }

                $insert->execute([
                    'user_id'          => $userId,
                    'original_phone'   => $phone,
                    'normalized_phone' => $normalized,
                    'error_code'       => $errorCode,
                ]);
            }
            $select->closeCursor();

            if ($rowsRead < $this->batchSize) {
                return;
            }
        }
    }

    /**
     * @return array{
     *     total_count: int,
     *     change_count: int,
     *     invalid_count: int,
     *     collision_count: int,
     *     change_user_ids: list<string>,
     *     invalid_user_ids: list<string>,
     *     collision_user_ids: list<string>,
     * }
     */
    private function summarizePlan(PDO $pdo): array
    {
        $totalCount = $this->count($pdo, 'SELECT COUNT(*) FROM ' . self::PLAN_TABLE);
        $changeCount = $this->count(
            $pdo,
            'SELECT COUNT(*) FROM ' . self::PLAN_TABLE . '
             WHERE error_code IS NULL AND original_phone <> normalized_phone'
        );
        $invalidCount = $this->count(
            $pdo,
            'SELECT COUNT(*) FROM ' . self::PLAN_TABLE . " WHERE error_code = 'invalid_phone'"
        );
        $this->createCollisionTable($pdo);
        $collisionCount = $this->count($pdo, 'SELECT COUNT(*) FROM ' . self::COLLISION_TABLE);

        try {
            return [
                'total_count'        => $totalCount,
                'change_count'       => $changeCount,
                'invalid_count'      => $invalidCount,
                'collision_count'    => $collisionCount,
                'change_user_ids'    => $this->sampleIds(
                    $pdo,
                    'SELECT user_id FROM ' . self::PLAN_TABLE . '
                     WHERE error_code IS NULL AND original_phone <> normalized_phone
                     ORDER BY user_id'
                ),
                'invalid_user_ids'   => $this->sampleIds(
                    $pdo,
                    "SELECT user_id FROM " . self::PLAN_TABLE . " WHERE error_code = 'invalid_phone' ORDER BY user_id"
                ),
                'collision_user_ids' => $this->sampleIds(
                    $pdo,
                    'SELECT plan.user_id
                     FROM ' . self::PLAN_TABLE . ' plan
                     INNER JOIN ' . self::COLLISION_TABLE . ' collisions
                        ON collisions.normalized_phone = plan.normalized_phone
                     ORDER BY plan.user_id'
                ),
            ];
        } finally {
            $pdo->exec('DROP TABLE IF EXISTS ' . self::COLLISION_TABLE);
        }
    }

    private function count(PDO $pdo, string $sql): int
    {
        return (int)$pdo->query($sql)->fetchColumn();
    }

    /**
     * @return list<string>
     */
    private function sampleIds(PDO $pdo, string $sql): array
    {
        $stmt = $pdo->query($sql . ' LIMIT ' . self::REPORT_SAMPLE_LIMIT);
        $ids = [];
        while (($id = $stmt->fetchColumn()) !== false) {
            $ids[] = (string)$id;
        }
        $stmt->closeCursor();

        return $ids;
    }

    private function updateFromPlan(PDO $pdo): int
    {
        $update = $pdo->prepare(
            'UPDATE users
             SET phone = :phone, updated_at = :updated_at
             WHERE id = :id AND phone = :original_phone'
        );
        $updatedAt = gmdate('Y-m-d H:i:s.v');
        $lastId = '0';
        $updated = 0;

        while (true) {
            $select = $pdo->prepare(
                'SELECT user_id, original_phone, normalized_phone
                 FROM ' . self::PLAN_TABLE . '
                 WHERE error_code IS NULL AND original_phone <> normalized_phone AND user_id > :last_id
                 ORDER BY user_id
                 LIMIT ' . $this->batchSize
            );
            $select->execute(['last_id' => $lastId]);
            $rowsRead = 0;

            while ($row = $select->fetch(PDO::FETCH_ASSOC)) {
                $lastId = (string)$row['user_id'];
                $rowsRead++;
                $update->execute([
                    'phone'          => $row['normalized_phone'],
                    'updated_at'     => $updatedAt,
                    'id'             => $row['user_id'],
                    'original_phone' => $row['original_phone'],
                ]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException(
                        'user phone migration aborted: phone changed after preflight for user ' . $lastId
                    );
                }
                $updated++;
            }
            $select->closeCursor();

            if ($rowsRead < $this->batchSize) {
                return $updated;
            }
        }
    }

    /**
     * @param list<string> $sampleIds
     */
    private function invalidMessage(array $sampleIds, int $totalCount): string
    {
        return 'user phone preflight failed: '
            . $totalCount . ' invalid phone(s); sample user_ids=' . implode(',', $sampleIds);
    }

    /**
     * @param list<string> $sampleIds
     */
    private function collisionMessage(array $sampleIds, int $totalCount): string
    {
        return 'user phone preflight failed: '
            . $totalCount . ' normalized phone collision group(s); sample user_ids=' . implode(',', $sampleIds);
    }
}
