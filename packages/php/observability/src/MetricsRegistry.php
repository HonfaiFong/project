<?php

declare(strict_types=1);

namespace Packages\Observability;

final class MetricsRegistry
{
    private static ?self $instance = null;
    private static ?string $persistenceDirectory = null;
    private readonly float $startedAt;
    private bool $persisting = false;
    /** @var array<string, array{type:string, help:string, values:array<string, array{labels:array<string,string>,value:float}>}> */
    private array $metrics = [];

    private function __construct()
    {
        $this->startedAt = microtime(true);
        $this->register('process_uptime_seconds', 'gauge', 'Process uptime in seconds.');
        $this->register('process_resident_memory_bytes', 'gauge', 'PHP process memory allocated in bytes.');
        $this->register('process_open_fds', 'gauge', 'Open file descriptors when available.');
        $this->register('process_cpu_seconds_total', 'counter', 'CPU seconds consumed when getrusage is available.');
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public static function setPersistenceDirectory(?string $directory): void
    {
        self::$persistenceDirectory = $directory !== null && trim($directory) !== '' ? trim($directory) : null;
    }

    public function register(string $name, string $type, string $help): void
    {
        if (!isset($this->metrics[$name])) {
            $this->metrics[$name] = ['type' => $type, 'help' => $help, 'values' => []];
        }
    }

    /** @param array<string, string|int|float|bool> $labels */
    public function inc(string $name, array $labels = [], float $value = 1.0): void
    {
        $this->register($name, 'counter', $name);
        $key = $this->labelKey($labels);
        $this->metrics[$name]['values'][$key] ??= ['labels' => $this->normalizeLabels($labels), 'value' => 0.0];
        $this->metrics[$name]['values'][$key]['value'] += $value;
        $this->persist();
    }

    /** @param array<string, string|int|float|bool> $labels */
    public function set(string $name, array $labels, float $value): void
    {
        $this->register($name, 'gauge', $name);
        $key = $this->labelKey($labels);
        $this->metrics[$name]['values'][$key] = ['labels' => $this->normalizeLabels($labels), 'value' => $value];
        $this->persist();
    }

    /** @param array<string, string|int|float|bool> $labels */
    public function addGauge(string $name, array $labels, float $value): void
    {
        $this->register($name, 'gauge', $name);
        $key = $this->labelKey($labels);
        $this->metrics[$name]['values'][$key] ??= ['labels' => $this->normalizeLabels($labels), 'value' => 0.0];
        $this->metrics[$name]['values'][$key]['value'] += $value;
        $this->persist();
    }

    /** @param array<string, string|int|float|bool> $labels */
    public function observe(string $name, float $value, array $labels = []): void
    {
        $this->register($name, 'histogram', $name);
        $base = $this->labelKey($labels);
        $normalized = $this->normalizeLabels($labels);
        $buckets = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0, INF];
        foreach ($buckets as $bucket) {
            $bucketLabel = is_infinite($bucket) ? '+Inf' : (string)$bucket;
            $key = $base . '|le=' . $bucketLabel;
            $this->metrics[$name]['values'][$key] ??= ['labels' => $normalized + ['le' => $bucketLabel], 'value' => 0.0];
            if ($value <= $bucket) {
                $this->metrics[$name]['values'][$key]['value']++;
            }
        }
        $sumKey = $base . '|__sum';
        $countKey = $base . '|__count';
        $this->metrics[$name]['values'][$sumKey] ??= ['labels' => $normalized, 'value' => 0.0];
        $this->metrics[$name]['values'][$countKey] ??= ['labels' => $normalized, 'value' => 0.0];
        $this->metrics[$name]['values'][$sumKey]['value'] += $value;
        $this->metrics[$name]['values'][$countKey]['value']++;
        $this->persist();
    }

