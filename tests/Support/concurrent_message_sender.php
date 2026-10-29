<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Packages\Database\PostgresConnectionFactory;
use Packages\GrpcCommon\OperationBudget;
use Packages\Identity\AnonymousAuthorProjector;
use Packages\Identity\AnonymousReferenceFactory;
use Services\MessageService\Application\MessageApplicationService;
use Services\MessageService\Domain\ChatMemberSnapshotClient;
use Services\MessageService\Infrastructure\Postgres\PostgresMessageRepository;
use Services\MessageService\Infrastructure\Postgres\PostgresMessageTransactionManager;

if (getenv('DATABASE_INTEGRATION') !== '1' || !str_starts_with((string)getenv('PG_DATABASE'), 'test_')) {
    throw new RuntimeException('A disposable test database is required');
}
fread(STDIN, 1);
$pdo = PostgresConnectionFactory::fromEnvironment(['PG_SCHEMA' => 'message_db']);
$chat = new class implements ChatMemberSnapshotClient {
    public function getConversationMemberSnapshot(string $trustedUserId, string $conversationId, ?OperationBudget $budget = null): array
    {
        return ['allowed' => true, 'denial_reason' => '', 'member' => [
            'user_id' => $trustedUserId, 'persona_id' => '101', 'identity_mode' => 1, 'display_name_snapshot' => 'Snapshot',
        ]];
    }
};
$app = new MessageApplicationService(new PostgresMessageRepository($pdo), new PostgresMessageTransactionManager($pdo), $chat,
    projector: new AnonymousAuthorProjector(new AnonymousReferenceFactory(str_repeat('k', 32))));
$app->sendMessage('100', ['conversation_id' => '1', 'client_message_id' => $argv[1], 'type' => 1,
    'content' => '{"body":"concurrent"}'], new OperationBudget(5000));
