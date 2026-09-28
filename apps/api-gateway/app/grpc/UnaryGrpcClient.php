<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Grpc;

use Contracts\Errors\ErrorCode;
use Google\Protobuf\Internal\Message;
use Grpc\BaseStub;
use Grpc\ChannelCredentials;
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

    public function call(
        string $method,
        Message $request,
        string $responseClass,
        int $timeoutMs,
        array $metadata = []
    ): Message {
        $options = GrpcClientContext::createCallOptions($timeoutMs, $metadata);
        $call = $this->_simpleRequest(
            $method,
            $request,
            static function (string $payload) use ($responseClass): Message {
                $response = new $responseClass();
                $response->mergeFromString($payload);
                return $response;
            },
            $options['metadata'],
            ['timeout' => $options['timeout']]
        );

        [$response, $status] = $call->wait();
        if ($status->code !== STATUS_OK || !$response instanceof Message) {
            $message = (string)($status->details ?? 'gRPC request failed');
            $code = $status->code === STATUS_DEADLINE_EXCEEDED
                ? ErrorCode::RPC_TIMEOUT->value
                : ErrorCode::SERVICE_UNAVAILABLE->value;
            throw new RuntimeException($message, $code);
        }

        return $response;
    }
}
