<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Http;

use Random\RandomException;
use Apps\ApiGateway\Response\ApiResponse;
use Contracts\Errors\ErrorCode;
use Packages\Tracing\TraceContext;
use Throwable;
use Webman\Exception\ExceptionHandler;
use Webman\Http\Request;
use Webman\Http\Response;

final class GatewayExceptionHandler extends ExceptionHandler
{
    public function report(Throwable $exception): void
    {
        $request = request();
        $this->logger->error('HTTP request failed', [
            'exception' => $exception::class,
            'path' => $request?->path(),
            'method' => $request?->method(),
        ]);
    }

    /** @throws RandomException */
    public function render(Request $request, Throwable $exception): Response
    {
        // PDOException::getCode() 可能返回 SQLSTATE 字符串（如 'HY000'），(int) 归一化为 0，并非冗余转换。
        /** @noinspection PhpCastIsUnnecessaryInspection */
        $knownCode = $exception->getCode() > 0 ? ErrorCode::tryFrom((int)$exception->getCode()) : null;
        $errorCode = $knownCode && $knownCode !== ErrorCode::OK
            ? $knownCode
            : ErrorCode::INTERNAL_SERVER_ERROR;
        $message = $knownCode && $knownCode !== ErrorCode::OK
            ? $exception->getMessage()
            : ErrorCode::INTERNAL_SERVER_ERROR->message();
        $trace = $request instanceof GatewayRequest ? $request->traceContext : null;
        $trace ??= TraceContext::create(
            requestId: $request->header('x-request-id'),
            traceId: $request->header('x-trace-id'),
            deviceId: $request->header('x-device-id'),
            appVersion: $request->header('x-app-version'),
            traceparent: $request->header('traceparent')
        );

        return ApiResponse::error($errorCode->value, $message, [], null, $trace);
    }
}
