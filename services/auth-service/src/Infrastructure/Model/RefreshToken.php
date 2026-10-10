<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Model;

use Illuminate\Database\Eloquent\Model;

/** `refresh_tokens` 表的持久化映射。 */
final class RefreshToken extends Model
{
    protected $table = 'refresh_tokens';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}
