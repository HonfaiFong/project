<?php

declare(strict_types=1);

namespace Services\UserService\Infrastructure\Model;

use Illuminate\Database\Eloquent\Model;
use Services\UserService\Domain\Entity\Persona;

/** Eloquent model for the personas table. */
final class PersonaModel extends Model
{
    protected $table = 'personas';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    public function toDomain(): Persona
    {
        $row = $this->getAttributes();

        return new Persona(
            id: (string)$row['id'],
            userId: (string)$row['user_id'],
            type: (int)$row['type'],
            nickname: (string)$row['nickname'],
            avatarKey: $row['avatar_key'] !== null ? (string)$row['avatar_key'] : null,
            bio: $row['bio'] !== null ? (string)$row['bio'] : null,
            status: (int)$row['status'],
            createdAt: (string)$row['created_at'],
            updatedAt: (string)$row['updated_at'],
            isInternalAlias: (bool)($row['is_internal_alias'] ?? false),
            deletedAt: isset($row['deleted_at']) ? (string)$row['deleted_at'] : null,
        );
    }
}
