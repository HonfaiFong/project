<?php

declare(strict_types=1);

namespace Tests\Unit;

use Contracts\Errors\ErrorCode;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use VerifyTokenLatencyProbe;

require_once dirname(__DIR__, 2) . '/scripts/verify_token_latency_probe.php';

/**
 * Deterministic, network-free coverage for the opt-in full-chain probe:
 * percentile/statistics math, outcome classification, the failure/budget gate
 * and the hard CLI contract (non-zero exit on HTTP/business/timeout failures,
 * token and exception URLs never printed). The Guzzle handler is replaced with
 * MockHandler, so nothing here touches a real gateway or auth service.
 */
final class VerifyTokenLatencyProbeTest extends TestCase
{
    /** @param array<string, string> $overrides */
    private function probe(array $overrides = []): VerifyTokenLatencyProbe
    {
        return VerifyTokenLatencyProbe::fromEnvironment($overrides + [
            'VERIFY_TOKEN_PROBE_TOKEN' => 'unit-test-token-never-printed',
            'API_BASE_URL' => 'http://127.0.0.1:1',
            'VERIFY_TOKEN_PROBE_REQUESTS' => '5',
            'VERIFY_TOKEN_PROBE_WARMUP' => '0',
            'VERIFY_TOKEN_PROBE_CONCURRENCY' => '2',
            'VERIFY_TOKEN_PROBE_BUDGET_MS' => '1000',
        ]);
    }

    /** @param list<Response|ConnectException> $queue */
    private function mockHandler(array $queue): MockHandler
    {
        return new MockHandler([
            new Response(401, [], '{"code":' . ErrorCode::AUTH_TOKEN_INVALID->value . '}'),
            new Response(200, [], '{"code":0,"data":{"personas":[],"user":{"id":"123"}}}'),
            ...$queue,
        ]);
    }

    /**
     * No-network handler whose promises stay pending until the Guzzle wait loop
     * drives them: each wait function settles the whole current batch of tickets
     * with a fixed TransferStats duration, so Pool concurrency and the seconds to
     * milliseconds conversion are observable instead of MockHandler's instant 0 s
     * completions. No sleeps and no sockets are involved.
     *
     * @param array{settled: int, inflight: int, max_inflight: int} $log
     */
    private function delayedPromiseHandler(array &$log, float $transferSeconds): callable
    {
        /** @var array<int, array{promise: Promise, request: RequestInterface, options: array}> $tickets */
        $tickets = [];

        return function (RequestInterface $request, array $options) use (&$log, $transferSeconds, &$tickets): PromiseInterface {
            $log['inflight']++;
            $log['max_inflight'] = max($log['max_inflight'], $log['inflight']);

            $promise = new Promise(function () use (&$tickets, &$log, $transferSeconds): void {
                $batch = $tickets;
                $tickets = [];
                foreach ($batch as $ticket) {
                    $response = $log['settled'] === 0
                        ? new Response(401, [], '{"code":' . ErrorCode::AUTH_TOKEN_INVALID->value . '}')
                        : new Response(200, [], '{"code":0,"data":{"personas":[]}}');
                    $onStats = $ticket['options']['on_stats'] ?? null;
                    if (is_callable($onStats)) {
                        $onStats(new TransferStats($ticket['request'], $response, $transferSeconds, null));
                    }
                    $log['inflight']--;
                    $log['settled']++;
                    $ticket['promise']->resolve($response);
                }
            });

            $tickets[] = ['promise' => $promise, 'request' => $request, 'options' => $options];

            return $promise;
        };
    }

    /** @return list<array{phase: string, category: string, http_status: ?int, business_code: ?int, message: ?string}> */
    private function okOutcomes(int $count): array
    {
        return array_fill(0, $count, [
            'phase' => 'measured',
            'category' => 'ok',
            'http_status' => 200,
            'business_code' => 0,
            'message' => null,
        ]);
    }

