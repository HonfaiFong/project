<?php

declare(strict_types=1);

namespace Services\UserService\Infrastructure\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Services\UserService\Domain\Entity\User;

/**
 * Eloquent model for the users table. Timestamps and incrementing stay off
 * because ids are Snowflake strings and the service writes UTC strings itself.
 */
final class UserModel extends Model
{
    use SoftDeletes;

    protected $table = 'users';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    public function toDomain(): User
    {
        $row = $this->getAttributes();

        return new User(
            id: (string)$row['id'],
            status: (int)$row['status'],
            phone: $row['phone'] ? (string)$row['phone'] : null,
            email: $row['email'] ? (string)$row['email'] : null,
            registerChannel: (string)($row['register_channel'] ?? 'default'),
            riskLevel: (int)($row['risk_level'] ?? 0),
            lastLoginAt: ($row['last_login_at'] ?? null) ? (string)$row['last_login_at'] : null,
            lastActiveAt: ($row['last_active_at'] ?? null) ? (string)$row['last_active_at'] : null,
            createdAt: (string)$row['created_at'],
            updatedAt: (string)$row['updated_at'],
        );
    }
}
