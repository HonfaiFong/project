<?php

declare(strict_types=1);

use Monolog\Handler\StreamHandler;

return [
    'default' => [
        'handlers' => [
            ['class' => StreamHandler::class, 'constructor' => ['stream' => 'php://stdout']],
        ],
    ],
];
