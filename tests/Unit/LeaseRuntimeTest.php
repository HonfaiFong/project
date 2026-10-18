<?php

declare(strict_types=1);

namespace Tests\Unit;

use Contracts\Gen\Common\V1\HealthCheckRequest;
use Contracts\Gen\Common\V1\HealthStatus;
use Packages\GrpcCommon\Health\RoadRunnerHealthService;
use Packages\GrpcCommon\LeaseMaintainingRelay;
use Packages\IdGenerator\WorkerId;
use PHPUnit\Framework\TestCase;
use Redis;
use RuntimeException;
use Spiral\Goridge\Frame;
use Spiral\Goridge\RelayInterface;
use Spiral\RoadRunner\GRPC\Context;

final class LeaseRuntimeTest extends TestCase
{
    public function testReadinessFailsWhenAnIdleWorkersLeaseIsLost(): void
    {
        $previous = getenv('WORKER_ID');
        putenv('WORKER_ID=31');
        $redis = $this->createMock(Redis::class);
        $redis->method('set')->willReturn(true);
        $redis->method('eval')->willReturn(0);
        $id = WorkerId::resolve($redis);
        try {
            $response = (new RoadRunnerHealthService())->Check(new Context([]), new HealthCheckRequest());
            self::assertSame(HealthStatus::HEALTH_STATUS_NOT_SERVING, $response->getStatus());
        } finally {
            WorkerId::release($id);
            putenv($previous === false ? 'WORKER_ID' : 'WORKER_ID=' . $previous);
        }
    }

    public function testLostLeaseEscapesTheRelaySoRoadRunnerReplacesTheWorker(): void
    {
        $previous = getenv('WORKER_ID');
        putenv('WORKER_ID=31');
        $redis = $this->createMock(Redis::class);
        $redis->method('set')->willReturn(true);
        $redis->method('eval')->willReturn(0);
        $id = WorkerId::resolve($redis);
        try {
            try {
                WorkerId::maintain(force: true);
            } catch (RuntimeException) {
            }
            $relay = $this->createMock(RelayInterface::class);
            $relay->expects(self::never())->method('waitFrame');
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('was lost');
            (new LeaseMaintainingRelay($relay))->waitFrame();
        } finally {
            WorkerId::release($id);
            putenv($previous === false ? 'WORKER_ID' : 'WORKER_ID=' . $previous);
        }
    }

    public function testRelayPreservesIncomingAndOutgoingFrames(): void
    {
        $frame = new Frame('payload');
        $relay = $this->createMock(RelayInterface::class);
        $relay->method('hasFrame')->willReturn(true);
        $relay->expects(self::once())->method('waitFrame')->willReturn($frame);
        $relay->expects(self::once())->method('send')->with($frame);
        $maintained = new LeaseMaintainingRelay($relay);
        self::assertTrue($maintained->hasFrame());
        self::assertSame($frame, $maintained->waitFrame());
        $maintained->send($frame);
    }
}
