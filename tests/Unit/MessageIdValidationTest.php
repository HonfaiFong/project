<?php

declare(strict_types=1);

namespace Tests\Unit;

use Contracts\Errors\ErrorCode;
use Packages\Identity\AnonymousAuthorProjector;
use Packages\Identity\AnonymousReferenceFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Services\MessageService\Application\MessageApplicationService;
use Services\MessageService\Domain\ChatMemberSnapshotClient;
use Services\MessageService\Domain\Exception\MessageException;
use Services\MessageService\Domain\IdGenerator;
use Services\MessageService\Domain\Repository\MessageRepositoryInterface;
use Services\MessageService\Domain\Repository\MessageTransactionManagerInterface;

final class MessageIdValidationTest extends TestCase
{
    #[DataProvider('invalidIds')]
    public function testInvalidIdsAreRejectedBeforeRpcOrPersistence(string $userId, string $conversationId): void
    {
        $repository = $this->createMock(MessageRepositoryInterface::class);
        $repository->expects(self::never())->method('findBySenderClientId');
        $transactions = $this->createMock(MessageTransactionManagerInterface::class);
        $transactions->expects(self::never())->method('transaction');
        $chat = $this->createMock(ChatMemberSnapshotClient::class);
        $chat->expects(self::never())->method('getConversationMemberSnapshot');
        $ids = $this->createMock(IdGenerator::class);
        $ids->expects(self::never())->method('nextId');
        $app = new MessageApplicationService(
            repository: $repository,
            transactions: $transactions,
            chat: $chat,
            ids: $ids,
            projector: new AnonymousAuthorProjector(new AnonymousReferenceFactory(str_repeat('k', 32))),
        );

        $this->expectException(MessageException::class);
        $this->expectExceptionCode(ErrorCode::INVALID_ARGUMENT->value);
        $app->sendMessage($userId, [
            'client_message_id' => 'invalid-id', 'conversation_id' => $conversationId,
            'type' => 1, 'content' => '{"body":"rejected"}',
        ]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidIds(): iterable
    {
        yield 'non-numeric user' => ['user-100', '70001'];
        yield 'non-numeric conversation' => ['100', 'conv-idempotent-1'];
        yield 'zero' => ['100', '0'];
        yield 'negative' => ['100', '-1'];
        yield 'leading zero' => ['100', '070001'];
        yield 'BSON Int64 overflow' => ['100', '9223372036854775808'];
    }
}
