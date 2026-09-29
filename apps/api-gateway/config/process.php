<?php

declare(strict_types=1);

use Apps\ApiGateway\Process\Http;
use Apps\ApiGateway\Http\GatewayRequest;
use support\Log;

return [
    'webman' => [
        'handler' => Http::class,
        'listen' => 'http://0.0.0.0:' . (getenv('HTTP_PORT') ?: '8787'),
        'count' => max(1, (int)(getenv('WEBMAN_WORKERS') ?: 1)),
        'user' => '',
        'group' => '',
        'reusePort' => false,
        'constructor' => [
            'requestClass' => GatewayRequest::class,
            'logger' => Log::channel('default'),
            'appPath' => app_path(),
            'publicPath' => public_path(),
        ],
    ],
];
