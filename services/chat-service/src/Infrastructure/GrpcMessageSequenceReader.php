<?php

declare(strict_types=1);

namespace Services\ChatService\Infrastructure;

use Contracts\Errors\ErrorCode;
use Contracts\Gen\Message\V1\GetConversationLatestSequenceRequest;
use Contracts\Gen\Message\V1\GetConversationLatestSequenceResponse;
use Packages\GrpcCommon\GrpcCallException;
use Packages\GrpcCommon\OperationBudget;
use Packages\GrpcCommon\UnaryGrpcClient;
use RuntimeException;
use Services\ChatService\Domain\MessageSequenceReader;
use Spiral\RoadRunner\GRPC\StatusCode;

final class GrpcMessageSequenceReader implements MessageSequenceReader
{
    private ?UnaryGrpcClient $client = null;

    public function __construct(
        private readonly string $target,
        private readonly ?string $serviceToken = null,
        private readonly int $timeoutMs = 250,
        ?UnaryGrpcClient $client = null,
    ) {
        if ($timeoutMs < 1) {
            throw new RuntimeException('Message sequence timeout must be positive');
        }
        $this->client = $client;
    }

    public function latestSequence(string $conversationId, OperationBudget $budget): string
    {
        $timeoutMs = min($this->timeoutMs, $budget->remainingMilliseconds(10));
        $client = $this->client ??= new UnaryGrpcClient($this->target, $this->serviceToken);

        try {
            /** @var GetConversationLatestSequenceResponse $response */
            $response = $client->call(
                '/message.v1.MessageService/GetConversationLatestSequence',
                new GetConversationLatestSequenceRequest(['conversation_id' => $conversationId]),
                GetConversationLatestSequenceResponse::class,
                $timeoutMs,
            );
        } catch (GrpcCallException $exception) {
            $applicationError = $exception->appErrorCode === null
                ? null
                : ErrorCode::tryFrom($exception->appErrorCode);
            if ($applicationError !== null && $applicationError !== ErrorCode::OK) {
                throw new RuntimeException($applicationError->message(), $applicationError->value, $exception);
            }
            if ($exception->grpcStatus === StatusCode::DEADLINE_EXCEEDED) {
                throw new RuntimeException(ErrorCode::RPC_TIMEOUT->message(), ErrorCode::RPC_TIMEOUT->value, $exception);
            }
            if ($exception->grpcStatus === StatusCode::UNAVAILABLE) {
                throw new RuntimeException(ErrorCode::SERVICE_UNAVAILABLE->message(), ErrorCode::SERVICE_UNAVAILABLE->value, $exception);
            }

            throw new RuntimeException('Message sequence lookup failed', ErrorCode::INTERNAL_SERVER_ERROR->value, $exception);
        }

        return (string)$response->getLatestSeq();
    }
}
