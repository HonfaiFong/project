<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * `devices` 表的持久化映射。
 *
 * Snowflake ID 使用字符串，created_at/updated_at 由仓储自行写入，因此 Eloquent 不得转换日期或自动递增主键。
 */
final class Device extends Model
{
    protected $table = 'devices';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}
