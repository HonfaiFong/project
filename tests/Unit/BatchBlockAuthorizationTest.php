<?php
declare(strict_types=1);

namespace Tests\Unit;

use Contracts\Gen\Social\V1\CheckBidirectionalBlockRequest;
use Contracts\Gen\Social\V1\CheckBidirectionalBlockResponse;
use Packages\GrpcCommon\UnaryGrpcClient;
use PHPUnit\Framework\TestCase;
use Services\ContentService\Infrastructure\GrpcBlockStatusResolver;
use Services\SocialService\Infrastructure\Repository\PdoSocialRepository;

final class BatchBlockAuthorizationTest extends TestCase
{
    public function testBatchUsesOneRpcAndPreservesBlockedIds(): void
    {
        $client = $this->createMock(UnaryGrpcClient::class);
        $client->expects(self::once())->method('call')->with(
            '/social.v1.SocialService/CheckBidirectionalBlock',
            self::callback(static fn ($request): bool => $request instanceof CheckBidirectionalBlockRequest && count($request->getTargetUserIds()) === 2),
            CheckBidirectionalBlockResponse::class, 200,
        )->willReturn(new CheckBidirectionalBlockResponse(['blocked_user_ids' => ['20'], 'batch_complete' => true]));
        self::assertSame(['20'], (new GrpcBlockStatusResolver('unused', client: $client))->blockedUserIds('10', ['20', '30']));
    }

    public function testOldSocialServerCannotSilentlyAllowBatch(): void
    {
        $client = $this->createMock(UnaryGrpcClient::class);
        $client->method('call')->willReturn(new CheckBidirectionalBlockResponse());
        $this->expectException(\RuntimeException::class);
        (new GrpcBlockStatusResolver('unused', client: $client))->blockedUserIds('10', ['20']);
    }

    public function testRepositoryBatchIncludesReverseBlocksAndNoUnrelatedAccounts(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE blocks (blocker_user_id TEXT, blocked_user_id TEXT)');
        $pdo->exec("INSERT INTO blocks VALUES ('10','20'),('30','10'),('40','50')");
        $ids = (new PdoSocialRepository($pdo))->blockedUserIds('10', ['20', '30', '40']);
        sort($ids);
        self::assertSame(['20', '30'], $ids);
    }
}
