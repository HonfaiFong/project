<?php

declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

\Packages\Observability\MetricsHttpServer::serve((int)$argv[2], $argv[3], (int)$argv[1]);
