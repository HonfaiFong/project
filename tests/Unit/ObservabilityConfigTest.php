<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ObservabilityConfigTest extends TestCase
{
    public function testPrometheusConfigurationScrapesGatewayAndLoadsAlerts(): void
    {
        $config = $this->read('infra/observability/prometheus/prometheus.yml');
        self::assertStringContainsString('job_name: api-gateway', $config);
        self::assertStringContainsString('job_name: php-grpc-services', $config);
        self::assertStringContainsString(':9100', $config);
        self::assertStringContainsString('metrics_path: /metrics', $config);
        self::assertStringContainsString('/etc/prometheus/alerts.yml', $config);
        foreach (['auth-service', 'user-service', 'content-service', 'social-service', 'chat-service', 'message-service', 'user-outbox-relay'] as $service) {
            self::assertStringContainsString('"' . $service . ':9100"', $config);
        }

        $alerts = $this->read('infra/observability/prometheus/alerts.yml');
        foreach (['ApiGatewayHighErrorRate', 'GrpcDependencyFailures', 'RedisDependencyDown', 'OutboxBacklogGrowing', 'RabbitMqPublisherFailures'] as $alert) {
            self::assertStringContainsString('alert: ' . $alert, $alerts);
        }
    }

    public function testTempoAndCollectorHaveBothOtlpReceiversAndTempoStorage(): void
    {
        $tempo = $this->read('infra/observability/tempo/tempo.yaml');
        self::assertStringContainsString('http:', $tempo);
        self::assertStringContainsString('grpc:', $tempo);
        self::assertStringContainsString('backend: local', $tempo);

        $collector = $this->read('infra/observability/otel-collector.yaml');
        self::assertStringContainsString('otlp/tempo:', $collector);
        self::assertStringContainsString('exporters: [otlp/tempo]', $collector);
    }

    public function testComposeWiresObservabilityProfileAndPhpScrapePorts(): void
    {
        $compose = $this->read('compose.yaml');
        foreach (['otel-collector', 'tempo', 'prometheus', 'grafana'] as $service) {
            self::assertStringContainsString("  {$service}:\n", $compose);
        }
        self::assertSame(4, substr_count($compose, "    profiles:\n      - observability\n"));
        self::assertStringContainsString('      - observability', $compose);
        self::assertStringContainsString('otel/opentelemetry-collector-contrib:', $compose);
        self::assertStringContainsString('127.0.0.1}:4318:4318', $compose);
        self::assertStringContainsString('127.0.0.1}:9090:9090', $compose);
        self::assertStringContainsString('127.0.0.1}:3000:3000', $compose);
        self::assertStringContainsString('"8787:8787"', $compose);
        self::assertStringContainsString('"9100"', $compose);
        self::assertStringContainsString('OTEL_EXPORTER_OTLP_ENDPOINT=${OTEL_EXPORTER_OTLP_ENDPOINT:-}', $compose);
        self::assertStringContainsString('METRICS_ALLOWED_CIDRS=${METRICS_ALLOWED_CIDRS:-}', $compose);
        self::assertStringContainsString('./infra/observability/prometheus/prometheus.yml:/etc/prometheus/prometheus.yml:ro', $compose);
        self::assertStringContainsString('./infra/observability/otel-collector.yaml:/etc/otelcol-contrib/otel-collector.yaml:ro', $compose);
        self::assertStringContainsString('GF_SECURITY_ADMIN_PASSWORD: ${GRAFANA_ADMIN_PASSWORD:?Set GRAFANA_ADMIN_PASSWORD in .env}', $compose);
        $prometheusStart = strpos($compose, "  prometheus:\n");
        $grafanaStart = $prometheusStart === false ? false : strpos($compose, "  grafana:\n", $prometheusStart);
        self::assertIsInt($prometheusStart);
        self::assertIsInt($grafanaStart);
        $prometheusSection = substr($compose, $prometheusStart, $grafanaStart - $prometheusStart);
        self::assertStringNotContainsString('depends_on:', $prometheusSection);
        foreach (['api-gateway', 'auth-service', 'user-service', 'content-service', 'social-service', 'chat-service', 'message-service', 'user-outbox-relay'] as $service) {
            self::assertStringNotContainsString('      ' . $service . ':', $prometheusSection);
        }
        foreach (['auth-service', 'user-service', 'content-service', 'social-service', 'chat-service', 'message-service', 'api-gateway'] as $service) {
            self::assertStringContainsString('OTEL_SERVICE_NAME=' . $service, $compose);
        }
        self::assertStringContainsString('OTEL_SERVICE_NAME: user-outbox-relay', $compose);

        foreach (['.env.example', '.env.production.example'] as $envFile) {
            $env = $this->read($envFile);
            self::assertStringContainsString('OTEL_EXPORTER_OTLP_ENDPOINT=', $env);
            self::assertStringNotContainsString('OTEL_EXPORTER_OTLP_ENDPOINT=http://tempo:4317', $env);
        }
        self::assertStringContainsString('GRAFANA_ADMIN_PASSWORD=replace-with-random-grafana-admin-password', $this->read('.env.example'));
        self::assertStringContainsString('GRAFANA_ADMIN_PASSWORD=', $this->read('.env.production.example'));
    }

    public function testGrafanaDatasourceDashboardProvisioningIsComplete(): void
    {
        $datasources = $this->read('infra/observability/grafana/provisioning/datasources/datasource.yml');
        self::assertStringContainsString('uid: prometheus', $datasources);
        self::assertStringContainsString('uid: tempo', $datasources);

        $providers = $this->read('infra/observability/grafana/provisioning/dashboards/dashboard.yml');
        self::assertStringContainsString('type: file', $providers);
        self::assertStringContainsString('path: /etc/grafana/dashboards', $providers);

        $dashboard = $this->read('infra/observability/grafana/dashboards/backend-observability.json');
        $decoded = json_decode($dashboard, true);
        self::assertIsArray($decoded);
        self::assertSame('backend-red-use', $decoded['uid'] ?? null);
        self::assertStringContainsString('http_server_requests_total', $dashboard);
        self::assertStringContainsString('process_resident_memory_bytes', $dashboard);
        self::assertStringContainsString('outbox_backlog', $dashboard);
    }

    private function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        self::assertFileExists($path);

        return (string)file_get_contents($path);
    }
}
