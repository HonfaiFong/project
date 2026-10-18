<?php

declare(strict_types=1);

namespace Packages\GrpcCommon;

use Packages\IdGenerator\WorkerId;
use Spiral\Goridge\BlockingRelayInterface;
use Spiral\Goridge\Frame;
use Spiral\Goridge\Relay;
use Spiral\Goridge\RelayInterface;
use Spiral\Goridge\StreamRelay;
use Spiral\RoadRunner\Environment;
use Spiral\RoadRunner\Worker;

/** Keep process leases alive even while RoadRunner is waiting for a request. */
final class LeaseMaintainingRelay implements BlockingRelayInterface
{
    public function __construct(private readonly RelayInterface $relay)
    {
    }

    public static function createWorker(): Worker
    {
        return new Worker(new self(Relay::create(Environment::fromGlobals()->getRelayAddress())));
    }

    public function waitFrame(): Frame
    {
        do {
            // A failure escapes the request loop: RoadRunner replaces this worker
            // and its connections instead of leaving a permanently broken slot.
            WorkerId::maintain();
            if ($this->relay->hasFrame()) {
                return $this->relay->waitFrame();
            }
            if ($this->relay instanceof StreamRelay) {
                $read = [$this->relay->in];
                $write = $except = null;
                stream_select($read, $write, $except, 0, 100_000);
            } else {
                usleep(1000);
            }
        } while (true);
    }

    public function send(Frame $frame): void
    {
        $this->relay->send($frame);
    }

    public function hasFrame(): bool
    {
        WorkerId::maintain();

        return $this->relay->hasFrame();
    }
}
