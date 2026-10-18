<?php

declare(strict_types=1);

namespace Tests\Unit;

use RuntimeException;
use Tests\TestCase;

/**
 * 验证模拟 OAuth 服务器的一次性授权码约定。
 *
 * 普通 CLI 下 scripts/fake_oauth_server.php 会在请求分发前返回，因此下方子进程针对真实磁盘状态文件调用真实
 * consumeCode()。重点验证竞争：flock 覆盖完整读取、检查和写入流程后，N 个并发进程中只能有一个成功消费授权码；这
 * 与微信/QQ 上游的约束及 scripts/social_e2e_test.sh 的端到端断言一致。
 */
final class FakeOAuthCodeConsumptionTest extends TestCase
{
    private string $stateDir = '';

    /** @var list<resource> */
    private array $processes = [];

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if (is_resource($process)) {
                proc_terminate($process, 9);
                proc_close($process);
            }
        }
        $this->processes = [];

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
        foreach ($processes as $worker) {
            $output = $this->waitForWorker($worker);
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

        // Finish writing the immutable runner before any child can execute it.
        $script = <<<PHP
            <?php
            putenv('FAKE_OAUTH_STATE_DIR=' . \$argv[1]);
            require \$argv[2];
            echo consumeCode('qq', \$argv[3]) ? 'won' : 'lost';
            PHP;
        if (file_put_contents($dir . '/worker.php', $script) !== strlen($script)) {
            throw new RuntimeException('Cannot write fake OAuth worker script');
        }

        return $dir;
    }

    /** @return array{process: resource, stdout: string, stderr: string, deadline: float} */
    private function spawnWorker(string $code): array
    {
        $prefix = $this->stateDir . '/worker-' . count($this->processes);
        $stdout = $prefix . '.stdout';
        $stderr = $prefix . '.stderr';
        // Files avoid pipe-buffer deadlocks and support bounded waits on Windows too.
        $process = proc_open([
            PHP_BINARY,
            $this->stateDir . '/worker.php',
            $this->stateDir,
            dirname(__DIR__, 2) . '/scripts/fake_oauth_server.php',
            $code,
        ], [1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot spawn fake OAuth worker process');
        }
        $this->processes[] = $process;

        return ['process' => $process, 'stdout' => $stdout, 'stderr' => $stderr, 'deadline' => hrtime(true) / 1e9 + 10.0];
    }

    /** @param array{process: resource, stdout: string, stderr: string, deadline: float} $worker */
    private function waitForWorker(array $worker): string
    {
        do {
            $status = proc_get_status($worker['process']);
            if (!$status['running']) {
                break;
            }
            if (hrtime(true) / 1e9 >= $worker['deadline']) {
                $this->fail('fake OAuth worker timed out; stdout: ' . file_get_contents($worker['stdout'])
                    . '; stderr: ' . file_get_contents($worker['stderr']));
            }
            usleep(10_000);
        } while (true);

        // Before PHP 8.3 proc_close() can lose the exit code after proc_get_status().
        $exit = $status['exitcode'];
        proc_close($worker['process']);
        $output = (string)file_get_contents($worker['stdout']);
        $stderr = (string)file_get_contents($worker['stderr']);
        $this->assertSame(0, $exit, 'fake OAuth worker failed; stdout: ' . $output . '; stderr: ' . $stderr);
        $this->assertSame('', $stderr, 'fake OAuth worker printed errors: ' . $stderr);
        $this->assertContains($output, ['won', 'lost'], 'worker printed unexpected output: ' . $output);

        return $output;
    }

    private function consumeInChildWorker(string $code): bool
    {
        $output = $this->waitForWorker($this->spawnWorker($code));

        return $output === 'won';
    }
}
