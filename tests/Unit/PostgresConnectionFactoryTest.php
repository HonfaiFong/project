<?php

declare(strict_types=1);

namespace Tests\Unit;

use Packages\Database\PostgresConnectionFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PostgresConnectionFactoryTest extends TestCase
{
    public function testRejectsUnsupportedSchemaPrefixBeforeConnecting(): void
    {
        try {
            PostgresConnectionFactory::fromEnvironment([
                'PG_HOST' => '127.0.0.1',
                'PG_PORT' => '5432',
                'PG_DATABASE' => 'social_backend',
                'PG_USER' => 'test_user',
                'PG_SCHEMA' => 'chat_db',
                'PG_SCHEMA_PREFIX' => 'tenant_',
            ]);
            self::fail('Schema prefixes are unsupported for fixed PostgreSQL service schemas');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('PG_SCHEMA_PREFIX is unsupported', $exception->getMessage());
        }
    }
}
