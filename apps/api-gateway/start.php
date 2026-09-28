#!/usr/bin/env php
<?php

declare(strict_types=1);

// Keep the Webman application base local to this gateway. The monorepo root
// remains the Composer project and is only used to load its dependencies.
define('BASE_PATH', __DIR__);
require_once __DIR__ . '/../../vendor/autoload.php';

use Psr\Log\NullLogger;
use Webman\App;
use Webman\Http\Request;
use Workerman\Worker;

$port = (int)(getenv('HTTP_PORT') ?: 8787);
$worker = new Worker("http://0.0.0.0:{$port}");
$worker->name = 'api-gateway';
$worker->count = max(1, (int)(getenv('WEBMAN_WORKERS') ?: 1));

// Load Webman route and middleware definitions before workers start. This is
// the framework bootstrap path while avoiding a dependency on Monolog, which
// is not part of this monorepo's runtime requirements.
require_once __DIR__ . '/../../vendor/workerman/webman-framework/src/support/bootstrap.php';

$app = new App(Request::class, new NullLogger(), __DIR__ . '/app', __DIR__ . '/public');
$worker->onWorkerStart = static function (Worker $worker) use ($app): void {
    $app->onWorkerStart($worker);
    $worker->onMessage = [$app, 'onMessage'];
};

Worker::runAll();
