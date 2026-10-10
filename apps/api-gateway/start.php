#!/usr/bin/env php
<?php

declare(strict_types=1);

chdir(__DIR__);
const BASE_PATH = __DIR__;
require_once __DIR__ . '/../../vendor/autoload.php';

// CLI 入口：启动失败时让异常冒泡到 PHP fatal handler（输出堆栈、非零退出码），不做局部捕获。
/** @noinspection PhpUnhandledExceptionInspection */
support\App::run();
