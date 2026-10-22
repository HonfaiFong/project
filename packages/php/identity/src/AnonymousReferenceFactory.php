<?php

declare(strict_types=1);

namespace Packages\Identity;

use JsonException;
use RuntimeException;

final readonly class AnonymousReferenceFactory
{
    public function __construct(private string $key)
    {
        if (strlen($this->key) < 32) {
            throw new RuntimeException('Anonymous identity key must be at least 32 bytes');
        }
    }

    public function forAlias(string $personaId, string $scopeType, string $scopeId): string
    {
        foreach ([$personaId, $scopeType, $scopeId] as $value) {
            if (blank($value) || strlen($value) > 255) {
                throw new RuntimeException('Anonymous identity scope is invalid');
            }
        }

        try {
            $payload = json_encode(
                ['persona' => $personaId, 'scope_type' => $scopeType, 'scope_id' => $scopeId],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Anonymous identity scope is invalid', 0, $e);
        }

        return 'anon_' . rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $this->key, true)), '+/', '-_'), '=');
    }

    public function isOpaqueReference(string $value): bool
    {
        return preg_match('/\Aanon_[A-Za-z0-9_-]{1,120}\z/', $value) === 1;
    }
}
