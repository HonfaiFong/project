<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Polyfill Grpc status constants when ext-grpc is not loaded in local environment
if (!defined('Grpc\STATUS_OK')) {
    define('Grpc\STATUS_OK', 0);
    define('Grpc\STATUS_CANCELLED', 1);
    define('Grpc\STATUS_UNKNOWN', 2);
    define('Grpc\STATUS_INVALID_ARGUMENT', 3);
    define('Grpc\STATUS_DEADLINE_EXCEEDED', 4);
    define('Grpc\STATUS_NOT_FOUND', 5);
    define('Grpc\STATUS_ALREADY_EXISTS', 6);
    define('Grpc\STATUS_PERMISSION_DENIED', 7);
    define('Grpc\STATUS_RESOURCE_EXHAUSTED', 8);
    define('Grpc\STATUS_FAILED_PRECONDITION', 9);
    define('Grpc\STATUS_ABORTED', 10);
    define('Grpc\STATUS_OUT_OF_RANGE', 11);
    define('Grpc\STATUS_UNIMPLEMENTED', 12);
    define('Grpc\STATUS_INTERNAL', 13);
    define('Grpc\STATUS_UNAVAILABLE', 14);
    define('Grpc\STATUS_DATA_LOSS', 15);
    define('Grpc\STATUS_UNAUTHENTICATED', 16);
}
