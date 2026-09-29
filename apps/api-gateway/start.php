#!/usr/bin/env php
<?php

declare(strict_types=1);

chdir(__DIR__);
define('BASE_PATH', __DIR__);
require_once __DIR__ . '/../../vendor/autoload.php';

support\App::run();
