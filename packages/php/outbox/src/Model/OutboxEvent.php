<?php

declare(strict_types=1);

namespace Packages\Outbox\Model;

use Illuminate\Database\Eloquent\Model;

final class OutboxEvent extends Model
{
    protected $table = 'outbox_events';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'event_version' => 'integer',
        'status' => 'integer',
        'attempt_count' => 'integer',
    ];
}
