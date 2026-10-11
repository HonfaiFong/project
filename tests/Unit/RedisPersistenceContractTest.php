<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

final class RedisPersistenceContractTest extends TestCase
{
    public function testComposeKeepsRevocationStateOnAofBackedNamedVolume(): void
    {
        $compose = file_get_contents(dirname(__DIR__, 2) . '/compose.yaml');
        self::assertIsString($compose);

        $inRedisService = false;
        $redisCommand = null;
        $redisVolumeMounted = false;

        foreach (preg_split('/\R/', $compose) as $line) {
            if ($line === '  redis:') {
                $inRedisService = true;
                continue;
            }

            if ($inRedisService && preg_match('/^  [A-Za-z0-9_-]+:\s*$/', $line) === 1) {
                break;
            }

            if (!$inRedisService) {
                continue;
            }

            if (preg_match('/^\s+command:\s*(.+)$/', $line, $matches) === 1) {
                $redisCommand = $matches[1];
            }
            if (trim($line) === '- redis_data:/data') {
                $redisVolumeMounted = true;
            }
        }

        self::assertIsString($redisCommand, 'Redis must declare an explicit server command.');
        self::assertStringContainsString('--appendonly yes', $redisCommand);
        self::assertStringContainsString('--appendfsync everysec', $redisCommand);
        self::assertStringContainsString('--no-appendfsync-on-rewrite no', $redisCommand);
        self::assertStringNotContainsString('--appendonly no', $redisCommand);
        self::assertTrue($redisVolumeMounted, 'Redis must keep its /data directory on the named redis_data volume.');
    }
}
