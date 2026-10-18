<?php

declare(strict_types=1);

namespace Packages\GrpcCommon;

use Google\Protobuf\Internal\Message;
use Exception;
use Grpc\BaseStub;
use Packages\Observability\Span;
use Packages\Observability\Tracer;
use Throwable;
use const Grpc\STATUS_OK;

class UnaryGrpcClient extends BaseStub
{
    /** @throws Exception */
    public function __construct(
        private readonly string $target,
        private readonly ?string $serviceToken = null,
    )
    {
        $options = GrpcTlsConfig::fromEnvironment($target)->stubOptions();
        // A replaced container can leave an idle channel pointing at its old IP.
        // Only the read-only health RPC waits for reconnection and retries once;
        // both attempts share the original call deadline, including backoff.
        $options['grpc.service_config'] = json_encode(['methodConfig' => [[
            'name' => [['service' => 'common.v1.HealthService', 'method' => 'Check']],
            'waitForReady' => true,
            'retryPolicy' => [
                'maxAttempts' => 2,
                'initialBackoff' => '0.01s',
                'maxBackoff' => '0.01s',
                'backoffMultiplier' => 1,
                'retryableStatusCodes' => ['UNAVAILABLE'],
            ],
        ]]], JSON_THROW_ON_ERROR);
        parent::__construct($target, $options);
    }

    /**
     * @template T of Message
     * @param class-string<T> $responseClass
     * @param array<string, array<string>> $metadata
     * @return T
     * @throws Exception
     */
    public function call(
        string $method,
        Message $request,
        string $responseClass,
        int $timeoutMs,
        array $metadata = []
    ): Message {
        $scope = Tracer::enter('grpc.client', Span::KIND_CLIENT, [
            'rpc.system' => 'grpc',
            'rpc.method' => $method,
            'server.address' => $this->target,
        ]);
        try {
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
            $scope->span->setAttribute('rpc.grpc.status_code', (int)$status->code);
            if ($status->code !== STATUS_OK || !$response instanceof $responseClass) {
                $message = (string)($status->details ?? 'gRPC request failed');
                $appErrorCode = $this->extractAppErrorCode($status->metadata ?? []);
                throw new GrpcCallException($status->code, $appErrorCode, $message);
            }

            return $response;
        } catch (Throwable $exception) {
            $scope->fail($exception);
            throw $exception;
        } finally {
            $scope->close();
        }
    }

    private function extractAppErrorCode(mixed $metadata): ?int
    {
        if (!is_array($metadata)) {
            return null;
        }

        foreach ($metadata as $key => $values) {
            if (strtolower((string)$key) === 'x-app-error-code') {
                $val = is_array($values) ? ($values[0] ?? null) : $values;
                if (is_numeric($val)) {
                    return (int)$val;
                }
            }
        }

        return null;
    }
}
