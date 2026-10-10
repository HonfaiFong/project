<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Packages\Phone\PhoneNormalizer;
use Services\UserService\Infrastructure\Migration\UserPhoneMigration;

set_exception_handler(static function (Throwable $exception): void {
    if ($exception instanceof PDOException) {
        fwrite(
            STDERR,
            sprintf("Phone migration database error (code=%s, type=PDOException).\n", $exception->getCode())
        );
    } else {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    }
    exit(1);
});

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
    if ($plan['invalid_user_ids'] !== []) {
        fwrite(
            STDERR,
            sprintf("invalid phone user_ids (sample): %s\n", implode(',', $plan['invalid_user_ids']))
        );
    }
    if ($plan['collision_user_ids'] !== []) {
        fwrite(
            STDERR,
            sprintf(
                "normalized phone collision user_ids (sample): %s\n",
                implode(',', $plan['collision_user_ids'])
            )
        );
    }

    printf(
        "Preflight: %d change(s), %d invalid phone(s), %d collision(s).\n",
        $plan['change_count'],
        $plan['invalid_count'],
        $plan['collision_count'],
    );

    if ($requireClean && $plan['change_count'] > 0) {
        fwrite(STDERR, "Phone backfill is required before starting the new services; review preflight and run --apply during a maintenance window.\n");
    }
    exit($plan['invalid_count'] > 0 || $plan['collision_count'] > 0
        || ($requireClean && $plan['change_count'] > 0) ? 2 : 0);
}

// The set_exception_handler above reports uncaught failures to STDERR and
// exits non-zero, so the migration is allowed to propagate here.
/** @noinspection PhpUnhandledExceptionInspection */
$result = $migration->apply($pdo);
printf("Applied phone normalization to %d user(s).\n", $result['updated']);
