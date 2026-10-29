<?php

declare(strict_types=1);

namespace Services\ContentService\Infrastructure;

use Contracts\Gen\Social\V1\CheckBidirectionalBlockRequest;
use Contracts\Gen\Social\V1\CheckBidirectionalBlockResponse;
use Google\Protobuf\Internal\Message;
use Packages\GrpcCommon\UnaryGrpcClient;
use Services\ContentService\Domain\Social\BlockStatusResolver;

final class GrpcBlockStatusResolver implements \Services\ContentService\Domain\Social\BatchBlockStatusResolver
{
    private ?UnaryGrpcClient $client = null;

    public function __construct(
        private readonly string $target,
        private readonly ?string $serviceToken = null,
        private readonly int $timeoutMs = 200,
        ?UnaryGrpcClient $client = null,
    ) {
        $this->client = $client;
    }

    public function isBlockedEitherWay(string $firstUserId, string $secondUserId): bool
    {
        /** @var CheckBidirectionalBlockResponse $response */
        $response = $this->call(
            '/social.v1.SocialService/CheckBidirectionalBlock',
            new CheckBidirectionalBlockRequest([
                'user_id_a' => $firstUserId,
                'user_id_b' => $secondUserId,
            ]),
            CheckBidirectionalBlockResponse::class,
        );

        return $response->getBlocked();
    }

    /** @param list<string> $targets @return list<string> */
    public function blockedUserIds(string $actor, array $targets): array
    {
        if ($targets === []) {
            return [];
        }
        if (count($targets) > 100) {
            throw new \InvalidArgumentException('Too many block targets');
        }
        $response = $this->call('/social.v1.SocialService/CheckBidirectionalBlock', new CheckBidirectionalBlockRequest([
            'user_id_a' => $actor, 'target_user_ids' => $targets,
        ]), CheckBidirectionalBlockResponse::class);
        if (!$response->getBatchComplete()) {
            throw new \RuntimeException('Social server does not support batch authorization', \Contracts\Errors\ErrorCode::SERVICE_UNAVAILABLE->value);
        }
        return array_map(static fn ($id): string => (string)$id, iterator_to_array($response->getBlockedUserIds()));
    }

    /**
     * @template T of Message
     * @param class-string<T> $responseClass
     * @return T
     */
    private function call(string $method, Message $request, string $responseClass): Message
    {
        $client = $this->client ??= new UnaryGrpcClient($this->target, $this->serviceToken);

        return $client->call($method, $request, $responseClass, $this->timeoutMs);
    }
}
