<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use Services\ChatService\Infrastructure\PdoChatRepository;
use Tests\TestCase;

final class ChatActivitySummaryTest extends TestCase
{
    public function testSummaryReplaysDoNotRegressActivityAndCursorFollowsSortOrder(): void
    {
        $repository = new PdoChatRepository(new PDO('sqlite::memory:'));
        $repository->ensureSchema();
        $createdAt = '2026-10-12 00:00:00.000';
        foreach (['100', '200'] as $id) {
            $repository->insert([
                'id' => $id, 'private_key' => 'key-' . $id, 'target_mode' => 1,
                'created_at' => $createdAt, 'updated_at' => $createdAt,
            ], [[
                'id' => $id, 'user_id' => '10', 'persona_id' => '11', 'identity_mode' => 1, 'joined_at' => $createdAt,
            ]]);
        }
        $repository->applyMessageSummary('100', '1002', '2', '2026-10-12 00:02:00.000');
        $repository->applyMessageSummary('100', '1001', '1', '2026-10-12 00:01:00.000');
        $repository->applyMessageSummary('100', '1002', '2', '2026-10-12 00:02:00.000');
        $firstPage = $repository->listForUser('10', 1);
        self::assertSame('100', (string)$firstPage[0]['id']);
        self::assertSame('1002', (string)$firstPage[0]['last_message_id']);
        $nextPage = $repository->listForUser('10', 1, $repository->listCursor($firstPage[0]));
        self::assertCount(1, $nextPage);
        self::assertSame('200', (string)$nextPage[0]['id']);
        self::assertTrue($repository->markRead('100', '10', '2'));
        self::assertTrue($repository->markRead('100', '10', '1'));
        self::assertSame('2', (string)$repository->findMember('100', '10')['last_read_seq']);
        self::assertSame('2', $repository->latestMessageSequence('100'));
        $repository->applyMessageSummary('200', '2001', '1', '2026-10-12 00:03:00.000');
        self::assertSame('200', (string)$repository->listForUser('10', 1)[0]['id']);
    }
}
