<?php

declare(strict_types=1);

namespace Packages\GrpcCommon;

use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\StatusCode;

/**
 * The authenticated account propagated across an internal RPC boundary.
 *
 * The account id is accepted only from gateway-authenticated metadata. Body
 * fields are never used to construct this value.
 */
final readonly class BusinessActor
{
    public function __construct(
        public string $userId,
        public ?string $personaId = null,
    ) {
        if (!self::isUint64($this->userId)) {
            throw GRPCException::create('authenticated user id is invalid', StatusCode::UNAUTHENTICATED);
        }
        if ($this->personaId !== null && !self::isUint64($this->personaId)) {
            throw GRPCException::create('authenticated persona id is invalid', StatusCode::UNAUTHENTICATED);
        }
    }

    /** @throws GRPCException */
    public static function fromContext(ContextInterface $ctx): self
    {
        $values = $ctx->getValues();

        return self::fromMetadata(
            self::value($values, 'x-user-id'),
            self::value($values, 'x-persona-id'),
        );
    }

    /** @throws GRPCException */
    public static function fromMetadata(?string $userId, ?string $personaId = null): self
    {
        $userId = $userId !== null ? trim($userId) : '';
        $personaId = $personaId !== null ? trim($personaId) : null;

        if ($userId === '' || !self::isUint64($userId)) {
            throw GRPCException::create('authenticated user metadata is required', StatusCode::UNAUTHENTICATED);
        }
        if ($personaId === '') {
            $personaId = null;
        }

        return new self($userId, $personaId);
    }

    /** @return array<string, array<string>> */
    public function metadata(): array
    {
        $metadata = ['x-user-id' => [$this->userId]];
        if ($this->personaId !== null) {
            $metadata['x-persona-id'] = [$this->personaId];
        }

        return $metadata;
    }

    private static function value(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;
        if ($value === null && is_array($values['metadata'] ?? null)) {
            $value = $values['metadata'][$key] ?? null;
        }
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_scalar($value) ? (string)$value : null;
    }

    private static function isUint64(string $value): bool
    {
        return preg_match('/\A[1-9][0-9]*\z/', $value) === 1
            && strlen($value) <= 20
            && (strlen($value) < 20 || strcmp($value, '18446744073709551615') <= 0);
    }
}
