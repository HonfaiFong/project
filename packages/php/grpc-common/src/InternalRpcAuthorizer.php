<?php

declare(strict_types=1);

namespace Packages\GrpcCommon;

use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\StatusCode;
use InvalidArgumentException;

/**
 * Authenticates and authorizes callers on the internal gRPC boundary.
 *
 * The caller identity is carried in metadata because the public protobuf
 * contracts must remain unchanged. Each caller has its own token, and an
 * optional method allow-list keeps a service with read-only needs from
 * reaching write methods on the same target.
 */
final readonly class InternalRpcAuthorizer
{
    /**
     * @param array<string, string> $callerTokens
     * @param array<string, array<string>> $allowedMethodsByCaller Empty means all methods for that caller
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
     * @throws GRPCException when the caller is absent, unknown, or presents an invalid token
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
     * Keep caller authentication independent of the service-specific trace readers.
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
