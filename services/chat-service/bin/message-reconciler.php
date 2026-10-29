<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Packages\Database\PostgresConnectionFactory;
use Services\ChatService\Infrastructure\MessageSummaryReconciler;
use Services\ChatService\Infrastructure\PdoChatRepository;

do {
    try {
        $messages = PostgresConnectionFactory::fromEnvironment([
            'PG_USER' => 'message_runtime', 'PG_SCHEMA' => 'message_db',
            'PG_PASSWORD' => getenv('PG_MESSAGE_RUNTIME_PASSWORD') ?: '',
        ]);
        $chat = PostgresConnectionFactory::fromEnvironment(['PG_SCHEMA' => 'chat_db']);
        $scanned = (new MessageSummaryReconciler($messages, new PdoChatRepository($chat)))->runOnce();
        fwrite(STDOUT, json_encode(['event' => 'message_summary_reconciled', 'scanned' => $scanned], JSON_THROW_ON_ERROR) . "\n");
    } catch (Throwable $exception) {
        error_log('Message summary reconciliation failed: ' . $exception::class);
        if (in_array('--once', $argv, true)) {
            exit(1);
        }
    } finally {
        unset($messages, $chat);
    }
    if (in_array('--once', $argv, true)) {
        break;
    }
    sleep(60);
} while (true);
