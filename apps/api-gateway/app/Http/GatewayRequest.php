<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Http;

use Packages\Tracing\TraceContext;
use Webman\Http\Request;

final class GatewayRequest extends Request
{
    public ?TraceContext $traceContext = null;

    /** @var array{user_id: string, device_id: string, requested_persona_id: ?string} */
    public array $authContext = [
        'user_id' => '',
        'device_id' => '',
        'requested_persona_id' => null,
    ];
}