    public function testPercentileAndSummaryUseTheNearestRankRankingSharedWithTheShellProbe(): void
    {
        $timings = [1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 10.0];
        $this->assertSame(5.0, VerifyTokenLatencyProbe::percentile($timings, 0.50));
        $this->assertSame(10.0, VerifyTokenLatencyProbe::percentile($timings, 0.95));
        $this->assertSame(10.0, VerifyTokenLatencyProbe::percentile($timings, 0.99));

        $summary = VerifyTokenLatencyProbe::summary([40.0, 10.0, 30.0, 20.0]);
        $this->assertSame(4, $summary['count']);
        $this->assertSame(10.0, $summary['min']);
        $this->assertSame(20.0, $summary['p50']);
        $this->assertSame(40.0, $summary['p95']);
        $this->assertSame(40.0, $summary['p99']);
        $this->assertSame(40.0, $summary['max']);
    }

    public function testGateFailsOnHttpBusinessAndTimeoutFailuresEvenInsideTheBudget(): void
    {
        $probe = $this->probe(['VERIFY_TOKEN_PROBE_REQUESTS' => '7']);
        $run = $probe->run($this->mockHandler([
            new Response(200, [], '{"code":0}' ),
            new Response(503, [], '{"code":1001}'),
            new Response(200, [], '{"code":7,"message":"banned"}'),
            new ConnectException(
                'cURL error 28: Operation timed out after 5000 milliseconds for https://auth-internal.invalid/token-check',
                new Request('GET', 'http://127.0.0.1:1/api/v1/personas'),
                null,
                ['errno' => 28],
            ),
            new Response(200, [], 'not-json'),
            new Response(200, [], '{"code":"abc"}'),
            new Response(200, [], '{"code":null}'),
        ]));

        $this->assertCount(7, $run['timings_ms']);
        $this->assertSame(
            ['ok', 'http', 'business', 'timeout', 'business', 'business', 'business'],
            array_column($run['outcomes'], 'category')
        );
        // A non-integer or null business code must never collapse to a false success.
        $this->assertSame(7, $run['outcomes'][2]['business_code']);
        $this->assertNull($run['outcomes'][5]['business_code']);
        $this->assertNull($run['outcomes'][6]['business_code']);

        $summary = VerifyTokenLatencyProbe::summary($run['timings_ms']);
        $gate = $probe->evaluate($run['outcomes'], $summary);

        $this->assertSame(1, $gate['exit_code']);
        $this->assertSame(
            ['http' => 1, 'business' => 4, 'timeout' => 1, 'transport' => 0, 'missing' => 0, 'total' => 6],
            $gate['failure_counts']
        );
        $this->assertSame(1 / 7, $gate['timeout_rate']);
        $reasons = implode(' ', $gate['reasons']);
        $this->assertStringContainsString('HTTP failure', $reasons);
        $this->assertStringContainsString('business failure', $reasons);
        $this->assertStringContainsString('timeout failure', $reasons);

        $report = $probe->report($run['outcomes'], $summary, $gate);
        $this->assertStringNotContainsString('unit-test-token-never-printed', $report);
        $this->assertStringNotContainsString('auth-internal.invalid', $report);
        $this->assertStringNotContainsString('Authorization', $report);
    }

    public function testTheTwoAuthenticatedReadOnlyPathsAreAllowed(): void
    {
        $probe = $this->probe(['VERIFY_TOKEN_PROBE_PATH' => '/api/v1/me', 'VERIFY_TOKEN_PROBE_REQUESTS' => '1']);
        $run = $probe->run($this->mockHandler([new Response(200, [], '{"code":0}') ]));

        $this->assertSame('ok', $run['outcomes'][0]['category']);
        $this->assertSame(0, $probe->evaluate($run['outcomes'], VerifyTokenLatencyProbe::summary($run['timings_ms']))['exit_code']);
    }

