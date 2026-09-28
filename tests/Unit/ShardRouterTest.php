<?php

declare(strict_types=1);

namespace Tests\Unit;

use Packages\GrpcCommon\ShardRouter;
use Tests\TestCase;

final class ShardRouterTest extends TestCase
{
    public function testSlotDistributionIsWithin256(): void
    {
        $router = new ShardRouter();

        for ($i = 1; $i <= 50; $i++) {
            $slot = $router->getSlot("conv_{$i}");
            $this->assertGreaterThanOrEqual(0, $slot);
            $this->assertLessThan(256, $slot);
        }
    }

    public function testDeterministicSlotRouting(): void
    {
        $router = new ShardRouter();
        $slotA = $router->getSlot('conv_abc_123');
        $slotB = $router->getSlot('conv_abc_123');

        $this->assertSame($slotA, $slotB);
    }

    public function testClusterMapping(): void
    {
        $router = new ShardRouter([
            'cluster_a' => ['min' => 0, 'max' => 127],
            'cluster_b' => ['min' => 128, 'max' => 255],
        ]);

        $cluster = $router->getClusterForConversation('1001');
        $this->assertContains($cluster, ['cluster_a', 'cluster_b']);
    }
}
