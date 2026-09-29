<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Http;

use Apps\ApiGateway\Middleware\TraceMiddleware;
use Apps\ApiGateway\Response\ApiResponse;
use Contracts\Errors\ErrorCode;
use Packages\Logging\JsonLogger;
use Packages\Tracing\TraceContext;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;

final class WebmanGateway
{
    public function __construct(private readonly JsonLogger $logger)
    {
    }

    /**
     * @param callable(array<string, mixed>, array<string, string>): array<string, mixed> $handler
     */
    public function handle(Request $request, callable $handler): Response
    {
        $headers = $this->headers($request);
        $trace = TraceMiddleware::process($headers);
        $body = (array)$request->post();

        try {
            $apiResponse = $handler($body, $headers);
        } catch (Throwable $e) {
            $this->logger->error('HTTP request failed', [
                'exception' => $e::class,
                'path'      => $request->path(),
                'method'    => $request->method(),
            ]);

            $code = $e->getCode() > 0
                ? (int)$e->getCode()
                : ErrorCode::INTERNAL_SERVER_ERROR->value;
            $apiResponse = ApiResponse::error($code, $e->getMessage());
        } finally {
            TraceContext::reset();
        }

        if (!isset($apiResponse['status'], $apiResponse['headers'], $apiResponse['body'])) {
            $apiResponse = ApiResponse::error(ErrorCode::INTERNAL_SERVER_ERROR->value, 'internal server error');
        }

        return new Response(
            (int)$apiResponse['status'],
            (array)$apiResponse['headers'],
            (string)$apiResponse['body']
        );
    }

    public function notFound(Request $request): Response
    {
        return $this->handle($request, static fn (): array => ApiResponse::error(
            ErrorCode::INVALID_ARGUMENT->value,
            'route not found',
            [],
            404
        ));
    }

    /**
     * @return array<string, string>
     */
    private function headers(Request $request): array
    {
        $headers = $request->header();
        if (!is_array($headers)) {
            return [];
        }

        $normalized = [];
        foreach ($headers as $name => $value) {
            if (is_scalar($value)) {
                $normalized[strtolower((string)$name)] = (string)$value;
            }
        }

        return $normalized;
    }
}
