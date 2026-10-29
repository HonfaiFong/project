<?php

declare(strict_types=1);

namespace Tests\Integration;

use Contracts\Gen\Common\V1\HealthCheckRequest;
use Contracts\Gen\Common\V1\HealthCheckResponse;
use Contracts\Gen\Common\V1\HealthStatus;
use Packages\GrpcCommon\GrpcCallException;
use Packages\GrpcCommon\UnaryGrpcClient;
use Packages\GrpcCommon\GrpcClientContext;
use PHPUnit\Framework\TestCase;

final class HealthRetryTest extends TestCase
{
    public function testNativeHealthRetryIsLimitedToTransportFailureAndTheOriginalDeadline(): void
    {
        $binary = getenv('ROADRUNNER_BINARY') ?: '/usr/local/bin/rr';
        if (PHP_OS_FAMILY === 'Windows' || !extension_loaded('grpc') || !extension_loaded('sockets') || !is_executable($binary)) {
            self::markTestSkipped('Requires the Linux RoadRunner and gRPC runtime');
        }
        $address = $this->availableAddress();
        $proxyAddress = $this->availableAddress();
        $directory = sys_get_temp_dir() . '/health-retry-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $root = dirname(__DIR__, 2);
        $configuration = "version: '3'\nserver:\n  command: php {$root}/tests/Integration/fixtures/HealthRetryWorker.php\n  relay: pipes\ngrpc:\n  listen: tcp://{$address}\n  proto:\n    - {$root}/contracts/proto/common/v1/health.proto\n  pool:\n    num_workers: 1\nlogs:\n  level: error\n";
        file_put_contents($directory . '/rr.yaml', $configuration);
        $process = proc_open([$binary, 'serve', '-c', $directory . '/rr.yaml'], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $directory . '/rr.log', 'a'],
            2 => ['file', $directory . '/rr.log', 'a'],
        ], $pipes);
        self::assertIsResource($process);
        $proxy = null;
        try {
            $backend = new UnaryGrpcClient($address);
            self::assertTrue($backend->waitForReady(5_000_000), (string)file_get_contents($directory . '/rr.log'));
            $proxy = proc_open([PHP_BINARY, $root . '/tests/Integration/fixtures/HealthRetryProxy.php', $proxyAddress, $address, $directory], [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $directory . '/proxy.log', 'a'],
                2 => ['file', $directory . '/proxy.log', 'a'],
            ], $pipes);
            self::assertIsResource($proxy);
            for ($attempt = 0; $attempt < 100 && !is_file($directory . '/proxy-ready'); $attempt++) {
                usleep(10_000);
            }
            self::assertFileExists($directory . '/proxy-ready');
            // Negative control: the original fail-fast channel exposes the reset.
            $plainChannel = new \Grpc\Channel($proxyAddress, ['credentials' => \Grpc\ChannelCredentials::createInsecure()]);
            $plainCheck = static function (int $timeoutMs = 300) use ($plainChannel): int {
                $options = GrpcClientContext::createCallOptions($timeoutMs);
                $call = new \Grpc\Call($plainChannel, '/common.v1.HealthService/Check', \Grpc\Timeval::now()->add(new \Grpc\Timeval($options['timeout'])));
                $call->startBatch([
                    \Grpc\OP_SEND_INITIAL_METADATA => $options['metadata'],
                    \Grpc\OP_SEND_MESSAGE => ['message' => (new HealthCheckRequest())->serializeToString(), 'flags' => 0],
                    \Grpc\OP_SEND_CLOSE_FROM_CLIENT => true,
                ]);
                $result = $call->startBatch([
                    \Grpc\OP_RECV_INITIAL_METADATA => true,
                    \Grpc\OP_RECV_MESSAGE => true,
                    \Grpc\OP_RECV_STATUS_ON_CLIENT => true,
                ]);
                return $result->status->code;
            };
            // A listening RoadRunner socket does not mean its PHP worker has finished cold startup.
            // Warm the fixture before checking reset/retry behavior under the original short deadline.
            self::assertSame(\Grpc\STATUS_OK, $plainCheck(5000));
            file_put_contents($directory . '/reset', '1');
            self::assertSame(\Grpc\STATUS_UNAVAILABLE, $plainCheck());

            $client = new UnaryGrpcClient($proxyAddress);
            self::assertTrue($client->waitForReady(5_000_000), (string)file_get_contents($directory . '/rr.log'));
            $this->check($client, 'warm');
            file_put_contents($directory . '/reset', '1');
            $recovered = $this->check($client, 'after-reset');
            self::assertSame(HealthStatus::HEALTH_STATUS_SERVING, $recovered->getStatus());
            self::assertSame('1', $recovered->getMessage());

            foreach (['always-unavailable' => \Grpc\STATUS_UNAVAILABLE, 'unauthenticated' => \Grpc\STATUS_UNAUTHENTICATED] as $scenario => $expected) {
                try {
                    $this->check($client, $scenario);
                    self::fail('Expected gRPC failure');
                } catch (GrpcCallException $exception) {
                    self::assertSame($expected, $exception->grpcStatus);
                }
            }
            // RoadRunner's application errors commit response headers and must not retry.
            self::assertSame('1', $this->check($client, 'count:always-unavailable')->getMessage());
            self::assertSame('1', $this->check($client, 'count:unauthenticated')->getMessage());
            self::assertSame(HealthStatus::HEALTH_STATUS_NOT_SERVING, $this->check($client, 'not-serving')->getStatus());
            self::assertSame('1', $this->check($client, 'count:not-serving')->getMessage());

            $start = hrtime(true);
            file_put_contents($directory . '/reset', '1');
            try {
                $this->check($client, 'slow', 100);
                self::fail('Expected the original deadline to expire');
            } catch (GrpcCallException $exception) {
                self::assertSame(\Grpc\STATUS_DEADLINE_EXCEEDED, $exception->grpcStatus);
            }
            self::assertLessThan(0.3, (hrtime(true) - $start) / 1e9);

            $unreachable = new UnaryGrpcClient($this->availableAddress());
            $start = hrtime(true);
            try {
                $this->check($unreachable, 'unreachable', 100);
                self::fail('An unavailable server must still fail within the original deadline');
            } catch (GrpcCallException $exception) {
                self::assertSame(\Grpc\STATUS_DEADLINE_EXCEEDED, $exception->grpcStatus);
            }
            self::assertLessThan(0.3, (hrtime(true) - $start) / 1e9);
        } finally {
            if (is_resource($proxy)) {
                proc_terminate($proxy);
                proc_close($proxy);
            }
            proc_terminate($process);
            proc_close($process);
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private function availableAddress(): string
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($listener);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        self::assertIsString($address);
        return $address;
    }

    private function check(UnaryGrpcClient $client, string $scenario, int $timeoutMs = 300): HealthCheckResponse
    {
        return $client->call('/common.v1.HealthService/Check', new HealthCheckRequest(['service' => $scenario]), HealthCheckResponse::class, $timeoutMs);
    }
}
