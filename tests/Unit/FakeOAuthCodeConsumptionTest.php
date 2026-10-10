<?php

declare(strict_types=1);

namespace Tests\Unit;

use RuntimeException;
use Tests\TestCase;

/**
 * Guards the fake OAuth server's single-use authorization-code contract.
 *
 * scripts/fake_oauth_server.php returns before request dispatch under plain
 * CLI, so the child workers below exercise the real consumeCode() against a
 * real on-disk state file. The race is the point: with the flock covering the
 * whole read-check-write cycle, exactly one of N concurrent workers may win a
 * code — the same invariant the WeChat/QQ upstreams enforce and
 * scripts/social_e2e_test.sh asserts end to end.
 */
final class FakeOAuthCodeConsumptionTest extends TestCase
{
    private string $stateDir = '';

    protected function tearDown(): void
    {
        if ($this->stateDir !== '') {
            foreach (glob($this->stateDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->stateDir);
            putenv('FAKE_OAUTH_STATE_DIR');
        }

        parent::tearDown();
    }

    public function testSequentialRedemptionsBurnTheCodeAfterTheFirstAttempt(): void
    {
        $this->stateDir = $this->freshStateDir();

        $first = $this->consumeInChildWorker('wechat-primary-seq~first');
        $second = $this->consumeInChildWorker('wechat-primary-seq~first');

        $this->assertTrue($first);
        $this->assertFalse($second);
    }

    public function testConcurrentWorkersRedeemingTheSameCodeProduceExactlyOneWinner(): void
    {
        $this->stateDir = $this->freshStateDir();
        $code = 'qq-primary-race~' . bin2hex(random_bytes(4));
        $workers = 8;

        $processes = [];
        for ($i = 0; $i < $workers; $i++) {
            $processes[] = $this->spawnWorker($code);
        }

        $wins = 0;
        foreach ($processes as [$process, $stdout]) {
            $output = (string)stream_get_contents($stdout);
            fclose($stdout);
            $exit = proc_close($process);
            $this->assertSame(0, $exit, 'fake OAuth worker failed: ' . $output);
            $this->assertContains($output, ['won', 'lost'], 'worker printed unexpected output: ' . $output);
            $wins += $output === 'won' ? 1 : 0;
        }

        $this->assertSame(1, $wins, 'exactly one concurrent redemption must succeed, got ' . $wins);
    }

    private function freshStateDir(): string
    {
        $dir = sys_get_temp_dir() . '/social-e2e-oauth-consume-test-' . bin2hex(random_bytes(6));
        if (!mkdir($dir) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create isolated fake OAuth state dir: ' . $dir);
        }

        return $dir;
    }

    /** @return array{0: resource, 1: resource} */
    private function spawnWorker(string $code): array
    {
        $runner = $this->stateDir . '/worker.php';
        file_put_contents($runner, <<<PHP
            <?php
            putenv('FAKE_OAUTH_STATE_DIR=' . \$argv[1]);
            require \$argv[2];
            echo consumeCode('qq', \$argv[3]) ? 'won' : 'lost';
            PHP);

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' '
            . escapeshellarg($this->stateDir) . ' '
            . escapeshellarg(dirname(__DIR__, 2) . '/scripts/fake_oauth_server.php') . ' '
            . escapeshellarg($code) . ' 2>&1';

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot spawn fake OAuth worker process');
        }
        stream_set_blocking($pipes[2], false);

        return [$process, $pipes[1]];
    }

    private function consumeInChildWorker(string $code): bool
    {
        [$process, $stdout] = $this->spawnWorker($code);
        $output = (string)stream_get_contents($stdout);
        fclose($stdout);
        $exit = proc_close($process);
        if ($exit !== 0) {
            throw new RuntimeException('fake OAuth worker failed: ' . $output);
        }

        return $output === 'won';
    }
}
