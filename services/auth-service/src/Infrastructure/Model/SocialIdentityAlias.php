<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Model;

use Illuminate\Database\Eloquent\Model;

/** `social_identity_aliases` 表的持久化映射。 */
final class SocialIdentityAlias extends Model
{
    protected $table = 'social_identity_aliases';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}
