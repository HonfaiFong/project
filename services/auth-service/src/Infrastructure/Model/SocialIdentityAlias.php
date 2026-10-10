<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Model;

use Illuminate\Database\Eloquent\Model;

/** Persistence mapping for the `social_identity_aliases` table. */
final class SocialIdentityAlias extends Model
{
    protected $table = 'social_identity_aliases';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}
