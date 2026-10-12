<?php

declare(strict_types=1);

// 并发冲突夹具：在独立进程中插入一条评论并持有唯一索引锁；当检测到业务连接的 INSERT 正在等待该锁时，
// 提交事务释放锁。主进程只需一个 PDO 连接即可复现 duplicate-key 恢复路径。
// 参数：<readyPath> <donePath> <database> <commentId> <postId> <requestId> <content>

if ($argc < 8) {
    exit(2);
}

$readyPath = (string)$argv[1];
$donePath = (string)$argv[2];
$database = (string)$argv[3];
$commentId = (string)$argv[4];
$postId = (string)$argv[5];
$requestId = (string)$argv[6];
$content = (string)$argv[7];
$host = getenv('SOCIAL_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int)(getenv('SOCIAL_TEST_MYSQL_PORT') ?: 3306);
$user = getenv('SOCIAL_TEST_MYSQL_USER') ?: 'root';
$password = getenv('SOCIAL_TEST_MYSQL_PASSWORD') ?: '';
$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);

try {
    $holder = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $holder->exec('SET SESSION innodb_lock_wait_timeout = 5');
    $holder->beginTransaction();
    $statement = $holder->prepare(
        'INSERT INTO comments (id, post_id, author_user_id, request_id, persona_id, identity_mode, content, status, created_at, updated_at) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)',
    );
    $now = gmdate('Y-m-d H:i:s.v');
    $statement->execute([$commentId, $postId, '10', $requestId, '110', 1, $content, $now, $now]);
    file_put_contents($readyPath, 'ready');

    $prober = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $waitQuery = $prober->prepare(
        'SELECT COUNT(*) FROM performance_schema.data_lock_waits AS w '
        . 'JOIN performance_schema.data_locks AS l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID '
        . 'WHERE l.OBJECT_SCHEMA = ? AND l.OBJECT_NAME = ? AND l.LOCK_TYPE = ? '
        . 'AND l.INDEX_NAME = ?',
    );
    $deadline = microtime(true) + 4.0;
    $waiting = false;
    while (microtime(true) < $deadline) {
        $waitQuery->execute([$database, 'comments', 'RECORD', 'uniq_author_request']);
        if ((int)$waitQuery->fetchColumn() > 0) {
            $waiting = true;
            break;
        }
        usleep(20000);
    }
    if (!$waiting) {
        $holder->rollBack();
        file_put_contents($donePath, 'collision worker never observed the blocked INSERT');
        fwrite(STDERR, 'collision worker never observed the blocked INSERT' . PHP_EOL);
        exit(4);
    }
    $holder->commit();
    file_put_contents($donePath, 'done');
    exit(0);
} catch (Throwable $e) {
    file_put_contents($donePath, 'collision worker failed: ' . $e->getMessage());
    fwrite(STDERR, 'collision worker failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
