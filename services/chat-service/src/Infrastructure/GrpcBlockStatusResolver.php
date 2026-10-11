<?php

declare(strict_types=1);

namespace Services\ChatService\Infrastructure;

use Contracts\Gen\Social\V1\CheckBidirectionalBlockRequest;
use Contracts\Gen\Social\V1\CheckBidirectionalBlockResponse;
use Google\Protobuf\Internal\Message;
use Packages\GrpcCommon\UnaryGrpcClient;
use Services\ChatService\Domain\Social\BlockStatusResolver;

final class GrpcBlockStatusResolver implements BlockStatusResolver
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

    /** @param class-string<Message> $responseClass */
    private function call(string $method, Message $request, string $responseClass): Message
    {
        $client = $this->client ??= new UnaryGrpcClient($this->target, $this->serviceToken);

        return $client->call($method, $request, $responseClass, $this->timeoutMs);
    }
}
