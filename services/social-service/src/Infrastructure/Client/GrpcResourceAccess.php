<?php

declare(strict_types=1);

namespace Services\SocialService\Infrastructure\Client;

use Contracts\Errors\ErrorCode;
use Contracts\Gen\Content\V1\CanAccessResourceRequest;
use Contracts\Gen\Content\V1\CanAccessResourceResponse;
use Exception;
use Google\Protobuf\Internal\Message;
use Packages\GrpcCommon\GrpcCallException;
use Packages\GrpcCommon\UnaryGrpcClient;
use RuntimeException;
use Services\SocialService\Domain\Service\ResourceAccessInterface;
use const Grpc\STATUS_DEADLINE_EXCEEDED;
use const Grpc\STATUS_NOT_FOUND;
use const Grpc\STATUS_PERMISSION_DENIED;

final class GrpcResourceAccess implements ResourceAccessInterface
{
    private ?UnaryGrpcClient $client = null;

    public function __construct(
        private readonly string $target,
        private readonly int $timeoutMs = 150,
        private readonly ?string $serviceToken = null,
        ?UnaryGrpcClient $client = null,
    ) {
        $this->client = $client;
    }

    /** @return array{resource_author_user_id:string,root_author_user_id:string} */
    public function assertAccessible(string $actorUserId, int $targetType, string $targetId): array
    {
        $resourceType = match ($targetType) {
            1 => 'post',
            2 => 'comment',
            default => throw new RuntimeException(ErrorCode::INVALID_ARGUMENT->message(), ErrorCode::INVALID_ARGUMENT->value),
        };

        try {
            /** @var CanAccessResourceResponse $response */
            $response = $this->call(
                '/content.v1.ContentService/CanAccessResource',
                new CanAccessResourceRequest([
                    'resource_type' => $resourceType,
                    'resource_id' => $targetId,
                    'action' => 'like',
                ]),
                CanAccessResourceResponse::class,
                ['x-user-id' => [$actorUserId]],
            );
        } catch (Exception $e) {
            throw $this->map($e);
        }

        if (!$response->getAllowed()) {
            $code = $targetType === 1 ? ErrorCode::CONTENT_POST_NOT_FOUND : ErrorCode::CONTENT_COMMENT_NOT_FOUND;
            throw new RuntimeException($response->getReason() !== '' ? $response->getReason() : $code->message(), $code->value);
        }

        $resourceOwner = (string)$response->getResourceAuthorUserId();
        $rootOwner = (string)$response->getRootAuthorUserId();
        if (!$this->isPositiveId($resourceOwner) || !$this->isPositiveId($rootOwner)) {
            throw new RuntimeException(ErrorCode::SERVICE_UNAVAILABLE->message(), ErrorCode::SERVICE_UNAVAILABLE->value);
        }

        return [
            'resource_author_user_id' => $resourceOwner,
            'root_author_user_id' => $rootOwner,
        ];
    }

    /**
     * @template T of Message
     * @param class-string<T> $responseClass
     * @param array<string, array<string>> $metadata
     * @return T
     */
    private function call(
        string $method,
        Message $request,
        string $responseClass,
        array $metadata = [],
    ): Message
    {
        $client = $this->client ??= new UnaryGrpcClient($this->target, $this->serviceToken);

        return $client->call($method, $request, $responseClass, $this->timeoutMs, $metadata);
    }

    private function map(Exception $exception): RuntimeException
    {
        if ($exception instanceof GrpcCallException) {
            $code = match ($exception->grpcStatus) {
                STATUS_DEADLINE_EXCEEDED => ErrorCode::RPC_TIMEOUT->value,
                STATUS_NOT_FOUND => ErrorCode::CONTENT_POST_NOT_FOUND->value,
                STATUS_PERMISSION_DENIED => ErrorCode::SOCIAL_USER_BLOCKED->value,
                default => ErrorCode::SERVICE_UNAVAILABLE->value,
            };

            return new RuntimeException($exception->getMessage(), $code, $exception);
        }

        return new RuntimeException(ErrorCode::SERVICE_UNAVAILABLE->message(), ErrorCode::SERVICE_UNAVAILABLE->value, $exception);
    }

    private function isPositiveId(string $value): bool
    {
        return preg_match('/\A[1-9][0-9]*\z/', $value) === 1 && strlen($value) <= 20;
    }
}
