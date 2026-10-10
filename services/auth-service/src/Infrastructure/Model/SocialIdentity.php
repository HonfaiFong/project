<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Model;

use Illuminate\Database\Eloquent\Model;

/** `social_identities` 表的持久化映射。 */
final class SocialIdentity extends Model
{
    protected $table = 'social_identities';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}
