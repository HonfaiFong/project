<?php

declare(strict_types=1);

namespace Tests\Unit;

use RuntimeException;
use Tests\TestCase;

/**
 * Every Snowflake service must be able to reach the shared Redis lease store
 * before RoadRunner starts accepting work.
 */
final class WorkerIdRedisConfigContractTest extends TestCase
{
    /** @var list<string> */
    private const SNOWFLAKE_SERVICES = [
        'auth-service',
        'user-service',
        'content-service',
        'social-service',
        'chat-service',
        'message-service',
    ];

    public function testSnowflakeServicesDeclareRedisLeaseAndHealthDependency(): void
    {
        $compose = $this->read('compose.yaml');

        self::assertStringNotContainsString('各副本须使用不同', $compose);
        self::assertStringNotContainsString('各副本必须使用不同', $compose);

        foreach (self::SNOWFLAKE_SERVICES as $service) {
            $section = $this->serviceSection($compose, $service);

            foreach ([
                '      - REDIS_HOST=redis',
                '      - REDIS_PORT=6379',
                '      - REDIS_PASSWORD=${REDIS_PASSWORD:-}',
            ] as $setting) {
                self::assertStringContainsString($setting, $section, $service . ' must configure ' . $setting);
            }

            self::assertMatchesRegularExpression('/^      - WORKER_ID=\d+$/m', $section);
            self::assertMatchesRegularExpression(
                '/^    depends_on:\s*\R(?:(?!^  [a-zA-Z0-9_-]+:).)*?^      redis:\s*\R^        condition: service_healthy$/ms',
                $section,
                $service . ' must wait for a healthy Redis lease store',
            );
        }
    }

    private function serviceSection(string $compose, string $service): string
    {
        $start = strpos($compose, '  ' . $service . ':');
        if ($start === false) {
            throw new RuntimeException('Service not found in compose.yaml: ' . $service);
        }

        $tail = substr($compose, $start + 2);
        if (preg_match('/^  [a-zA-Z0-9_-]+:/m', $tail, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return $tail;
        }

        return substr($tail, 0, (int)$match[0][1]);
    }

    private function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read ' . $relativePath);
        }

        return $contents;
    }
}
