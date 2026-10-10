<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

final class GatewayConfigurationTest extends TestCase
{
    /** @var list<string> */
    private const MANAGED_ENVIRONMENT_VARIABLES = [
        'APP_ENV',
        'SOCIAL_TRUSTED_PROXY_CIDRS',
        'REDIS_HOST',
        'REDIS_PORT',
        'REDIS_PASSWORD',
        'GRPC_AUTH_SERVICE',
        'GRPC_USER_SERVICE',
        'GRPC_AUTH_TIMEOUT_MS',
        'GRPC_SOCIAL_LOGIN_TIMEOUT_MS',
        'GRPC_SOCIAL_BIND_TIMEOUT_MS',
        'SOCIAL_HTTP_TIMEOUT_MS',
        'GRPC_AUTH_TOKEN',
        'GRPC_USER_TOKEN',
        'SOCIAL_LOGIN_RATE_LIMIT',
        'SOCIAL_LOGIN_RATE_WINDOW_SECONDS',
    ];

    /** @var array<string, string|false> */
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::MANAGED_ENVIRONMENT_VARIABLES as $name) {
            $this->previousEnvironment[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $name => $value) {
            $value === false ? putenv($name) : putenv($name . '=' . $value);
        }

        parent::tearDown();
    }

    public function testGatewayConfigKeepsTheContainerDefaults(): void
    {
        $config = $this->loadGatewayConfig();

        $this->assertSame('production', $config['environment']);
        $this->assertSame([], $config['trusted_proxy_cidrs']);
        $this->assertSame([
            'host' => '',
            'port' => 6379,
            'password' => '',
            'connect_timeout' => 1.0,
            'read_timeout' => 1.0,
        ], $config['redis']);
        $this->assertSame('auth-service:50051', $config['grpc']['auth']['target']);
        $this->assertSame(300, $config['grpc']['auth']['timeout_ms']);
        $this->assertSame(7000, $config['grpc']['auth']['social_login_timeout_ms']);
        $this->assertSame(7000, $config['grpc']['auth']['social_bind_timeout_ms']);
        $this->assertSame(3000, $config['grpc']['auth']['social_http_timeout_ms']);
        $this->assertNull($config['grpc']['auth']['service_token']);
        $this->assertSame('user-service:50052', $config['grpc']['user']['target']);
        $this->assertNull($config['grpc']['user']['service_token']);
        $this->assertSame(30, $config['social_login_rate_limit']['limit']);
        $this->assertSame(60, $config['social_login_rate_limit']['window_seconds']);
    }

    public function testEnvironmentVariablesOverrideTheGatewayConfig(): void
    {
        putenv('APP_ENV=Testing');
        putenv('SOCIAL_TRUSTED_PROXY_CIDRS=10.0.0.0/8, 192.168.0.0/16 ,');
        putenv('REDIS_HOST= redis.internal ');
        putenv('REDIS_PORT=6380');
        putenv('REDIS_PASSWORD= secret ');
        putenv('GRPC_AUTH_SERVICE=auth.internal:50051');
        putenv('GRPC_AUTH_TIMEOUT_MS=450');
        putenv('GRPC_SOCIAL_LOGIN_TIMEOUT_MS=7100');
        putenv('GRPC_SOCIAL_BIND_TIMEOUT_MS=7200');
        putenv('SOCIAL_HTTP_TIMEOUT_MS=3100');
        putenv('GRPC_AUTH_TOKEN= auth-token ');
        putenv('GRPC_USER_SERVICE=user.internal:50052');
        putenv('GRPC_USER_TOKEN= user-token ');
        putenv('SOCIAL_LOGIN_RATE_LIMIT=5');
        putenv('SOCIAL_LOGIN_RATE_WINDOW_SECONDS=30');

        $config = $this->loadGatewayConfig();

        $this->assertSame('development', $config['environment']);
        $this->assertSame(['10.0.0.0/8', '192.168.0.0/16'], $config['trusted_proxy_cidrs']);
        $this->assertSame('redis.internal', $config['redis']['host']);
        $this->assertSame(6380, $config['redis']['port']);
        $this->assertSame('secret', $config['redis']['password']);
        $this->assertSame('auth.internal:50051', $config['grpc']['auth']['target']);
        $this->assertSame(450, $config['grpc']['auth']['timeout_ms']);
        $this->assertSame(7100, $config['grpc']['auth']['social_login_timeout_ms']);
        $this->assertSame(7200, $config['grpc']['auth']['social_bind_timeout_ms']);
        $this->assertSame(3100, $config['grpc']['auth']['social_http_timeout_ms']);
        $this->assertSame('auth-token', $config['grpc']['auth']['service_token']);
        $this->assertSame('user.internal:50052', $config['grpc']['user']['target']);
        $this->assertSame('user-token', $config['grpc']['user']['service_token']);
        $this->assertSame(5, $config['social_login_rate_limit']['limit']);
        $this->assertSame(30, $config['social_login_rate_limit']['window_seconds']);
    }

    public function testEnvironmentNamesAreNormalizedBeforeTheBehaviourIsChosen(): void
    {
        putenv('APP_ENV=  TeStInG ');
        $this->assertSame('development', $this->loadGatewayConfig()['environment']);

        putenv('APP_ENV=staging');
        $this->assertSame('production', $this->loadGatewayConfig()['environment']);
    }

    public function testEnvironmentIsResolvedWhenTheConfigFileIsLoaded(): void
    {
        putenv('APP_ENV=local');

        $loaded = $this->loadGatewayConfig();

        putenv('APP_ENV=production');

        $this->assertSame('development', $loaded['environment']);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadGatewayConfig(): array
    {
        $loader = static function (): array {
            return include dirname(__DIR__, 2) . '/apps/api-gateway/config/gateway.php';
        };

        return $loader();
    }
}
