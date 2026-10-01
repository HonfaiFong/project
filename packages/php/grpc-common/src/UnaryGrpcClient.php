<?php

declare(strict_types=1);

namespace Packages\GrpcCommon;

use Google\Protobuf\Internal\Message;
use Grpc\BaseStub;
use Grpc\ChannelCredentials;
use const Grpc\STATUS_OK;

class UnaryGrpcClient extends BaseStub
{
    public function __construct(
        string $target,
        private readonly ?string $serviceToken = null,
    )
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
        $options = GrpcClientContext::createCallOptions($timeoutMs, $metadata, $this->serviceToken);
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
            $appErrorCode = $this->extractAppErrorCode($status->metadata ?? []);
            throw new GrpcCallException($status->code, $appErrorCode, $message);
        }

        return $response;
    }

    /**
     * @param mixed $metadata
     */
    private function extractAppErrorCode(mixed $metadata): ?int
    {
        if (!is_array($metadata)) {
            return null;
        }

        foreach ($metadata as $key => $values) {
            if (strtolower((string)$key) === 'x-app-error-code') {
                $val = is_array($values) ? ($values[0] ?? null) : $values;
                if ($val !== null && is_numeric($val)) {
                    return (int)$val;
                }
            }
        }

        return null;
    }
}
