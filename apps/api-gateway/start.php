<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Apps\ApiGateway\Controller\AuthController;
use Apps\ApiGateway\Controller\HealthController;
use Apps\ApiGateway\Controller\PersonaController;
use Apps\ApiGateway\Controller\UserController;
use Apps\ApiGateway\Grpc\AuthServiceClient;
use Apps\ApiGateway\Grpc\UserServiceClient;
use Apps\ApiGateway\Middleware\AuthMiddleware;
use Apps\ApiGateway\Middleware\TraceMiddleware;
use Apps\ApiGateway\Response\ApiResponse;
use Contracts\Errors\ErrorCode;
use Packages\Logging\JsonLogger;
use Packages\Tracing\TraceContext;

$logger = new JsonLogger('api-gateway', getenv('SERVICE_INSTANCE_ID') ?: 'gw-01');

$authClient = new AuthServiceClient(getenv('GRPC_AUTH_SERVICE') ?: 'auth-service:50051');
$userClient = new UserServiceClient(getenv('GRPC_USER_SERVICE') ?: 'user-service:50052');

$authController = new AuthController($authClient, $userClient);
$userController = new UserController($userClient);
$personaController = new PersonaController($userClient);
$healthController = new HealthController($authClient, $userClient);
$authMiddleware = new AuthMiddleware($authClient);

$port = (int)(getenv('HTTP_PORT') ?: 8787);
$server = stream_socket_server("tcp://0.0.0.0:{$port}", $errno, $errstr);

if (!$server) {
    $logger->error("Failed to start API Gateway on port {$port}: {$errstr} ({$errno})");
    exit(1);
}

$logger->info("API Gateway started and listening on http://0.0.0.0:{$port}");

while ($conn = @stream_socket_accept($server, -1)) {
    $rawHeaders = '';
    while (!str_contains($rawHeaders, "\r\n\r\n")) {
        $chunk = fread($conn, 2048);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $rawHeaders .= $chunk;
    }

    if ($rawHeaders === '') {
        fclose($conn);
        continue;
    }

    $headerEndPos = strpos($rawHeaders, "\r\n\r\n");
    $headersStr = substr($rawHeaders, 0, $headerEndPos);
    $initialBody = substr($rawHeaders, $headerEndPos + 4);

    $headerLines = explode("\r\n", $headersStr);
    $requestLine = array_shift($headerLines) ?? '';
    $parts = explode(' ', $requestLine);
    $method = strtoupper($parts[0] ?? 'GET');
    $uri = $parts[1] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?? '/';

    $headers = [];
    foreach ($headerLines as $line) {
        $colonPos = strpos($line, ':');
        if ($colonPos !== false) {
            $k = trim(substr($line, 0, $colonPos));
            $v = trim(substr($line, $colonPos + 1));
            $headers[strtolower($k)] = $v;
        }
    }

    // Read remaining body if Content-Length specified
    $contentLength = (int)($headers['content-length'] ?? 0);
    $body = $initialBody;
    while (strlen($body) < $contentLength) {
        $need = $contentLength - strlen($body);
        $chunk = fread($conn, min($need, 4096));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $body .= $chunk;
    }

    // Initialize Request Trace Context
    $trace = TraceMiddleware::process($headers);

    $jsonBody = [];
    if (!empty($body)) {
        $jsonBody = json_decode($body, true) ?? [];
    }

    $logger->info("Incoming HTTP {$method} {$path}", [
        'method'     => $method,
        'path'       => $path,
        'request_id' => $trace->requestId,
    ]);

    $response = null;

    try {
        if ($method === 'GET' && ($path === '/health' || $path === '/api/v1/health')) {
            $response = $healthController->check();
        } elseif ($method === 'POST' && $path === '/api/v1/auth/login') {
            $response = $authController->login($jsonBody, $headers);
        } elseif ($method === 'POST' && $path === '/api/v1/auth/refresh') {
            $response = $authController->refresh($jsonBody);
        } elseif ($method === 'POST' && $path === '/api/v1/auth/logout') {
            $response = $authController->logout($jsonBody);
        } elseif ($method === 'POST' && $path === '/api/v1/ws/ticket') {
            $auth = $authMiddleware->authenticate($headers);
            $response = $authController->createWsTicket($jsonBody, $auth);
        } elseif ($method === 'GET' && $path === '/api/v1/me') {
            $auth = $authMiddleware->authenticate($headers);
            $response = $userController->me($auth);
        } elseif ($method === 'GET' && $path === '/api/v1/personas') {
            $auth = $authMiddleware->authenticate($headers);
            $response = $personaController->list($auth);
        } elseif ($method === 'POST' && $path === '/api/v1/personas') {
            $auth = $authMiddleware->authenticate($headers);
            $response = $personaController->create($jsonBody, $auth);
        } elseif ($method === 'GET' && preg_match('#^/api/v1/personas/([0-9]+)$#', $path, $matches)) {
            $auth = $authMiddleware->authenticate($headers);
            $response = $personaController->get($matches[1]);
        } elseif ($method === 'PATCH' && preg_match('#^/api/v1/personas/([0-9]+)$#', $path, $matches)) {
            $auth = $authMiddleware->authenticate($headers);
            $response = $personaController->update($matches[1], $jsonBody, $auth);
        } else {
            $response = ApiResponse::error(
                ErrorCode::INVALID_ARGUMENT->value,
                "Route not found: {$method} {$path}",
                [],
                404
            );
        }
    } catch (\Throwable $e) {
        $logger->error("HTTP Request Error: " . $e->getMessage(), [
            'exception' => $e::class,
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ]);
        $code = $e->getCode() > 0 ? (int)$e->getCode() : ErrorCode::INTERNAL_SERVER_ERROR->value;
        $response = ApiResponse::error($code, $e->getMessage());
    } finally {
        TraceContext::reset();
    }

    $status = $response['status'] ?? 200;
    $statusText = match ($status) {
        200 => 'OK',
        201 => 'Created',
        204 => 'No Content',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        409 => 'Conflict',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        default => 'Internal Server Error',
    };

    $outBody = $response['body'] ?? '{}';
    $outHeaders = array_merge([
        'Content-Type'   => 'application/json',
        'Content-Length' => (string)strlen($outBody),
        'Connection'     => 'close',
    ], $response['headers'] ?? []);

    $rawOut = "HTTP/1.1 {$status} {$statusText}\r\n";
    foreach ($outHeaders as $k => $v) {
        $rawOut .= "{$k}: {$v}\r\n";
    }
    $rawOut .= "\r\n" . $outBody;

    fwrite($conn, $rawOut);
    fclose($conn);
}