    public function render(bool $includeRuntime = true): string
    {
        if ($includeRuntime) {
            $this->withPersistencePaused(function (): void {
                $this->setRuntimeMetrics();
            });
        }
        $lines = [];
        foreach ($this->metrics as $name => $metric) {
            $lines[] = '# HELP ' . $name . ' ' . $metric['help'];
            $lines[] = '# TYPE ' . $name . ' ' . $metric['type'];
            foreach ($metric['values'] as $entryKey => $entry) {
                $metricName = $name;
                $labels = $entry['labels'];
                if ($metric['type'] === 'histogram') {
                    if (array_key_exists('le', $labels)) {
                        $metricName .= '_bucket';
                    } elseif (str_ends_with((string)$entryKey, '|__sum')) {
                        $metricName .= '_sum';
                    } elseif (str_ends_with((string)$entryKey, '|__count')) {
                        $metricName .= '_count';
                    }
                }
                $lines[] = $metricName . $this->formatLabels($labels) . ' ' . $this->number($entry['value']);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $this->withPersistencePaused(function (): void {
            $this->setRuntimeMetrics();
        });

        return ['metrics' => $this->metrics];
    }

    public function persistNow(): void
    {
        $this->persist();
    }

    /** @param array<string, mixed> $snapshot */
    public static function renderSnapshot(array $snapshot): string
    {
        $registry = new self();
        $metrics = $snapshot['metrics'] ?? null;
        if (is_array($metrics)) {
            $registry->metrics = $metrics;
        }

        return $registry->render(false);
    }

    public static function renderPersisted(string $directory, ?int $maxAgeSeconds = null): string
    {
        $registry = new self();
        $registry->metrics = [];
        $maxAgeSeconds ??= self::snapshotMaxAgeSeconds();
        $now = microtime(true);
        $files = glob(rtrim($directory, '\\/') . DIRECTORY_SEPARATOR . '*.json') ?: [];
        foreach ($files as $file) {
            $snapshot = json_decode((string)@file_get_contents($file), true);
            if (is_array($snapshot) && self::shouldRemoveSnapshot($file, $snapshot, $now, $maxAgeSeconds)) {
                @unlink($file);
                continue;
            }
            if (is_array($snapshot)) {
                $registry->mergeSnapshot($snapshot);
            }
        }

        return $registry->render(false);
    }

    private function setRuntimeMetrics(): void
    {
        $this->set('process_uptime_seconds', [], max(0, microtime(true) - $this->startedAt));
        $this->set('process_resident_memory_bytes', [], (float)memory_get_usage(true));
        if (is_dir('/proc/self/fd')) {
            $entries = @scandir('/proc/self/fd');
            if (is_array($entries)) {
                $this->set('process_open_fds', [], (float)max(0, count($entries) - 2));
            }
        }
        if (function_exists('getrusage')) {
            $usage = getrusage();
            $this->set('process_cpu_seconds_total', [], ((float)($usage['ru_utime.tv_sec'] ?? 0)) + ((float)($usage['ru_stime.tv_sec'] ?? 0)));
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function mergeSnapshot(array $snapshot): void
    {
        $metrics = $snapshot['metrics'] ?? null;
        if (!is_array($metrics)) {
            return;
        }
        foreach ($metrics as $name => $metric) {
            if (!is_string($name) || !is_array($metric)) {
                continue;
            }
            $type = is_string($metric['type'] ?? null) ? $metric['type'] : 'gauge';
            $help = is_string($metric['help'] ?? null) ? $metric['help'] : $name;
            $this->register($name, $type, $help);
            $values = $metric['values'] ?? null;
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as $value) {
                if (!is_array($value) || !is_array($value['labels'] ?? null) || !is_numeric($value['value'] ?? null)) {
                    continue;
                }
                $labels = [];
                foreach ($value['labels'] as $key => $label) {
                    if (is_scalar($label)) {
                        $labels[(string)$key] = (string)$label;
                    }
                }
                $key = $this->labelKey($labels);
                $numeric = (float)$value['value'];
                if (!isset($this->metrics[$name]['values'][$key])) {
                    $this->metrics[$name]['values'][$key] = ['labels' => $labels, 'value' => $numeric];
                } elseif ($name === 'process_uptime_seconds') {
                    $this->metrics[$name]['values'][$key]['value'] = max($this->metrics[$name]['values'][$key]['value'], $numeric);
                } else {
                    $this->metrics[$name]['values'][$key]['value'] += $numeric;
                }
            }
        }
    }

    private function persist(): void
    {
        if ($this->persisting || self::$persistenceDirectory === null) {
            return;
        }
        $this->persisting = true;
        try {
            $directory = self::$persistenceDirectory;
            if (!is_dir($directory)) {
                @mkdir($directory, 0777, true);
            }
            $this->setRuntimeMetrics();
            $json = json_encode([
                'pid' => getmypid(),
                'writtenAtUnix' => microtime(true),
                'metrics' => $this->metrics,
            ], JSON_UNESCAPED_SLASHES);
            if ($json !== false) {
                $service = rawurlencode(trim((string)(getenv('SERVICE_NAME') ?: 'php-service')));
                $path = rtrim($directory, '\\/') . DIRECTORY_SEPARATOR . $service . '.' . getmypid() . '.json';
                $temporary = $path . '.tmp';
                if (@file_put_contents($temporary, $json, LOCK_EX) !== false) {
                    @rename($temporary, $path);
                }
            }
        } finally {
            $this->persisting = false;
        }
    }

    /** @param callable():void $callback */
    private function withPersistencePaused(callable $callback): void
    {
        $wasPersisting = $this->persisting;
        $this->persisting = true;
        try {
            $callback();
        } finally {
            $this->persisting = $wasPersisting;
        }
    }

    /** @param array<string, string|int|float|bool> $labels */
    private function labelKey(array $labels): string
    {
        $normalized = $this->normalizeLabels($labels);
        ksort($normalized);

        return http_build_query($normalized, '', '&', PHP_QUERY_RFC3986);
    }

    /** @param array<string, string|int|float|bool> $labels @return array<string,string> */
    private function normalizeLabels(array $labels): array
    {
        $normalized = [];
        foreach ($labels as $key => $value) {
            $normalized[(string)$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
        }
        $normalized['service'] ??= trim((string)(getenv('SERVICE_NAME') ?: getenv('OTEL_SERVICE_NAME') ?: 'unknown-service'));

        return $normalized;
    }

    /** @param array<string,string> $labels */
    private function formatLabels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }
        ksort($labels);
        $pairs = [];
        foreach ($labels as $key => $value) {
            $pairs[] = $key . '="' . addcslashes($value, "\\\"\n\r") . '"';
        }

        return '{' . implode(',', $pairs) . '}';
    }

    private function number(float $value): string
    {
        return is_finite($value) ? rtrim(rtrim(sprintf('%.12F', $value), '0'), '.') : '0';
    }

    private static function snapshotMaxAgeSeconds(): int
    {
        $raw = trim((string)(getenv('OBSERVABILITY_METRICS_SNAPSHOT_MAX_AGE_SECONDS') ?: ''));

        return ctype_digit($raw) ? max(1, (int)$raw) : 3600;
    }

    /** @param array<string, mixed> $snapshot */
    private static function shouldRemoveSnapshot(string $file, array $snapshot, float $now, int $maxAgeSeconds): bool
    {
        $pid = self::snapshotPid($file, $snapshot);
        if ($pid !== null) {
            $alive = self::processIsAlive($pid);
            if ($alive === true) {
                return false;
            }
            if ($alive === false) {
                return true;
            }
        }

        $writtenAt = is_numeric($snapshot['writtenAtUnix'] ?? null) ? (float)$snapshot['writtenAtUnix'] : null;
        $modifiedAt = @filemtime($file);
        $timestamp = $writtenAt ?? (is_int($modifiedAt) ? (float)$modifiedAt : null);
        if ($timestamp === null) {
            return false;
        }

        return $now - $timestamp > max(1, $maxAgeSeconds);
    }

    /** @param array<string, mixed> $snapshot */
    private static function snapshotPid(string $file, array $snapshot): ?int
    {
        $candidate = $snapshot['pid'] ?? null;
        if (is_int($candidate) || (is_string($candidate) && ctype_digit($candidate))) {
            $pid = (int)$candidate;
            if ($pid > 0) {
                return $pid;
            }
        }
        if (preg_match('/\.([0-9]+)\.json\z/', basename($file), $matches) === 1) {
            return (int)$matches[1];
        }

        return null;
    }

    private static function processIsAlive(int $pid): ?bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (is_dir('/proc')) {
            return is_dir('/proc/' . $pid);
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return null;
    }
}
