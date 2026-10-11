<?php

declare(strict_types=1);

/**
 * Seed the two MAIN accounts used by the isolated CR05 RPC probes.
 *
 * The schema prefix is deliberately mandatory and restricted to the test
 * namespace. This script never writes an unprefixed production database.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$prefix = trim((string)(getenv('MYSQL_SCHEMA_PREFIX') ?: ''));
if (preg_match('/\Asocial_test_[A-Za-z0-9_]+\z/', $prefix) !== 1 || strlen($prefix) > 50) {
    throw new RuntimeException('MYSQL_SCHEMA_PREFIX must be a social_test_ prefixed isolated schema');
}

$host = (string)(getenv('MYSQL_HOST') ?: '127.0.0.1');
$port = (int)(getenv('MYSQL_PORT') ?: 3306);
$user = (string)(getenv('MYSQL_USER') ?: 'root');
$password = (string)(getenv('MYSQL_PASSWORD') ?: '');
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
    $user,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
);

$quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';
$userTable = $quote($prefix . 'user_db') . '.`users`';
$personaTable = $quote($prefix . 'user_db') . '.`personas`';
$now = gmdate('Y-m-d H:i:s.v');

$userInsert = $pdo->prepare(
    'INSERT INTO ' . $userTable . ' '
    . '(`id`, `status`, `phone`, `email`, `register_channel`, `risk_level`, `created_at`, `updated_at`, `deleted_at`) '
    . 'VALUES (:id, 1, NULL, NULL, \'cr05-rpc\', 0, :created_at, :updated_at, NULL) '
    . 'ON DUPLICATE KEY UPDATE `status` = 1, `phone` = NULL, `email` = NULL, '
    . '`register_channel` = \'cr05-rpc\', `risk_level` = 0, `updated_at` = VALUES(`updated_at`), `deleted_at` = NULL',
);

$personaInsert = $pdo->prepare(
    'INSERT INTO ' . $personaTable . ' '
    . '(`id`, `user_id`, `type`, `is_internal_alias`, `nickname`, `avatar_key`, `bio`, `status`, `created_at`, `updated_at`, `deleted_at`) '
    . 'VALUES (:id, :user_id, 1, 0, :nickname, NULL, NULL, 1, :created_at, :updated_at, NULL) '
    . 'ON DUPLICATE KEY UPDATE `user_id` = VALUES(`user_id`), `type` = 1, `is_internal_alias` = 0, '
    . '`nickname` = VALUES(`nickname`), `status` = 1, `updated_at` = VALUES(`updated_at`), `deleted_at` = NULL',
);

foreach ([['1001', '2001', 'CR05 Main A'], ['1002', '2002', 'CR05 Main B']] as [$userId, $personaId, $nickname]) {
    $userInsert->execute(['id' => $userId, 'created_at' => $now, 'updated_at' => $now]);
    $personaInsert->execute([
        'id' => $personaId,
        'user_id' => $userId,
        'nickname' => $nickname,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

echo json_encode([
    'ok' => true,
    'schema_prefix' => $prefix,
    'users' => ['1001', '1002'],
    'main_personas' => ['2001', '2002'],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
