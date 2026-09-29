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

        return array_intersect_key($persona, array_flip(self::PUBLIC_FIELDS));
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
}
