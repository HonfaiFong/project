<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Packages\Phone\PhoneNormalizer;
use Services\UserService\Infrastructure\Migration\UserPhoneMigration;

$options = array_slice($argv, 1);
$apply = in_array('--apply', $options, true);
$requireClean = in_array('--require-clean', $options, true);
$host = getenv('MYSQL_HOST') ?: '127.0.0.1';
$port = getenv('MYSQL_PORT') ?: '3306';
$database = getenv('USER_DB_NAME') ?: 'user_db';
$user = getenv('MYSQL_USER') ?: 'root';
$password = getenv('MYSQL_PASSWORD') ?: '';
$dsn = getenv('USER_PHONE_MIGRATION_DSN') ?: sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $host,
    $port,
    $database,
);

$pdo = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$migration = new UserPhoneMigration(new PhoneNormalizer(getenv('PHONE_DEFAULT_REGION') ?: 'CN'));

if (!$apply) {
    $plan = $migration->preflight($pdo);
    foreach ($plan['invalid'] as $row) {
        fwrite(STDERR, sprintf("invalid phone: user_id=%s phone=%s\n", $row['id'], $row['phone']));
    }
    foreach ($plan['collisions'] as $phone => $ids) {
        fwrite(STDERR, sprintf("normalized phone collision: phone=%s user_ids=%s\n", $phone, implode(',', $ids)));
    }

    printf(
        "Preflight: %d change(s), %d invalid phone(s), %d collision(s).\n",
        count($plan['changes']),
        count($plan['invalid']),
        count($plan['collisions']),
    );

    if ($requireClean && $plan['changes'] !== []) {
        fwrite(STDERR, "Phone backfill is required before starting the new services; review preflight and run --apply during a maintenance window.\n");
    }
    exit($plan['invalid'] !== [] || $plan['collisions'] !== []
        || ($requireClean && $plan['changes'] !== []) ? 2 : 0);
}

$result = $migration->apply($pdo);
printf("Applied phone normalization to %d user(s).\n", $result['updated']);
