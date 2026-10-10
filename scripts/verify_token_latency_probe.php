#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Opt-in full-chain latency probe for the authenticated VerifyToken path.
 *
 * Each measured sample is one complete HTTP round trip to an existing gateway
 * endpoint (default GET /api/v1/personas): gateway AuthMiddleware ->
 * auth-service VerifyToken gRPC (JWT decode + Redis revocation lookup + MySQL
 * device-ownership query + user-service user lookup) -> the endpoint's own
 * user-service work. The numbers are complete authenticated HTTP request
 * latency, including downstream UserService; they are NOT an isolated
 * VerifyToken RPC measurement.
 *
 * The probe never logs in, never writes accounts, and never prints the access
 * token, request headers, or exception URLs. The token is read only from
 * VERIFY_TOKEN_PROBE_TOKEN.
 * Before warmup or sampling, an invalid JWT must receive an authentication
 * rejection and the valid JWT must receive the endpoint's expected data shape.
 * These two preflight requests are never included in latency statistics.
 *
 * Usage:
 *   VERIFY_TOKEN_PROBE_TOKEN=<access token> php scripts/verify_token_latency_probe.php
 *
 * Env:
 *   API_BASE_URL                          gateway base URL (default http://127.0.0.1:8787)
 *   VERIFY_TOKEN_PROBE_TOKEN              required access token of an existing test identity
 *   VERIFY_TOKEN_PROBE_PATH               request path (default /api/v1/personas; /api/v1/me works too)
 *   VERIFY_TOKEN_PROBE_REQUESTS           measured requests (default 30)
 *   VERIFY_TOKEN_PROBE_WARMUP             unmeasured warmup requests (default 3)
 *   VERIFY_TOKEN_PROBE_CONCURRENCY        max in-flight measured requests (default 4)
 *   VERIFY_TOKEN_PROBE_TIMEOUT_MS         per-request timeout (default 5000)
 *   VERIFY_TOKEN_PROBE_CONNECT_TIMEOUT_MS connect timeout (default 2000)
 *   VERIFY_TOKEN_PROBE_BUDGET_MS          P95 gate (default 1000)
 *
 * Exit codes: 0 pass; 1 gate failed (any HTTP/business/timeout/transport failure
 * or P95 >= budget); 2 configuration error.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Contracts\Errors\ErrorCode;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\ResponseInterface;

final class VerifyTokenLatencyProbe
{
    private const DEFAULT_BASE_URL = 'http://127.0.0.1:8787';
    private const DEFAULT_PATH = '/api/v1/personas';
    private const ALLOWED_ME_PATH = '/api/v1/me';

    private function __construct(
        private readonly string $baseUrl,
        private readonly string $path,
        private readonly string $token,
        private readonly int $requests,
        private readonly int $warmup,
        private readonly int $concurrency,
        private readonly int $timeoutMs,
        private readonly int $connectTimeoutMs,
        private readonly int $budgetMs,
    ) {
    }

    /** @param array<string, string> $env */
    public static function fromEnvironment(array $env): self
    {
        $token = trim($env['VERIFY_TOKEN_PROBE_TOKEN'] ?? '');
        if ($token === '') {
            throw new InvalidArgumentException(
                'VERIFY_TOKEN_PROBE_TOKEN is required; the token is never printed or logged'
            );
        }

        $path = trim($env['VERIFY_TOKEN_PROBE_PATH'] ?? '') ?: self::DEFAULT_PATH;
        if (!in_array($path, [self::DEFAULT_PATH, self::ALLOWED_ME_PATH], true)) {
            throw new InvalidArgumentException(sprintf(
                'VERIFY_TOKEN_PROBE_PATH must be one of %s, %s; only existing authenticated read-only paths are probed',
                self::DEFAULT_PATH,
                self::ALLOWED_ME_PATH,
            ));
        }

        $baseUrl = rtrim(($env['API_BASE_URL'] ?? '') ?: self::DEFAULT_BASE_URL, '/');
        $parts = parse_url($baseUrl);
        if (
            !is_array($parts)
            || !in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException(
                'API_BASE_URL must be a plain http(s) URL without userinfo, query, or fragment'
            );
        }

        $timeoutMs = self::envCount($env, 'VERIFY_TOKEN_PROBE_TIMEOUT_MS', 5000, 1);
        $connectTimeoutMs = self::envCount($env, 'VERIFY_TOKEN_PROBE_CONNECT_TIMEOUT_MS', 2000, 1);
        if ($connectTimeoutMs > $timeoutMs) {
            throw new InvalidArgumentException(
                'VERIFY_TOKEN_PROBE_CONNECT_TIMEOUT_MS must not exceed VERIFY_TOKEN_PROBE_TIMEOUT_MS'
            );
        }

        return new self(
            baseUrl: $baseUrl,
            path: $path,
            token: $token,
            requests: self::envCount($env, 'VERIFY_TOKEN_PROBE_REQUESTS', 30, 1),
            warmup: self::envCount($env, 'VERIFY_TOKEN_PROBE_WARMUP', 3, 0),
            concurrency: self::envCount($env, 'VERIFY_TOKEN_PROBE_CONCURRENCY', 4, 1),
            timeoutMs: $timeoutMs,
            connectTimeoutMs: $connectTimeoutMs,
            budgetMs: self::envCount($env, 'VERIFY_TOKEN_PROBE_BUDGET_MS', 1000, 1),
        );
    }

    /** @param array<string, string> $env */
    private static function envCount(array $env, string $name, int $default, int $min): int
    {
        $raw = $env[$name] ?? '';
        if ($raw === '') {
            return $default;
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min]]);
        if ($value === false) {
            throw new InvalidArgumentException("{$name} must be an integer >= {$min}");
        }

        return $value;
    }

    /**
     * @param callable|null $handler Guzzle handler override (tests inject MockHandler); null uses cURL.
     * @return array{timings_ms: list<float>, outcomes: list<array{phase: string, category: string, http_status: ?int, business_code: ?int, message: ?string}>}
     */
    public function run(?callable $handler = null): array
    {
        $client = new Client([
            'handler' => HandlerStack::create($handler),
            'http_errors' => false,
            'allow_redirects' => false,
        ]);
        $url = $this->baseUrl . $this->path;
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/json',
            ],
            'timeout' => $this->timeoutMs / 1000,
            'connect_timeout' => $this->connectTimeoutMs / 1000,
        ];

        foreach ([true, false] as $negative) {
            $preflightOptions = $options;
            if ($negative) {
                // Deliberately malformed JWT; keep Bearer authentication present
                // so this checks token verification rather than a missing header.
                $preflightOptions['headers']['Authorization'] = 'Bearer invalid.jwt.probe';
            }
            $preflight = $this->preflight($client, $url, $preflightOptions, $negative);
            if ($preflight['category'] !== 'ok') {
                return [
                    'timings_ms' => [],
                    'outcomes' => [['phase' => $negative ? 'preflight_invalid' : 'preflight_valid'] + $preflight],
                ];
            }
        }

        $outcomes = [];
        for ($i = 0; $i < $this->warmup; $i++) {
            $outcomes[] = ['phase' => 'warmup'] + $this->attempt($client, $url, $options);
        }

        /** @var array<int, float> $durations */
        $durations = [];
        /** @var array<int, int> $startedAt */
        $startedAt = [];
        $requests = function () use ($client, $url, $options, &$durations, &$startedAt): Generator {
            for ($index = 0; $index < $this->requests; $index++) {
                yield $index => static function (array $requestOptions) use ($client, $url, $options, $index, &$durations, &$startedAt): PromiseInterface {
                    $startedAt[$index] = hrtime(true);
                    $requestOptions += $options;
                    $requestOptions['on_stats'] = static function (TransferStats $stats) use ($index, &$durations): void {
                        $transferTime = $stats->getTransferTime();
                        if ($transferTime !== null) {
                            $durations[$index] = $transferTime * 1000.0;
                        }
                    };

                    return $client->sendAsync(new Request('GET', $url), $requestOptions);
                };
            }
        };

        /** @var array<int, array{phase: string, category: string, http_status: ?int, business_code: ?int, message: ?string}> $records */
        $records = [];
        $pool = new Pool($client, $requests(), [
            'concurrency' => $this->concurrency,
            'fulfilled' => function (ResponseInterface $response, int $index) use (&$records, &$durations, &$startedAt): void {
                $records[$index] = self::classify($response, null);
                self::ensureDuration($index, $durations, $startedAt);
            },
            'rejected' => function (Throwable $reason, int $index) use (&$records, &$durations, &$startedAt): void {
                $records[$index] = self::classify(null, $reason);
                self::ensureDuration($index, $durations, $startedAt);
            },
        ]);
        $pool->promise()->wait();

        ksort($records);
        foreach ($records as $record) {
            $outcomes[] = ['phase' => 'measured'] + $record;
        }
        ksort($durations);

        return ['timings_ms' => array_values($durations), 'outcomes' => $outcomes];
    }

    /** @param array<string, mixed> $options */
    private function preflight(Client $client, string $url, array $options, bool $negative): array
    {
        try {
            $response = $client->request('GET', $url, $options);
        } catch (Throwable $error) {
            return self::classify(null, $error);
        }

        $decoded = json_decode((string)$response->getBody());
        $code = is_object($decoded) && is_int($decoded->code ?? null) ? $decoded->code : null;
        if ($negative) {
            $accepted = $response->getStatusCode() === 401 && in_array($code, [
                ErrorCode::AUTH_TOKEN_INVALID->value,
                ErrorCode::AUTH_UNAUTHORIZED->value,
            ], true);
            return [
                'category' => $accepted ? 'ok' : 'business',
                'http_status' => $response->getStatusCode(),
                'business_code' => $code,
                'message' => $accepted ? null : 'invalid JWT must receive HTTP 401 with an authentication error code',
            ];
        }

        $outcome = self::classify($response, null);
        if ($outcome['category'] !== 'ok') {
            // Never report a target-controlled message during preflight.
            $outcome['message'] = 'valid JWT preflight request failed';
            return $outcome;
        }
        $data = $decoded->data ?? null;
        $validData = is_object($data) && is_array($data->personas ?? null);
        if ($this->path === self::ALLOWED_ME_PATH) {
            $validData = $validData && is_object($data->user ?? null)
                && is_string($data->user->id ?? null)
                && $data->user->id !== '';
        }
        if (!$validData) {
            $outcome['category'] = 'business';
            $outcome['message'] = 'valid JWT response is missing the expected endpoint data structure';
        }

        return $outcome;
    }

    /** @param array<string, mixed> $options */
    private function attempt(Client $client, string $url, array $options): array
    {
        try {
            return self::classify($client->request('GET', $url, $options), null);
        } catch (Throwable $error) {
            return self::classify(null, $error);
        }
    }

    /**
     * @return array{category: string, http_status: ?int, business_code: ?int, message: ?string}
     */
    public static function classify(?ResponseInterface $response, ?Throwable $error): array
    {
        if ($error !== null) {
            // Only the exception class is reported: messages may embed request
            // URLs and handler context, which must stay out of the report.
            return [
                'category' => self::isTimeout($error) ? 'timeout' : 'transport',
                'http_status' => null,
                'business_code' => null,
                'message' => (new ReflectionClass($error))->getShortName(),
            ];
        }

        $status = $response?->getStatusCode();
        if ($status === null || $status < 200 || $status >= 300) {
            $decoded = $response === null ? null : json_decode((string)$response->getBody(), true);
            $code = is_array($decoded) && is_int($decoded['code'] ?? null) ? $decoded['code'] : null;
            if ($code === ErrorCode::RPC_TIMEOUT->value) {
                // The gateway answers an exceeded gRPC deadline with HTTP 503 and
                // this business code while the HTTP transport itself succeeded.
                return [
                    'category' => 'timeout',
                    'http_status' => $status,
                    'business_code' => $code,
                    'message' => null,
                ];
            }

            return [
                'category' => $status === null ? 'transport' : 'http',
                'http_status' => $status,
                'business_code' => null,
                'message' => null,
            ];
        }

        $decoded = json_decode((string)$response->getBody(), true);
        if (!is_array($decoded) || !is_int($decoded['code'] ?? null)) {
            return [
                'category' => 'business',
                'http_status' => $status,
                'business_code' => null,
                'message' => 'response is not the expected JSON envelope with an integer code',
            ];
        }

        $code = $decoded['code'];
        if ($code !== 0) {
            return [
                'category' => 'business',
                'http_status' => $status,
                'business_code' => $code,
                'message' => is_string($decoded['message'] ?? null) ? substr($decoded['message'], 0, 120) : null,
            ];
        }

        return ['category' => 'ok', 'http_status' => $status, 'business_code' => 0, 'message' => null];
    }

    private static function isTimeout(Throwable $error): bool
    {
        if (!$error instanceof ConnectException) {
            return false;
        }
        $errno = (int)($error->getHandlerContext()['errno'] ?? 0);

        return $errno === (defined('CURLE_OPERATION_TIMEDOUT') ? (int)CURLE_OPERATION_TIMEDOUT : 28)
            || str_contains(strtolower($error->getMessage()), 'timed out');
    }

    /** @param array<int, float> $durations @param array<int, int> $startedAt */
    private static function ensureDuration(int $index, array &$durations, array $startedAt): void
    {
        if (!isset($durations[$index]) && isset($startedAt[$index])) {
            $durations[$index] = (hrtime(true) - $startedAt[$index]) / 1e6;
        }
    }

    /**
     * @param list<float> $timingsMs unsorted timings in milliseconds
     * @return array{count: int, min: float, p50: float, p95: float, p99: float, max: float}
     */
    public static function summary(array $timingsMs): array
    {
        if ($timingsMs === []) {
            throw new InvalidArgumentException('summary() requires at least one timing');
        }
        sort($timingsMs);

        return [
            'count' => count($timingsMs),
            'min' => $timingsMs[0],
            'p50' => self::percentile($timingsMs, 0.50),
            'p95' => self::percentile($timingsMs, 0.95),
            'p99' => self::percentile($timingsMs, 0.99),
            'max' => $timingsMs[count($timingsMs) - 1],
        ];
    }

    /**
     * Nearest-rank percentile over sorted timings, matching the ranking used by
     * scripts/auth_latency_probe.sh and AuthWarmPathLatencyTest.
     *
     * @param list<float> $sortedTimingsMs
     */
    public static function percentile(array $sortedTimingsMs, float $quantile): float
    {
        if ($sortedTimingsMs === []) {
            throw new InvalidArgumentException('percentile() requires at least one timing');
        }
        $last = count($sortedTimingsMs) - 1;
        $index = (int)ceil($quantile * count($sortedTimingsMs)) - 1;

        return $sortedTimingsMs[max(0, min($index, $last))];
    }

    /**
     * @param list<array{phase: string, category: string}> $outcomes
     * @param array{count: int, p95: float} $summary
     * @return array{exit_code: int, failure_counts: array{http: int, business: int, timeout: int, transport: int, missing: int, total: int}, timeout_rate: float, reasons: list<string>}
     */
    public function evaluate(array $outcomes, array $summary): array
    {
        $counts = ['http' => 0, 'business' => 0, 'timeout' => 0, 'transport' => 0];
        $measured = 0;
        $measuredTimeouts = 0;

        foreach ($outcomes as $outcome) {
            if (isset($counts[$outcome['category']])) {
                $counts[$outcome['category']]++;
            }
            if ($outcome['phase'] === 'measured') {
                $measured++;
                if ($outcome['category'] === 'timeout') {
                    $measuredTimeouts++;
                }
            }
        }

        $counts['missing'] = max(0, $this->requests - $summary['count']);
        $counts['total'] = $counts['http'] + $counts['business'] + $counts['timeout'] + $counts['transport'] + $counts['missing'];

        $reasons = [];
        foreach (['http' => 'HTTP', 'business' => 'business', 'timeout' => 'timeout', 'transport' => 'transport', 'missing' => 'missing'] as $category => $label) {
            if ($counts[$category] > 0) {
                $reasons[] = sprintf('%d %s failure(s)', $counts[$category], $label);
            }
        }
        if ($summary['p95'] >= $this->budgetMs) {
            $reasons[] = sprintf('p95 %.2f ms >= %d ms budget', $summary['p95'], $this->budgetMs);
        }

        return [
            'exit_code' => $reasons === [] ? 0 : 1,
            'failure_counts' => $counts,
            'timeout_rate' => $measured > 0 ? $measuredTimeouts / $measured : 0.0,
            'reasons' => $reasons,
        ];
    }

    /**
     * @param list<array{phase: string, category: string}> $outcomes
     * @param array{count: int, min: float, p50: float, p95: float, p99: float, max: float} $summary
     * @param array{exit_code: int, failure_counts: array{http: int, business: int, timeout: int, transport: int, missing: int, total: int}, timeout_rate: float, reasons: list<string>} $gate
     */
    public function report(array $outcomes, array $summary, array $gate): string
    {
        $measuredFailures = 0;
        $warmupFailures = 0;
        foreach ($outcomes as $outcome) {
            if ($outcome['category'] === 'ok') {
                continue;
            }
            $outcome['phase'] === 'measured' ? $measuredFailures++ : $warmupFailures++;
        }

        return implode(PHP_EOL, [
            'VerifyToken full-chain latency probe (opt-in, concurrent HTTP)',
            sprintf('  target:  GET %s on %s', $this->path, $this->baseUrl),
            sprintf('  samples: %d measured + %d warmup, concurrency %d', $this->requests, $this->warmup, $this->concurrency),
            '  preflight: invalid JWT rejected and valid JWT data checked (2 unmeasured requests)',
            '  scope:   one sample = the complete authenticated HTTP request: gateway AuthMiddleware',
            '           -> VerifyToken gRPC (JWT + Redis revocation + MySQL device ownership',
            '           + user-service lookup) -> endpoint user-service work. Complete request',
            '           latency including downstream UserService; NOT an isolated VerifyToken RPC.',
            sprintf('  p50: %8.2f ms   p95: %8.2f ms   p99: %8.2f ms   max: %8.2f ms', $summary['p50'], $summary['p95'], $summary['p99'], $summary['max']),
            sprintf('  budget: p95 < %d ms', $this->budgetMs),
            sprintf(
                '  failures: %d measured + %d warmup (http %d, business %d, timeout %d, transport %d, missing %d); measured timeout rate %.2f%%',
                $measuredFailures,
                $warmupFailures,
                $gate['failure_counts']['http'],
                $gate['failure_counts']['business'],
                $gate['failure_counts']['timeout'],
                $gate['failure_counts']['transport'],
                $gate['failure_counts']['missing'],
                $gate['timeout_rate'] * 100,
            ),
        ]);
    }

    /** Runs the probe, prints the report and failure reasons, returns the process exit code. */
    public function execute(?callable $handler = null): int
    {
        $run = $this->run($handler);
        if ($run['timings_ms'] === []) {
            $failure = $run['outcomes'][0] ?? null;
            fwrite(STDERR, 'FAILED: ' . ($failure === null ? 'no timing samples were recorded' : sprintf(
                '%s (%s): %s; sampling was not started',
                $failure['phase'],
                $failure['category'],
                $failure['message'] ?? 'authentication preflight failed',
            )) . PHP_EOL);

            return 1;
        }

        $summary = self::summary($run['timings_ms']);
        $gate = $this->evaluate($run['outcomes'], $summary);

        echo $this->report($run['outcomes'], $summary, $gate), PHP_EOL;
        foreach ($gate['reasons'] as $reason) {
            fwrite(STDERR, 'FAILED: ' . $reason . PHP_EOL);
        }

        return $gate['exit_code'];
    }
}

if (PHP_SAPI === 'cli' && realpath((string)($argv[0] ?? '')) === __FILE__) {
    try {
        exit(VerifyTokenLatencyProbe::fromEnvironment(getenv())->execute());
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, 'verify_token_latency_probe: ' . $e->getMessage() . PHP_EOL);
        exit(2);
    }
}
