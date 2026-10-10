<?php

declare(strict_types=1);

namespace Packages\GrpcCommon;

use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\StatusCode;
use InvalidArgumentException;

/**
 * 在内部 gRPC 边界认证并授权调用方。
 *
 * 为保持公共 Protobuf 协议不变，通过元数据传递调用方身份。每个调用方使用独立令牌；可选的方法白名单防止仅需读取权
 * 限的服务调用同一目标的写入方法。
 */
final readonly class InternalRpcAuthorizer
{
    /**
     * @param array<string, string> $callerTokens
     * @param array<string, array<string>> $allowedMethodsByCaller 为空时允许该调用方访问所有方法
     */
    public function __construct(
        private array $callerTokens,
        private array $allowedMethodsByCaller = [],
    ) {
        if ($this->callerTokens === []) {
            throw new InvalidArgumentException('Internal RPC authorization requires callers and a token');
        }
        foreach ($this->callerTokens as $caller => $token) {
            if ($caller === '' || $token === '' || strlen($token) < 32) {
                throw new InvalidArgumentException('Internal RPC tokens must be at least 32 bytes');
            }
        }
    }

    /**
     * @throws GRPCException 调用方缺失、未知或提供无效令牌时抛出
     */
    public function authorize(ContextInterface $ctx, string $method = ''): void
    {
        $caller = $this->metadataValue($ctx, 'x-service-name');
        $token = $this->metadataValue($ctx, 'x-service-token');
        $expectedToken = $caller !== null ? ($this->callerTokens[$caller] ?? null) : null;
        $allowedMethods = $caller !== null ? ($this->allowedMethodsByCaller[$caller] ?? null) : null;

        if (
            $caller === null
            || $expectedToken === null
            || $token === null
            || !hash_equals($expectedToken, $token)
        ) {
            throw GRPCException::create(
                'internal service authentication failed',
                StatusCode::UNAUTHENTICATED,
            );
        }

        if ($allowedMethods !== null && !in_array($method, $allowedMethods, true)) {
            throw GRPCException::create(
                'internal service is not authorized for this method',
                StatusCode::PERMISSION_DENIED,
            );
        }
    }

    /**
     * 调用方认证与各服务的追踪信息读取逻辑保持独立。
     * @noinspection DuplicatedCode
     */
    private function metadataValue(ContextInterface $ctx, string $key): ?string
    {
        $values = $ctx->getValues();
        $value = $values[$key] ?? null;
        if ($value === null && is_array($values['metadata'] ?? null)) {
            $value = $values['metadata'][$key] ?? null;
        }
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_scalar($value) && (string)$value !== '' ? (string)$value : null;
    }
}
