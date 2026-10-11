# PHP observability stack

This directory contains the deployable observability pieces for the current
monorepo. Application code emits real OTLP/HTTP spans when
`OTEL_EXPORTER_OTLP_TRACES_ENDPOINT` (or the base
`OTEL_EXPORTER_OTLP_ENDPOINT`) is set. The API gateway exposes Prometheus
text at `GET /metrics` on port `8787`.

The PHP instrumentation covers:

- HTTP server spans and RED metrics in the API gateway;
- gRPC client spans/RED metrics in `UnaryGrpcClient`;
- gRPC server spans/RED metrics in the six RoadRunner transport wrappers;
- external OAuth HTTP client spans;
- process memory, CPU, open-file and in-flight gauges;
- dependency health gauges and Redis rate-limit operation failures;
- outbox backlog/claims/publication failures and RabbitMQ publisher latency.

The RoadRunner-only services start a small read-only metrics listener on the
internal port `9100`; the listener aggregates per-worker snapshots from the
shared temporary directory. Docker does not need a host port mapping for
Prometheus to reach these service ports. The current compose stack still has
no RabbitMQ Prometheus plugin enabled.

Override the listener with `OBSERVABILITY_METRICS_PORT` and the snapshot
directory with `OBSERVABILITY_METRICS_DIR` when a platform requires a different
port or writable temporary mount. The listener removes snapshots whose worker
PID is no longer alive in the same container. On platforms without `/proc` or
`posix_kill`, set `OBSERVABILITY_METRICS_SNAPSHOT_MAX_AGE_SECONDS` to control
the age-based cleanup fallback (the default is 3600 seconds).
RabbitMQ consumer delay is not claimed here because this repository currently
has a publisher/outbox relay but no consumer process. Do not add an alert for
that signal until a consumer and a reliable age metric exist.

## Run locally

1. Copy `.env.example` to `.env`, fill the required local secrets, and start
   the complete stack with `docker compose --profile observability up -d`.
   The profile starts Collector (`4318` HTTP), Tempo (`3200`), Prometheus
   (`9090`) and Grafana (`3000`) with the mounted configuration and named
   data volumes.
2. The PHP services receive
   `OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4318` by default. The
   exporter appends `/v1/traces`; override the base endpoint only when using a
   different OTLP/HTTP collector.
3. Grafana provisions the Prometheus and Tempo datasources plus the
   `Backend RED / USE` dashboard automatically.

Example checks:

```text
curl http://localhost:8787/metrics
curl -X POST http://localhost:8787/api/v1/auth/refresh
```

The second request should produce an HTTP server span and a gRPC client span
when the upstream is reachable. Export failures are fail-open and never turn
business requests into 5xx responses.
