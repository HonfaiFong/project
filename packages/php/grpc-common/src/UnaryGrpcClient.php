<?php

declare(strict_types=1);

namespace Packages\GrpcCommon;

use Contracts\Errors\ErrorCode;
use Google\Protobuf\Internal\Message;
use Grpc\BaseStub;
use Grpc\ChannelCredentials;
use const Grpc\STATUS_DEADLINE_EXCEEDED;
use const Grpc\STATUS_INVALID_ARGUMENT;
use const Grpc\STATUS_NOT_FOUND;
use const Grpc\STATUS_OK;
use const Grpc\STATUS_PERMISSION_DENIED;
use const Grpc\STATUS_RESOURCE_EXHAUSTED;
use const Grpc\STATUS_UNAUTHENTICATED;
use RuntimeException;

class UnaryGrpcClient extends BaseStub
{
    public function __construct(string $target)
    {
        parent::__construct($target, [
            'credentials' => ChannelCredentials::createInsecure(),
        ]);
    }

    /**
     * @template T of Message
     * @param class-string<T> $responseClass
     * @param array<string, array<string>> $metadata
     * @return T
     */
    public function call(
        string $method,
        Message $request,
        string $responseClass,
        int $timeoutMs,
        array $metadata = []
    ): Message {
        $options = GrpcClientContext::createCallOptions($timeoutMs, $metadata);
        // 本项目的 grpc/grpc 版本按 list($className, $func) 解构反序列化器,
        // 只认二元数组不认 Closure,第二个元素仅占位
        /** @var callable $deserialize */
        $deserialize = [$responseClass, 'mergeFromString'];
        $call = $this->_simpleRequest(
            $method,
            $request,
            $deserialize,
            $options['metadata'],
            ['timeout' => $options['timeout']]
        );

        [$response, $status] = $call->wait();
        if ($status->code !== STATUS_OK || !$response instanceof $responseClass) {
            $message = (string)($status->details ?? 'gRPC request failed');
            $code = match ($status->code) {
                STATUS_DEADLINE_EXCEEDED => ErrorCode::RPC_TIMEOUT->value,
                STATUS_UNAUTHENTICATED => ($message === ErrorCode::AUTH_INVALID_CREDENTIALS->message()
                    ? ErrorCode::AUTH_INVALID_CREDENTIALS->value
                    : ErrorCode::AUTH_UNAUTHORIZED->value),
                STATUS_INVALID_ARGUMENT => ErrorCode::INVALID_ARGUMENT->value,
                STATUS_NOT_FOUND => ErrorCode::USER_NOT_FOUND->value,
                STATUS_PERMISSION_DENIED => ErrorCode::PERSONA_FORBIDDEN->value,
                STATUS_RESOURCE_EXHAUSTED => ErrorCode::RATE_LIMIT_EXCEEDED->value,
                default => ErrorCode::SERVICE_UNAVAILABLE->value,
            };
            throw new RuntimeException($message, $code);
        }

        return $response;
    }
}
