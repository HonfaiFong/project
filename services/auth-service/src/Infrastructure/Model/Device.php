<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * Persistence mapping for the `devices` table.
 *
 * Snowflake ids are strings and the repository writes created_at/updated_at
 * itself, so Eloquent must not cast dates or auto-increment the key.
 */
final class Device extends Model
{
    protected $table = 'devices';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}
