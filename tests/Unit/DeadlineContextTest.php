<?php

declare(strict_types=1);

namespace Tests\Unit;

use Packages\GrpcCommon\DeadlineContext;
use Packages\GrpcCommon\GrpcClientContext;
use Tests\TestCase;

final class DeadlineContextTest extends TestCase
{
    protected function tearDown(): void
    {
        DeadlineContext::reset();

        parent::tearDown();
    }

    public function testNoPropagatedDeadlineLeavesTheConfiguredBudgetUnchanged(): void
    {
        DeadlineContext::reset();

        $this->assertNull(DeadlineContext::remainingMs());
        $this->assertSame(250, DeadlineContext::clampHopBudgetMs(250));
    }

    public function testRemainingDeadlineShrinksButNeverExtendsTheConfiguredBudget(): void
    {
        DeadlineContext::setCurrent(DeadlineContext::nowEpochMs() + 10_000);

        $this->assertLessThanOrEqual(10_000, DeadlineContext::remainingMs());
        $this->assertSame(250, DeadlineContext::clampHopBudgetMs(250));

        DeadlineContext::setCurrent(DeadlineContext::nowEpochMs() + 40);

        $this->assertSame(40, DeadlineContext::clampHopBudgetMs(250));
    }

    public function testAnExpiredDeadlineFailsFastInsteadOfSpendingAFreshBudget(): void
    {
        DeadlineContext::setCurrent(DeadlineContext::nowEpochMs());

        $this->assertSame(0, DeadlineContext::remainingMs());
        $this->assertNull(DeadlineContext::clampHopBudgetMs(250));
    }

    public function testClientCallOptionsPropagateTheAbsoluteDeadline(): void
    {
        $before = DeadlineContext::nowEpochMs();
        $options = GrpcClientContext::createCallOptions(300);
        $after = DeadlineContext::nowEpochMs();

        $deadline = (int)($options['metadata']['x-deadline-unix-ms'][0] ?? '0');
        $this->assertGreaterThanOrEqual($before + 300, $deadline);
        $this->assertLessThanOrEqual($after + 300, $deadline);
    }
}