    public function testRedirectsAreNotFollowedSoA302CannotMasqueradeAsSuccess(): void
    {
        $probe = $this->probe(['VERIFY_TOKEN_PROBE_REQUESTS' => '1']);
        $run = $probe->run($this->mockHandler([
            new Response(302, ['Location' => 'http://127.0.0.1:1/public/health']),
        ]));

        $this->assertSame('http', $run['outcomes'][0]['category']);
        $this->assertSame(302, $run['outcomes'][0]['http_status']);
        $gate = $probe->evaluate($run['outcomes'], VerifyTokenLatencyProbe::summary($run['timings_ms']));
        $this->assertSame(1, $gate['exit_code']);
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function invalidSettings(): array
    {
        return [
            'unauthenticated path' => [['VERIFY_TOKEN_PROBE_PATH' => '/api/v1/health'], 'VERIFY_TOKEN_PROBE_PATH'],
            'userinfo in base url' => [['API_BASE_URL' => 'http://user:secret@127.0.0.1:8787'], 'API_BASE_URL'],
            'query in base url' => [['API_BASE_URL' => 'http://127.0.0.1:8787?token=leaked'], 'API_BASE_URL'],
            'fragment in base url' => [['API_BASE_URL' => 'http://127.0.0.1:8787#frag'], 'API_BASE_URL'],
            'non-http scheme' => [['API_BASE_URL' => 'ftp://127.0.0.1:8787'], 'API_BASE_URL'],
            'connect timeout over total' => [
                ['VERIFY_TOKEN_PROBE_CONNECT_TIMEOUT_MS' => '5000', 'VERIFY_TOKEN_PROBE_TIMEOUT_MS' => '1000'],
                'VERIFY_TOKEN_PROBE_CONNECT_TIMEOUT_MS',
            ],
            'overflowing count' => [['VERIFY_TOKEN_PROBE_REQUESTS' => '99999999999999999999'], 'VERIFY_TOKEN_PROBE_REQUESTS'],
            'non-numeric count' => [['VERIFY_TOKEN_PROBE_REQUESTS' => 'abc'], 'VERIFY_TOKEN_PROBE_REQUESTS'],
        ];
    }

    /** @param array<string, string> $settings */
    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsAreRejected(array $settings, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);
        $this->probe($settings);
    }

    public function testWarmupRequestsAreNotMeasuredButTheirFailuresStillFailTheGate(): void
    {
        $probe = $this->probe(['VERIFY_TOKEN_PROBE_WARMUP' => '1', 'VERIFY_TOKEN_PROBE_REQUESTS' => '2']);
        $run = $probe->run($this->mockHandler([
            new Response(401, [], '{"code":401}' ),
            new Response(200, [], '{"code":0}'),
            new Response(200, [], '{"code":0}'),
        ]));

        $this->assertSame('warmup', $run['outcomes'][0]['phase']);
        $this->assertSame('measured', $run['outcomes'][1]['phase']);
        $this->assertCount(2, $run['timings_ms']);

        $gate = $probe->evaluate($run['outcomes'], VerifyTokenLatencyProbe::summary($run['timings_ms']));
        $this->assertSame(1, $gate['exit_code']);
        $this->assertSame(1, $gate['failure_counts']['http']);
        $this->assertSame(1, $gate['failure_counts']['total']);
    }

    public function testBudgetGateComparesP95AtTheSortedBoundary(): void
    {
        $four = $this->probe(['VERIFY_TOKEN_PROBE_BUDGET_MS' => '40', 'VERIFY_TOKEN_PROBE_REQUESTS' => '4']);

        $atBudget = $four->evaluate($this->okOutcomes(4), VerifyTokenLatencyProbe::summary([10.0, 20.0, 30.0, 40.0]));
        $this->assertSame(1, $atBudget['exit_code']);
        $this->assertStringContainsString('budget', implode(' ', $atBudget['reasons']));

        $belowBudget = $four->evaluate($this->okOutcomes(4), VerifyTokenLatencyProbe::summary([10.0, 20.0, 30.0, 39.0]));
        $this->assertSame(0, $belowBudget['exit_code']);
        $this->assertSame([], $belowBudget['reasons']);

        // One outlier above the budget only fails when it lands on the P95 rank.
        $twenty = $this->probe(['VERIFY_TOKEN_PROBE_BUDGET_MS' => '40', 'VERIFY_TOKEN_PROBE_REQUESTS' => '20']);
        $summary = VerifyTokenLatencyProbe::summary(array_merge(array_fill(0, 19, 10.0), [5000.0]));
        $this->assertSame(10.0, $summary['p95']);
        $this->assertSame(5000.0, $summary['max']);
        $this->assertSame(0, $twenty->evaluate($this->okOutcomes(20), $summary)['exit_code']);
    }

