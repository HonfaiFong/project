<?php

declare(strict_types=1);

namespace Tests\Unit;

use Packages\Logging\JsonLogger;
use Packages\Tracing\TraceContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\InvalidArgumentException;

final class JsonLoggerContractTest extends TestCase
{
    protected function tearDown(): void
    {
        TraceContext::reset();
    }

    public function testPsrLoggerWritesStructuredAndRedactedEntry(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        $logger = new JsonLogger('test', 'one', $stream);
        self::assertInstanceOf(LoggerInterface::class, $logger);

        $logger->warning('request failed', ['token' => 'secret-value']);
        rewind($stream);
        $entry = json_decode((string)stream_get_contents($stream), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('WARNING', $entry['level']);
        self::assertSame('request failed', $entry['message']);
        self::assertSame('[REDACTED]', $entry['context']['token']);
        fclose($stream);
    }

    public function testUnsupportedLevelIsRejected(): void
    {
        $logger = new JsonLogger('test');
        $this->expectException(InvalidArgumentException::class);
        $logger->log('TRACE', 'not a PSR level');
    }
}
