<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Response;

final class PersonaPresenter
{
    /** @var array<int, string> */
    private const PUBLIC_FIELDS = [
        'id',
        'type',
        'nickname',
        'avatar_key',
        'bio',
        'status',
        'created_at',
        'updated_at',
    ];

    /**
     * @param mixed $persona
     * @return array<string, mixed>
     */
    public static function one(mixed $persona): array
    {
        if (!is_array($persona)) {
            return [];
        }

        // A public directory is MAIN-only. The type check is repeated here
        // because presenters are the final boundary even if a future RPC
        // accidentally returns an internal ALIAS profile.
        if ((int)($persona['type'] ?? 0) !== 1 || (string)($persona['identity_mode'] ?? '') === 'alias') {
            return [];
        }

        return array_intersect_key($persona, array_flip(self::PUBLIC_FIELDS));
    }

    /**
     * Private /me representation. It exposes that an anonymous mode exists,
     * but never treats the internal alias as a public profile.
     *
     * @param mixed $persona
     * @return array<string, mixed>
     */
    public static function privateOne(mixed $persona): array
    {
        if (!is_array($persona)) {
            return [];
        }

        $type = (int)($persona['type'] ?? 0);
        if ($type === 1) {
            // The account owner must still receive the MAIN profile fields.
            // The public whitelist is safe here because MAIN is the only
            // profile that may expose stable persona data.
            return array_intersect_key($persona, array_flip(self::PUBLIC_FIELDS)) + [
                'identity_mode' => 'main',
                'profile_accessible' => true,
            ];
        }

        if ($type === 2) {
            // ALIAS is a mode descriptor only. Never return its stable
            // database id or write-time nickname/avatar through this API.
            return [
                'type' => 2,
                'status' => (int)($persona['status'] ?? 0),
                'identity_mode' => 'alias',
                'profile_accessible' => false,
            ];
        }

        return [];
    }

    /**
     * @param mixed $personas
     * @return array<int, array<string, mixed>>
     */
    public static function many(mixed $personas): array
    {
        if (!is_array($personas)) {
            return [];
        }

        return array_map(static fn (mixed $persona): array => self::one($persona), $personas);
    }

    /** @param mixed $personas @return array<int, array<string, mixed>> */
    public static function privateMany(mixed $personas): array
    {
        if (!is_array($personas)) {
            return [];
        }

        return array_map(static fn (mixed $persona): array => self::privateOne($persona), $personas);
    }
}
