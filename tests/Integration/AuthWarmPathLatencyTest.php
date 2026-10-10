<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Database\Schema\Blueprint;
use Packages\Database\EloquentConnection;
use Packages\IdGenerator\Snowflake;
use PDO;
use Redis;
use RuntimeException;
use Services\AuthService\Application\Service\AuthApplicationService;
use Services\AuthService\Domain\Client\UserDirectoryInterface;
use Services\AuthService\Infrastructure\Auth\LoginCredentialVerifier;
use Services\AuthService\Infrastructure\Cache\RedisAccessTokenRevocationStore;
use Services\AuthService\Infrastructure\Model\Device as DeviceModel;
use Services\AuthService\Infrastructure\Repository\EloquentAuthRepository;
use Services\AuthService\Infrastructure\Token\JwtTokenCodec;
use Services\AuthService\Infrastructure\Token\WsTicketIssuer;
use Tests\TestCase;
use Throwable;

/**
 * 测量 VerifyToken 预热后单次调用工作在真实基础设施上的 P50/P95/P99，补充仅测量类似编解码工作负载的
 * GrpcDeadlineBudgetBenchmarkTest。网关为完整认证调用提供 GRPC_AUTH_TIMEOUT_MS = 300 毫秒，auth->user 用户目录调
 * 用最多消耗 250 毫秒，因此这两次查询及编解码工作须将 P95 控制在剩余约 50 毫秒内；此处性能退化会使截止时间传播压
 * 缩目录调用预算，导致所有受保护请求返回 HTTP 503。
 *
 * 每次测量均输出百分位报告；断言有意留出余量，因为 CI 运行器共享 CPU，主要捕获索引丢失或每次调用重新连接等数量级
 * 性能退化。
 */
final class AuthWarmPathLatencyTest extends TestCase
{
    private const WARM_ITERATIONS = 200;
    private const AUTH_SIDE_BUDGET_MS = 50;

    private PDO $pdo;

    private Redis $redis;

    /** @var list<string> */
    private array $redisKeys = [];

    protected function setUp(): void
    {
        $database = getenv('SOCIAL_TEST_MYSQL_DATABASE');
        if ($database === false || $database === '') {
            $this->markTestSkipped('SOCIAL_TEST_MYSQL_DATABASE is not configured');
        }
        if (!str_starts_with($database, 'social_test_')) {
            throw new RuntimeException('SOCIAL_TEST_MYSQL_DATABASE must use the social_test_ prefix');
        }

        try {
            $this->pdo = new PDO(
                sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    getenv('SOCIAL_TEST_MYSQL_HOST') ?: '127.0.0.1',
                    (int)(getenv('SOCIAL_TEST_MYSQL_PORT') ?: 3306),
                    $database,
                ),
                getenv('SOCIAL_TEST_MYSQL_USER') ?: 'root',
                getenv('SOCIAL_TEST_MYSQL_PASSWORD') ?: '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2],
            );
        } catch (Throwable $e) {
            $this->markTestSkipped('MySQL integration service is not available: ' . $e->getMessage());
        }

        $this->redis = new Redis();
        try {
            $connected = $this->redis->connect(
                getenv('SOCIAL_TEST_REDIS_HOST') ?: '127.0.0.1',
                (int)(getenv('SOCIAL_TEST_REDIS_PORT') ?: 6379),
                1.0,
            );
            if (!$connected || !$this->redis->ping()) {
                $this->markTestSkipped('Redis integration service is not available');
            }
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis integration service is not available: ' . $e->getMessage());
        }

