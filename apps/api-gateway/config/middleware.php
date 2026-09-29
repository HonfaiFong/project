<?php

declare(strict_types=1);

use Apps\ApiGateway\Middleware\TraceMiddleware;

return [
    '' => [TraceMiddleware::class],
];
