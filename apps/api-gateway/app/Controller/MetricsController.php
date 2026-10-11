<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Controller;

use Packages\Observability\MetricsRegistry;
use Webman\Http\Response;

final readonly class MetricsController
{
    public function metrics(): Response
    {
        return new Response(
            200,
            ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8'],
            MetricsRegistry::instance()->render(),
        );
    }
}
