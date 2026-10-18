# Optional tracing and Worker ID leases

Tracing is off by default. To enable the bundled collector, set
`OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4318` and start Compose with
`--profile observability`. A custom collector URL is also supported. Merely
enabling the profile does not enable application tracing.

PHP spans use a separate exporter process on Linux (`proc_open`, POSIX and Unix
datagram sockets required). Request handlers only enqueue a bounded datagram;
DNS and HTTP run in the exporter. A full queue, oversized span (60 KB encoded),
unsupported runtime, or unavailable exporter drops telemetry. There is no
synchronous fallback or guaranteed flush at process exit. Collector failures
back off for one second, then subsequent spans retry. Exporter children stop
when their parent exits. Windows tracing remains propagation-only.

The six PHP gRPC services maintain their Redis Worker ID leases while waiting
on RoadRunner's relay, including when no HTTP health probes or business calls
arrive. The chat message consumer also maintains its lease during idle queue
waits. Renewal still compares the owner token atomically. Ownership loss never
reclaims the old lease in place: the relay exits so RoadRunner creates a new
worker, connections, and lease. The consumer exits for its container supervisor
to restart it. Readiness explicitly renews registered leases and reports
NOT_SERVING when Redis or ownership validation fails.

The default 30-second TTL and existing RPC deadlines are unchanged. A stopped
or suspended process can still lose its lease; it refuses to issue IDs and is
replaced after resuming. Redis remains a required dependency for ID generation.
