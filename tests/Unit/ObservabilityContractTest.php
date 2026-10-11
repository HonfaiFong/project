<?php

declare(strict_types=1);

namespace Tests\Unit;

use Packages\Observability\MetricsRegistry;
use Packages\Observability\NoopSpanExporter;
use Packages\Observability\OtlpHttpExporter;
use Packages\Observability\Span;
use Packages\Observability\SpanExporter;
use Packages\Observability\Tracer;
use Packages\Tracing\TraceContext;
use PHPUnit\Framework\TestCase;

final class ObservabilityContractTest extends TestCase
{
    protected function tearDown(): void
    {
        TraceContext::reset();
        Tracer::resetExporter();
        MetricsRegistry::reset();
        MetricsRegistry::setPersistenceDirectory(null);
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT');
        putenv('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT');
        putenv('OTEL_SDK_DISABLED');
        putenv('SERVICE_NAME');
        putenv('OBSERVABILITY_METRICS_SNAPSHOT_MAX_AGE_SECONDS');
    }

    public function testNestedSpanKeepsTraceAndPropagatesAChildParent(): void
    {
        $exporter = new RecordingSpanExporter();
        Tracer::setExporter($exporter);
        $root = TraceContext::create(
            requestId: 'request-1',
            traceId: '0123456789abcdef0123456789abcdef',
            traceparent: '00-0123456789abcdef0123456789abcdef-1111111111111111-01',
        );
        TraceContext::setCurrent($root);

        $scope = Tracer::enter('grpc.client', Span::KIND_CLIENT, [
            'rpc.system' => 'grpc',
            'rpc.method' => '/user.v1.UserService/GetUser',
            'server.address' => 'user-service:50052',
        ]);
        $child = TraceContext::current();
        $scope->close();

        self::assertSame($root->traceId, $exporter->spans[0]->traceId());
        self::assertSame('1111111111111111', $exporter->spans[0]->parentSpanId());
        self::assertNotSame($root->traceparent, $child->traceparent);
        self::assertSame($root->traceparent, TraceContext::current()->traceparent);
    }

    public function testValidIncomingTraceparentSuppliesTraceIdWhenLegacyHeaderIsMissing(): void
    {
        $context = TraceContext::create(
            requestId: 'request-2',
            traceparent: '00-abcdefabcdefabcdefabcdefabcdefab-2222222222222222-01',
        );

        self::assertSame('abcdefabcdefabcdefabcdefabcdefab', $context->traceId);
        self::assertSame('00-abcdefabcdefabcdefabcdefabcdefab-2222222222222222-01', $context->traceparent);
    }

    public function testSpanTimesUseUnixEpochAndMonotonicEndOrdering(): void
    {
        $exporter = new RecordingSpanExporter();
        Tracer::setExporter($exporter);
        TraceContext::setCurrent(TraceContext::create(requestId: 'request-time'));
        $before = (int)round(microtime(true) * 1_000_000_000);
        $scope = Tracer::enter('http.server', Span::KIND_SERVER);
        usleep(1000);
        $scope->close();
        $after = (int)round(microtime(true) * 1_000_000_000);
        $span = $exporter->spans[0];

        self::assertGreaterThanOrEqual($before - 1_000_000_000, $span->startTimeUnixNano());
        self::assertLessThanOrEqual($after + 1_000_000_000, $span->endTimeUnixNano());
        self::assertGreaterThanOrEqual($span->startTimeUnixNano(), $span->endTimeUnixNano());
        self::assertGreaterThanOrEqual($span->startTimeNs(), $span->endTimeNs());
    }

    public function testPrometheusRegistryRendersRedAndUseSeries(): void
    {
        putenv('SERVICE_NAME=unit-test');
        MetricsRegistry::reset();
        $registry = MetricsRegistry::instance();
        $registry->inc('http_server_requests_total', ['method' => 'GET', 'route' => '/health', 'status' => '200']);
        $registry->observe('http_server_request_duration_seconds', 0.02, ['method' => 'GET', 'route' => '/health', 'status' => '200']);
        $registry->set('dependency_up', ['dependency' => 'redis'], 0);

        $rendered = $registry->render();
        self::assertStringContainsString('# TYPE http_server_requests_total counter', $rendered);
        self::assertStringContainsString('http_server_requests_total{method="GET",route="/health",service="unit-test",status="200"} 1', $rendered);
        self::assertStringContainsString('http_server_request_duration_seconds_bucket', $rendered);
        self::assertStringContainsString('http_server_request_duration_seconds_bucket{le="+Inf",method="GET",route="/health",service="unit-test",status="200"} 1', $rendered);
        self::assertStringContainsString('http_server_request_duration_seconds_sum', $rendered);
        self::assertStringContainsString('http_server_request_duration_seconds_count{method="GET",route="/health",service="unit-test",status="200"} 1', $rendered);
        preg_match('/http_server_request_duration_seconds_bucket\{le="\+Inf"[^}]*\} ([0-9.]+)/', $rendered, $infinityBucket);
        preg_match('/http_server_request_duration_seconds_count\{[^}]*\} ([0-9.]+)/', $rendered, $histogramCount);
        self::assertSame($histogramCount[1] ?? null, $infinityBucket[1] ?? null);
        self::assertStringContainsString('process_resident_memory_bytes', $rendered);
        self::assertStringContainsString('dependency_up{dependency="redis",service="unit-test"} 0', $rendered);
    }

    public function testOtlpEndpointUsesTraceSpecificEndpointThenBaseEndpoint(): void
    {
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT=http://collector:4318');
        self::assertSame('http://collector:4318/v1/traces', OtlpHttpExporter::endpointFromEnvironment());
        putenv('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=http://tempo:4318/v1/traces');
        self::assertSame('http://tempo:4318/v1/traces', OtlpHttpExporter::endpointFromEnvironment());
    }

    public function testPersistedSnapshotsCanBeScrapedAcrossWorkerProcesses(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'observability-test-' . bin2hex(random_bytes(4));
        mkdir($directory, 0777, true);
        try {
            putenv('SERVICE_NAME=worker-a');
            MetricsRegistry::setPersistenceDirectory($directory);
            $registry = MetricsRegistry::instance();
            $registry->inc('grpc_server_requests_total', ['method' => 'Check', 'target' => 'health', 'status' => 'ok']);
            $registry->persistNow();

            $rendered = MetricsRegistry::renderPersisted($directory);
            self::assertStringContainsString('grpc_server_requests_total', $rendered);
            self::assertStringContainsString('service="worker-a"', $rendered);
        } finally {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }

    public function testStalePersistedSnapshotsAreRemovedAfterWorkerExit(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'observability-stale-test-' . bin2hex(random_bytes(4));
        mkdir($directory, 0777, true);
        $staleFile = $directory . DIRECTORY_SEPARATOR . 'worker-stale.999999999.json';
        try {
            file_put_contents($staleFile, (string)json_encode([
                'writtenAtUnix' => microtime(true) - 10,
                'metrics' => [],
            ]));

            MetricsRegistry::renderPersisted($directory, 1);

            self::assertFileDoesNotExist($staleFile);
        } finally {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }
}

final class RecordingSpanExporter implements SpanExporter
{
    /** @var list<Span> */
    public array $spans = [];

    public function export(Span $span): void
    {
        $this->spans[] = $span;
    }
}