    public function testExecutePrintsTheCumulativeScopeAndExitsZeroOnSuccess(): void
    {
        $probe = $this->probe();

        ob_start();
        $exitCode = $probe->execute($this->mockHandler(array_fill(0, 5, new Response(200, [], '{"code":0}'))));
        $stdout = (string)ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('p95:', $stdout);
        $this->assertStringContainsString('complete authenticated HTTP request', $stdout);
        $this->assertStringContainsString('NOT an isolated VerifyToken RPC', $stdout);
        $this->assertStringContainsString('user-service work', $stdout);
        $this->assertStringNotContainsString('unit-test-token-never-printed', $stdout);
        // "auth-side" is reserved for the 50 ms local cumulative budget in
        // AuthWarmPathLatencyTest, which the probe does not measure.
        $this->assertStringNotContainsString('auth-side', $stdout);
    }

    public function testGatewayRpcTimeoutBusinessCodeCountsAsTimeoutNotPlainHttpFailure(): void
    {
        $probe = $this->probe(['VERIFY_TOKEN_PROBE_REQUESTS' => '2']);
        $run = $probe->run($this->mockHandler([
            new Response(503, [], '{"code":' . ErrorCode::RPC_TIMEOUT->value . ',"message":"upstream service rpc timeout"}'),
            new Response(503, [], '{"code":' . ErrorCode::SERVICE_UNAVAILABLE->value . ',"message":"service unavailable"}'),
        ]));

        $this->assertSame('timeout', $run['outcomes'][0]['category']);
        $this->assertSame(503, $run['outcomes'][0]['http_status']);
        $this->assertSame(ErrorCode::RPC_TIMEOUT->value, $run['outcomes'][0]['business_code']);
        $this->assertSame('http', $run['outcomes'][1]['category']);

        $gate = $probe->evaluate($run['outcomes'], VerifyTokenLatencyProbe::summary($run['timings_ms']));
        $this->assertSame(1, $gate['exit_code']);
        $this->assertSame(1, $gate['failure_counts']['timeout']);
        $this->assertSame(1, $gate['failure_counts']['http']);
        $this->assertSame(0.5, $gate['timeout_rate']);
    }

    public function testDelayedPromiseHandlerProvesMsConversionAndPoolConcurrencyWithoutSleeping(): void
    {
        $probe = $this->probe([
            'VERIFY_TOKEN_PROBE_REQUESTS' => '3',
            'VERIFY_TOKEN_PROBE_CONCURRENCY' => '2',
        ]);
        $log = ['settled' => 0, 'inflight' => 0, 'max_inflight' => 0];

        $run = $probe->run($this->delayedPromiseHandler($log, 0.25));

        // A fixed 0.25 s TransferStats must surface as exactly 250 ms.
        $this->assertSame([250.0, 250.0, 250.0], $run['timings_ms']);
        $this->assertSame(2, $log['max_inflight']);
        $this->assertSame(5, $log['settled']);
        $this->assertSame(0, $log['inflight']);

        $summary = VerifyTokenLatencyProbe::summary($run['timings_ms']);
        $this->assertSame(250.0, $summary['p95']);
        $this->assertSame(0, $probe->evaluate($run['outcomes'], $summary)['exit_code']);

        $strict = $this->probe([
            'VERIFY_TOKEN_PROBE_REQUESTS' => '3',
            'VERIFY_TOKEN_PROBE_CONCURRENCY' => '2',
            'VERIFY_TOKEN_PROBE_BUDGET_MS' => '200',
        ]);
        $strictLog = ['settled' => 0, 'inflight' => 0, 'max_inflight' => 0];
        $strictRun = $strict->run($this->delayedPromiseHandler($strictLog, 0.25));
        $strictGate = $strict->evaluate($strictRun['outcomes'], VerifyTokenLatencyProbe::summary($strictRun['timings_ms']));
        $this->assertSame(1, $strictGate['exit_code']);
        $this->assertStringContainsString('budget', implode(' ', $strictGate['reasons']));

        $serial = $this->probe(['VERIFY_TOKEN_PROBE_REQUESTS' => '3', 'VERIFY_TOKEN_PROBE_CONCURRENCY' => '1']);
        $serialLog = ['settled' => 0, 'inflight' => 0, 'max_inflight' => 0];
        $serialRun = $serial->run($this->delayedPromiseHandler($serialLog, 0.25));
        $this->assertSame(1, $serialLog['max_inflight']);
        $this->assertSame([250.0, 250.0, 250.0], $serialRun['timings_ms']);
    }

