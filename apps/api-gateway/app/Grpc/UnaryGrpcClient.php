<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Grpc;

use Contracts\Errors\ErrorCode;
use Google\Protobuf\Internal\Message;
use Grpc\BaseStub;
use Grpc\ChannelCredentials;
use const Grpc\STATUS_DEADLINE_EXCEEDED;
use const Grpc\STATUS_OK;
use Packages\GrpcCommon\GrpcClientContext;
use RuntimeException;

final class UnaryGrpcClient extends BaseStub
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
            $code = $status->code === STATUS_DEADLINE_EXCEEDED
                ? ErrorCode::RPC_TIMEOUT->value
                : ErrorCode::SERVICE_UNAVAILABLE->value;
            throw new RuntimeException($message, $code);
        }

        return $response;
    }
}
