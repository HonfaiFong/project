<?php

declare(strict_types=1);

namespace Packages\Observability;

/**
 * Minimal OTLP/HTTP JSON exporter. The application does not need the full
 * OpenTelemetry SDK just to export the small server/client spans it creates.
 */
final class OtlpHttpExporter implements SpanExporter
{
    private readonly string $endpoint;
    private readonly float $timeoutSeconds;
    /** @var array<string, string> */
    private readonly array $headers;
    private ?AsyncOtlpTransport $transport = null;

    /** @param array<string, string> $headers */
    public function __construct(
        ?string $endpoint = null,
        ?float $timeoutSeconds = null,
        array $headers = [],
    ) {
        $endpoint ??= self::endpointFromEnvironment();
        $this->endpoint = rtrim(trim($endpoint), '/');
        $this->timeoutSeconds = max(0.01, $timeoutSeconds ?? self::floatEnvironment('OTEL_EXPORTER_OTLP_TIMEOUT', 0.25));
        $this->headers = $headers + self::headersFromEnvironment();
    }

    public function export(Span $span): void
    {
        if ($this->endpoint === '' || filter_var(getenv('OTEL_SDK_DISABLED') ?: 'false', FILTER_VALIDATE_BOOL)) {
            return;
        }
        if (!function_exists('curl_init')) {
            return;
        }

        $payload = json_encode([
            'resourceSpans' => [[
                'resource' => ['attributes' => $this->resourceAttributes()],
                'scopeSpans' => [[
                    'scope' => ['name' => 'social/backend-observability', 'version' => '1.0.0'],
                    'spans' => [$this->spanPayload($span)],
                ]],
            ]],
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }

        $headers = ['Content-Type: application/json'];
        foreach ($this->headers as $key => $value) {
            $headers[] = $key . ': ' . $value;
        }

        $message = json_encode([
            'endpoint' => $this->endpoint,
            'timeout' => $this->timeoutSeconds,
            'headers' => $headers,
            'payload' => $payload,
        ], JSON_UNESCAPED_SLASHES);
        if ($message !== false) {
            ($this->transport ??= new AsyncOtlpTransport())->send($message);
        }
    }

    public static function endpointFromEnvironment(): string
    {
        $endpoint = trim((string)(getenv('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT') ?: ''));
        if ($endpoint !== '') {
            return $endpoint;
        }

        $base = trim((string)(getenv('OTEL_EXPORTER_OTLP_ENDPOINT') ?: ''));
        if ($base !== '') {
            return rtrim($base, '/') . '/v1/traces';
        }

        return '';
    }

    /** @return array<string, string> */
    private static function headersFromEnvironment(): array
    {
        $headers = [];
        $raw = trim((string)(getenv('OTEL_EXPORTER_OTLP_HEADERS') ?: ''));
        if ($raw === '') {
            return $headers;
        }
        foreach (explode(',', $raw) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if (trim($key) !== '') {
                $headers[trim($key)] = trim($value);
            }
        }

        return $headers;
    }

    /** @return list<array{key:string,value:array<string, string|bool|int|float>}> */
    private function resourceAttributes(): array
    {
        $service = trim((string)(getenv('OTEL_SERVICE_NAME') ?: getenv('SERVICE_NAME') ?: 'unknown-service'));
        $instance = trim((string)(getenv('OTEL_SERVICE_INSTANCE_ID') ?: getenv('SERVICE_INSTANCE_ID') ?: getenv('HOSTNAME') ?: 'unknown'));
        $environment = trim((string)(getenv('OTEL_DEPLOYMENT_ENVIRONMENT') ?: getenv('APP_ENV') ?: 'production'));
        $attributes = [
            $this->stringAttribute('service.name', $service),
            $this->stringAttribute('service.instance.id', $instance),
            $this->stringAttribute('deployment.environment', $environment),
        ];
        $raw = trim((string)(getenv('OTEL_RESOURCE_ATTRIBUTES') ?: ''));
        foreach (explode(',', $raw) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if (trim($key) !== '' && trim($value) !== '') {
                $attributes[] = $this->stringAttribute(trim($key), trim($value));
            }
        }

        return $attributes;
    }

    /** @return array{key:string,value:array<string, string>} */
    private function stringAttribute(string $key, string $value): array
    {
        return ['key' => $key, 'value' => ['stringValue' => $value]];
    }

    /** @return array<string, mixed> */
    private function spanPayload(Span $span): array
    {
        $payload = [
            'traceId' => $span->traceId(),
            'spanId' => $span->spanId(),
            'name' => $span->name(),
            'kind' => $span->kind(),
            'startTimeUnixNano' => (string)$span->startTimeUnixNano(),
            'endTimeUnixNano' => (string)$span->endTimeUnixNano(),
            'attributes' => [],
            'status' => ['code' => $span->statusCode()],
        ];
        if ($span->parentSpanId() !== null) {
            $payload['parentSpanId'] = $span->parentSpanId();
        }
        if ($span->statusMessage() !== null) {
            $payload['status']['message'] = $span->statusMessage();
        }
        foreach ($span->attributes() as $key => $value) {
            if ($value === null) {
                continue;
            }
            $typed = is_bool($value)
                ? ['boolValue' => $value]
                : (is_int($value) ? ['intValue' => (string)$value] : (is_float($value) ? ['doubleValue' => $value] : ['stringValue' => (string)$value]));
            $payload['attributes'][] = ['key' => $key, 'value' => $typed];
        }

        return $payload;
    }

    private static function floatEnvironment(string $name, float $default): float
    {
        $raw = trim((string)(getenv($name) ?: ''));

        return $raw !== '' && is_numeric($raw) ? (float)$raw : $default;
    }
}
