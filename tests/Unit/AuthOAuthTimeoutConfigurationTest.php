<?php

declare(strict_types=1);

namespace Tests\Unit;

use GuzzleHttp\Client;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Services\AuthService\Infrastructure\OAuth\GuzzleOAuthHttpClient;
use Tests\TestCase;

final class AuthOAuthTimeoutConfigurationTest extends TestCase
{
    #[DataProvider('invalidEnvironment')]
    public function testBootstrapRejectsInvalidOAuthTimeoutsBeforeConnectingToInfrastructure(string $name, string $value): void
    {
        $environment = getenv();
        $environment['GRPC_AUTH_TIMEOUT_MS'] = '300';
        $environment['GRPC_USER_DIRECTORY_TIMEOUT_MS'] = '250';
        $environment['SOCIAL_HTTP_CONNECT_TIMEOUT_MS'] = '1000';
        $environment['SOCIAL_HTTP_TIMEOUT_MS'] = '3000';
        $environment[$name] = $value;
        $process = proc_open(
            // Assert the bootstrap error independently of the host php.ini output settings.
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'html_errors=0',
                '-d', 'error_reporting=-1', dirname(__DIR__, 2) . '/services/auth-service/bin/server.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        self::assertNotSame(0, $exit);
        self::assertStringContainsString('InvalidArgumentException', $stdout . $stderr);
        self::assertStringContainsString($name, $stdout . $stderr);
        self::assertStringNotContainsString('Waiting for MySQL', $stdout . $stderr);
    }

    /** @return list<array{string, string}> */
    public static function invalidEnvironment(): array
    {
        $cases = [];
        foreach (['SOCIAL_HTTP_CONNECT_TIMEOUT_MS', 'SOCIAL_HTTP_TIMEOUT_MS'] as $name) {
            foreach (['0', '-1', 'abc', '1000ms', '1.5'] as $value) {
                $cases[] = [$name, $value];
            }
        }
        $cases[] = ['SOCIAL_HTTP_CONNECT_TIMEOUT_MS', '3001'];

        return $cases;
    }

    #[DataProvider('invalidClientTimeouts')]
    public function testOAuthClientAlsoRejectsInvalidTypedTimeouts(int $connectMs, int $totalMs): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GuzzleOAuthHttpClient(new Client(), $connectMs, $totalMs);
    }

    /** @return list<array{int, int}> */
    public static function invalidClientTimeouts(): array
    {
        return [[0, 3000], [1000, 0], [-1, 3000], [3001, 3000]];
    }

    public function testEqualConnectAndTotalTimeoutsAreAllowed(): void
    {
        $this->assertInstanceOf(GuzzleOAuthHttpClient::class, new GuzzleOAuthHttpClient(new Client(), 1000, 1000));
    }
}