    public function testMissingTokenIsAConfigurationError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('VERIFY_TOKEN_PROBE_TOKEN');
        VerifyTokenLatencyProbe::fromEnvironment([]);
    }

    /** @return array<string, array{int, string}> */
    public static function invalidJwtResponses(): array
    {
        return [
            'static success' => [200, '{"code":0}'],
            'success with data' => [200, '{"code":0,"data":{"personas":[]}}'],
            'bare 401' => [401, 'Unauthorized'],
            '401 success code' => [401, '{"code":0}'],
            '401 unrelated code' => [401, '{"code":9001006}'],
            '401 string code' => [401, '{"code":"1001003"}'],
            'wrong HTTP status' => [403, '{"code":1001003}'],
            'redirect' => [302, '{"code":1001003}'],
            'unavailable' => [503, '{"code":9001006}'],
        ];
    }

    #[DataProvider('invalidJwtResponses')]
    public function testNegativePreflightRejectsTargetsWithoutExplicitAuthenticationFailure(int $status, string $body): void
    {
        $handler = new MockHandler([new Response($status, [], $body)]);
        $requests = 0;
        $run = $this->probe(['VERIFY_TOKEN_PROBE_WARMUP' => '3'])->run(
            static function (RequestInterface $request, array $options) use ($handler, &$requests): PromiseInterface {
                $requests++;
                return $handler($request, $options);
            }
        );

        $this->assertSame([], $run['timings_ms']);
        $this->assertCount(1, $run['outcomes']);
        $this->assertSame('preflight_invalid', $run['outcomes'][0]['phase']);
        $this->assertSame('business', $run['outcomes'][0]['category']);
        $this->assertSame(1, $requests);
        $this->assertCount(0, $handler);
    }

    /** @return array<string, array{string, string}> */
    public static function invalidSuccessData(): array
    {
        return [
            'static success' => ['/api/v1/personas', '{"code":0}'],
            'null data' => ['/api/v1/personas', '{"code":0,"data":null}'],
            'missing personas' => ['/api/v1/personas', '{"code":0,"data":{}}'],
            'object personas' => ['/api/v1/personas', '{"code":0,"data":{"personas":{"bad":1}}}'],
            'empty object personas' => ['/api/v1/personas', '{"code":0,"data":{"personas":{}}}'],
            'null personas' => ['/api/v1/personas', '{"code":0,"data":{"personas":null}}'],
            'missing user' => ['/api/v1/me', '{"code":0,"data":{"personas":[]}}'],
            'empty user id' => ['/api/v1/me', '{"code":0,"data":{"personas":[],"user":{"id":""}}}'],
            'numeric user id' => ['/api/v1/me', '{"code":0,"data":{"personas":[],"user":{"id":123}}}'],
            'business failure' => ['/api/v1/personas', '{"code":7,"message":"unit-test-token-never-printed"}'],
            'invalid JSON' => ['/api/v1/personas', 'not-json'],
        ];
    }

    #[DataProvider('invalidSuccessData')]
    public function testPositivePreflightRequiresTheEndpointDataStructure(string $path, string $body): void
    {
        $handler = new MockHandler([
            new Response(401, [], '{"code":' . ErrorCode::AUTH_TOKEN_INVALID->value . '}'),
            new Response(200, [], $body),
        ]);
        $requests = 0;
        $run = $this->probe(['VERIFY_TOKEN_PROBE_PATH' => $path])->run(
            static function (RequestInterface $request, array $options) use ($handler, &$requests): PromiseInterface {
                $requests++;
                return $handler($request, $options);
            }
        );

        $this->assertSame([], $run['timings_ms']);
        $this->assertSame('preflight_valid', $run['outcomes'][0]['phase']);
        $this->assertSame('business', $run['outcomes'][0]['category']);
        $this->assertStringNotContainsString('unit-test-token-never-printed', $run['outcomes'][0]['message']);
        $this->assertSame(2, $requests);
        $this->assertCount(0, $handler);
    }

    public function testPreflightsUseTheSameTargetBeforeWarmupAndDoNotEnterStatistics(): void
    {
        $seen = [];
        $queue = new MockHandler([
            new Response(401, [], '{"code":' . ErrorCode::AUTH_UNAUTHORIZED->value . '}'),
            new Response(200, [], '{"code":0,"data":{"personas":[]}}'),
            new Response(200, [], '{"code":0}'),
            new Response(200, [], '{"code":0}'),
        ]);
        $handler = static function (RequestInterface $request, array $options) use ($queue, &$seen): PromiseInterface {
            $seen[] = $request;
            return $queue($request, $options);
        };
        $run = $this->probe(['VERIFY_TOKEN_PROBE_WARMUP' => '1', 'VERIFY_TOKEN_PROBE_REQUESTS' => '1'])->run($handler);

        $this->assertCount(4, $seen);
        $this->assertSame('Bearer invalid.jwt.probe', $seen[0]->getHeaderLine('Authorization'));
        foreach ($seen as $index => $request) {
            $this->assertSame('GET', $request->getMethod());
            $this->assertSame('http://127.0.0.1:1/api/v1/personas', (string)$request->getUri());
            if ($index > 0) {
                $this->assertSame('Bearer unit-test-token-never-printed', $request->getHeaderLine('Authorization'));
            }
        }
        $this->assertSame(['warmup', 'measured'], array_column($run['outcomes'], 'phase'));
        $this->assertCount(1, $run['timings_ms']);
    }

    public function testPositivePreflightAcceptsNonEmptyPersonaList(): void
    {
        $run = $this->probe(['VERIFY_TOKEN_PROBE_REQUESTS' => '1'])->run(new MockHandler([
            new Response(401, [], '{"code":' . ErrorCode::AUTH_TOKEN_INVALID->value . '}'),
            new Response(200, [], '{"code":0,"data":{"personas":[{"id":"456"}]}}'),
            new Response(200, [], '{"code":0}'),
        ]));

        $this->assertCount(1, $run['timings_ms']);
        $this->assertSame(['measured'], array_column($run['outcomes'], 'phase'));
    }

    public function testPositivePreflightHttpFailureStopsSampling(): void
    {
        $run = $this->probe()->run(new MockHandler([
            new Response(401, [], '{"code":' . ErrorCode::AUTH_TOKEN_INVALID->value . '}'),
            new Response(503, [], '{"code":' . ErrorCode::SERVICE_UNAVAILABLE->value . '}'),
        ]));

        $this->assertSame([], $run['timings_ms']);
        $this->assertSame('preflight_valid', $run['outcomes'][0]['phase']);
        $this->assertSame('http', $run['outcomes'][0]['category']);
    }

    public function testPreflightTransportFailureStopsSamplingAndRedactsExceptionContext(): void
    {
        $handler = new MockHandler([new ConnectException(
            'timed out: unit-test-token-never-printed https://secret.invalid',
            new Request('GET', 'http://127.0.0.1:1/api/v1/personas'),
            null,
            ['errno' => 28],
        )]);
        $run = $this->probe()->run($handler);

        $this->assertSame([], $run['timings_ms']);
        $this->assertSame('timeout', $run['outcomes'][0]['category']);
        $this->assertSame('ConnectException', $run['outcomes'][0]['message']);
    }

    public function testExecuteFailsWithoutPrintingLatencyWhenAuthenticationPreflightFails(): void
    {
        ob_start();
        $exitCode = $this->probe()->execute(new MockHandler([new Response(200, [], '{"code":0}') ]));
        $stdout = (string)ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertSame('', $stdout);
    }

    public function testInvalidNumericSettingIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('VERIFY_TOKEN_PROBE_REQUESTS');
        $this->probe(['VERIFY_TOKEN_PROBE_REQUESTS' => '0']);
    }
}
