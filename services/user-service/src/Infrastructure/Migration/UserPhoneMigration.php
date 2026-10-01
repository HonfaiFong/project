<?php

declare(strict_types=1);

namespace Services\UserService\Infrastructure\Migration;

use PDO;
use Packages\Phone\PhoneNormalizer;
use RuntimeException;
use Throwable;

/**
 * Plans and, when explicitly requested, applies the user phone normalization.
 *
 * This is kept outside the regular service startup path because historical data
 * must be reviewed before any phone value is changed.
 */
final class UserPhoneMigration
{
    public function __construct(private readonly PhoneNormalizer $phoneNormalizer)
    {
    }

    /**
     * @return array{
     *     changes: list<array{id: string, phone: string, normalized_phone: string}>,
     *     invalid: list<array{id: string, phone: string}>,
     *     collisions: array<string, list<string>>,
     * }
     */
    public function preflight(PDO $pdo, bool $lockRows = false): array
    {
        $sql = 'SELECT id, phone FROM users WHERE phone IS NOT NULL ORDER BY id';
        if ($lockRows && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $changes = [];
        $invalid = [];
        $groups = [];

        foreach ($rows as $row) {
            $id = (string)$row['id'];
            $phone = (string)$row['phone'];

            try {
                $normalized = $this->phoneNormalizer->normalize($phone);
            } catch (RuntimeException) {
                $invalid[] = [
                    'id'    => $id,
                    'phone' => $phone,
                ];
                continue;
            }

            $groups[$normalized][] = $id;
            if ($phone !== $normalized) {
                $changes[] = [
                    'id'               => $id,
                    'phone'            => $phone,
                    'normalized_phone' => $normalized,
                ];
            }
        }

        $collisions = [];
        foreach ($groups as $normalized => $ids) {
            $uniqueIds = array_values(array_unique($ids));
            if (count($uniqueIds) > 1) {
                $collisions[$normalized] = $uniqueIds;
            }
        }

        return [
            'changes'    => $changes,
            'invalid'    => $invalid,
            'collisions' => $collisions,
        ];
    }

    /**
     * @return array{updated: int}
     */
    public function apply(PDO $pdo): array
    {
        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $plan = $this->preflight($pdo, true);
            if ($plan['invalid'] !== []) {
                throw new RuntimeException($this->invalidMessage($plan['invalid']));
            }
            if ($plan['collisions'] !== []) {
                throw new RuntimeException($this->collisionMessage($plan['collisions']));
            }

            $stmt = $pdo->prepare(
                'UPDATE users
                 SET phone = :phone, updated_at = :updated_at
                 WHERE id = :id AND phone = :original_phone'
            );
            $updatedAt = gmdate('Y-m-d H:i:s.v');
            foreach ($plan['changes'] as $change) {
                $stmt->execute([
                    'phone'          => $change['normalized_phone'],
                    'updated_at'     => $updatedAt,
                    'id'             => $change['id'],
                    'original_phone' => $change['phone'],
                ]);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException(
                        'user phone migration aborted: phone changed after preflight for user ' . $change['id']
                    );
                }
            }

            if ($startedTransaction) {
                $pdo->commit();
            }

            return ['updated' => count($plan['changes'])];
        } catch (Throwable $e) {
            if ($startedTransaction) {
                try {
                    $pdo->rollBack();
                } catch (Throwable) {
                    // The transaction may already have been closed by a failed commit.
                }
            }
            throw $e;
        }
    }

    /**
     * @param list<array{id: string, phone: string}> $invalid
     */
    private function invalidMessage(array $invalid): string
    {
        $values = array_map(
            static fn (array $row): string => $row['id'] . ':' . $row['phone'],
            $invalid,
        );

        return 'user phone preflight failed: invalid phone(s) ' . implode(', ', $values);
    }

    /**
     * @param array<string, list<string>> $collisions
     */
    private function collisionMessage(array $collisions): string
    {
        $values = [];
        foreach ($collisions as $phone => $ids) {
            $values[] = $phone . '=[' . implode(',', $ids) . ']';
        }

        return 'user phone preflight failed: normalized phone collision(s) ' . implode(', ', $values);
    }
}