        $schema = EloquentConnection::fromPdo($this->pdo)->getSchemaBuilder();
        $schema->dropIfExists('devices');
        $schema->create('devices', static function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('device_uuid', 64);
            $table->string('platform', 16);
            $table->string('app_version', 32);
            $table->string('push_token', 255)->nullable();
            $table->binary('last_ip', 16)->nullable();
            $table->dateTime('last_active_at', 3);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->unique('device_uuid', 'uniq_device_uuid');
            $table->index('user_id', 'idx_user_id');
        });

        // Device 模型延迟初始化；测量前先预热，使结果反映已预热 RoadRunner 工作进程实际提供的路径。
        EloquentConnection::warmup($this->pdo, DeviceModel::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            foreach ($this->redisKeys as $key) {
                $this->redis->del($key);
            }
        }
        if (isset($this->pdo)) {
            EloquentConnection::fromPdo($this->pdo)->getSchemaBuilder()->dropIfExists('devices');
            EloquentConnection::forget($this->pdo);
        }

        parent::tearDown();
    }

    /** @throws Throwable */
    public function testWarmVerifyTokenPathQueriesKeepP95InsideTheAuthSideBudget(): void
    {
        $now = date('Y-m-d H:i:s.v');
        EloquentConnection::model(DeviceModel::class, $this->pdo)->newQuery()->insert([
            'id'              => 4242424242,
            'user_id'         => 7373737373,
            'device_uuid'     => 'latency-device',
            'platform'        => 'ios',
            'app_version'     => '1.0.0',
            'last_active_at'  => $now,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        $repository = new EloquentAuthRepository($this->pdo, new Snowflake(31));
        $deviceQuery = static fn (): bool => $repository->deviceBelongsToUser('7373737373', '4242424242');

        $revocationStore = new RedisAccessTokenRevocationStore($this->redis);
        $tokenHash = hash('sha256', 'latency-probe-token');
        $this->redisKeys[] = 'auth:access-token-revoked:' . $tokenHash;
        $this->redisKeys[] = 'auth:access-device-revoked:4242424242';
        $revocationQuery = static fn (): bool => $revocationStore->isRevoked($tokenHash, '4242424242', time() - 60);

        $coldMs = $this->measureOnce($deviceQuery);

        $deviceTimings = $this->measure($deviceQuery);
        $redisTimings = $this->measure($revocationQuery);

        printf(
            "Auth warm-path latency report (%d iterations):\n" .
            "  cold first device-ownership query: %8.2f ms\n" .
            "  eloquent deviceBelongsToUser:     p50=%6.2f ms  p95=%6.2f ms  p99=%6.2f ms  (budget %d ms)\n" .
            "  redis isRevoked mGet:             p50=%6.2f ms  p95=%6.2f ms  p99=%6.2f ms\n",
            self::WARM_ITERATIONS,
            $coldMs,
            $this->percentile($deviceTimings, 0.50),
            $this->percentile($deviceTimings, 0.95),
            $this->percentile($deviceTimings, 0.99),
            self::AUTH_SIDE_BUDGET_MS,
            $this->percentile($redisTimings, 0.50),
            $this->percentile($redisTimings, 0.95),
            $this->percentile($redisTimings, 0.99),
        );

        $this->assertTrue($deviceQuery());
        $this->assertFalse($revocationQuery());
        $this->assertLessThan(
            self::AUTH_SIDE_BUDGET_MS,
            $this->percentile($deviceTimings, 0.95),
            'P95 of the warm device-ownership query exceeded the auth-side deadline budget'
        );
        $this->assertLessThan(
            self::AUTH_SIDE_BUDGET_MS,
            $this->percentile($redisTimings, 0.95),
            'P95 of the warm revocation lookup exceeded the auth-side deadline budget'
        );
    }

    /**
     * 测量一次真实 AuthApplicationService::verifyToken() 的认证侧累计工作：JWT 解码、Redis 撤销查询、MySQL 设备归属
     * 查询及用户目录状态检查。真实 auth->user 调用是网络 RPC，因此将 UserDirectory 桩设为 ACTIVE；50 毫秒预算适用于
     * 认证侧累计耗时，而非各部分单独耗时。
     */
    public function testCumulativeVerifyTokenAuthSideWorkKeepsP95InsideTheAuthSideBudget(): void
    {
        $now = date('Y-m-d H:i:s.v');
        EloquentConnection::model(DeviceModel::class, $this->pdo)->newQuery()->insert([
            'id'             => 5252525252,
            'user_id'        => 8484848484,
            'device_uuid'    => 'latency-verify-token-device',
            'platform'       => 'ios',
            'app_version'    => '1.0.0',
            'last_active_at' => $now,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        $codec = new JwtTokenCodec('warm-path-verify-token-jwt-secret-1234567890');
        $service = new AuthApplicationService(
            authRepo: new EloquentAuthRepository($this->pdo, new Snowflake(31)),
            jwtCodec: $codec,
            wsTicketIssuer: new WsTicketIssuer('warm-path-verify-token-ws-secret-1234567890', 60),
            credentialVerifier: new LoginCredentialVerifier('local', 'unused-code', null, true),
            userDirectory: new class implements UserDirectoryInterface {
                public function findOrCreateByPhone(string $phone, string $registerChannel = 'default'): array
                {
                    throw new RuntimeException('findOrCreateByPhone is not used by verifyToken');
                }

                public function findOrCreateBySocialIdentity(string $registrationKey, string $registerChannel): array
                {
                    throw new RuntimeException('findOrCreateBySocialIdentity is not used by verifyToken');
                }

                public function findById(string $userId): array
                {
                    // 测试数据约定：JWT 主体、设备行 user_id 及此桩均对应用户 8484848484 / 设备 5252525252。
                    return ['user_id' => $userId, 'status' => UserDirectoryInterface::STATUS_ACTIVE];
                }
            },
            accessTokenRevocationStore: new RedisAccessTokenRevocationStore($this->redis),
        );

        $token = $codec->encode([
            'sub'       => '8484848484',
            'device_id' => '5252525252',
            'iat'       => time(),
            'exp'       => time() + 300,
        ]);
        $this->redisKeys[] = 'auth:access-token-revoked:' . hash('sha256', $token);
        $this->redisKeys[] = 'auth:access-device-revoked:5252525252';

        $verify = static fn (): array => $service->verifyToken($token);

        // setUp() 已预热模型，上方也已编码令牌，因此首个样本不是工作进程冷启动；仅作为参考报告。
        $firstMs = $this->measureOnce($verify);

        $timings = $this->measure($verify);

        printf(
            "Auth warm-path cumulative verifyToken report (%d iterations):\n" .
            "  first verifyToken call before sampling: %8.2f ms\n" .
            "  cumulative auth-side verifyToken:   p50=%6.2f ms  p95=%6.2f ms  p99=%6.2f ms  (budget %d ms)\n" .
            "  scope: JWT decode + Redis revocation + MySQL device ownership + UserDirectory stub;\n" .
            "         the real user-service network hop is NOT included, so the budget covers the\n" .
            "         cumulative auth-side number, not each part in isolation.\n",
            self::WARM_ITERATIONS,
            $firstMs,
            $this->percentile($timings, 0.50),
            $this->percentile($timings, 0.95),
            $this->percentile($timings, 0.99),
            self::AUTH_SIDE_BUDGET_MS,
        );

        $this->assertSame(
            ['valid' => true, 'user_id' => '8484848484', 'device_id' => '5252525252'],
            $verify()
        );
        $this->assertLessThan(
            self::AUTH_SIDE_BUDGET_MS,
            $this->percentile($timings, 0.95),
            'P95 of the cumulative warm verifyToken auth-side work exceeded the auth-side deadline budget'
        );
    }

    /** @return list<float> */
    private function measure(callable $operation): array
    {
        // 执行几次不计入统计的迭代，吸收连接建立的尾部开销。
        for ($i = 0; $i < 10; $i++) {
            $operation();
        }

        $timings = [];
        for ($i = 0; $i < self::WARM_ITERATIONS; $i++) {
            $timings[] = $this->measureOnce($operation);
        }

        return $timings;
    }

    private function measureOnce(callable $operation): float
    {
        $start = hrtime(true);
        $operation();

        return (hrtime(true) - $start) / 1e6;
    }

    /** @param list<float> $timings */
    private function percentile(array $timings, float $quantile): float
    {
        sort($timings);
        $index = (int)ceil($quantile * count($timings)) - 1;

        return $timings[max(0, min($index, count($timings) - 1))];
    }
}
