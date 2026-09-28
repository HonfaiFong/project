<?php

declare(strict_types=1);

namespace Packages\IdGenerator;

use InvalidArgumentException;
use RuntimeException;

final class Snowflake
{
    private const WORKER_ID_BITS = 10;
    private const SEQUENCE_BITS  = 12;

    private const MAX_WORKER_ID  = (1 << self::WORKER_ID_BITS) - 1; // 1023
    private const MAX_SEQUENCE   = (1 << self::SEQUENCE_BITS) - 1;  // 4095

    private const WORKER_ID_SHIFT = self::SEQUENCE_BITS;
    private const TIMESTAMP_SHIFT = self::WORKER_ID_BITS + self::SEQUENCE_BITS;

    // Default Epoch: 2024-01-01 00:00:00 UTC (1704067200000 ms)
    private int $epoch;
    private int $workerId;
    private int $sequence = 0;
    private int $lastTimestamp = -1;

    public function __construct(int $workerId = 1, int $epoch = 1704067200000)
    {
        if ($workerId < 0 || $workerId > self::MAX_WORKER_ID) {
            throw new InvalidArgumentException(sprintf('Worker ID must be between 0 and %d', self::MAX_WORKER_ID));
        }

        $this->workerId = $workerId;
        $this->epoch = $epoch;
    }

    public function nextId(): string
    {
        $timestamp = $this->timeGen();

        if ($timestamp < $this->lastTimestamp) {
            $offset = $this->lastTimestamp - $timestamp;
            if ($offset <= 5) {
                // Wait for clock to catch up
                usleep((int)($offset * 1000));
                $timestamp = $this->timeGen();
            }
            if ($timestamp < $this->lastTimestamp) {
                throw new RuntimeException(sprintf('Clock moved backwards. Refusing to generate id for %d ms', $this->lastTimestamp - $timestamp));
            }
        }

        if ($this->lastTimestamp === $timestamp) {
            $this->sequence = ($this->sequence + 1) & self::MAX_SEQUENCE;
            if ($this->sequence === 0) {
                $timestamp = $this->tilNextMillis($this->lastTimestamp);
            }
        } else {
            $this->sequence = 0;
        }

        $this->lastTimestamp = $timestamp;

        $id = (($timestamp - $this->epoch) << self::TIMESTAMP_SHIFT)
            | ($this->workerId << self::WORKER_ID_SHIFT)
            | $this->sequence;

        return (string)$id;
    }

    /**
     * @return array{timestamp: int, worker_id: int, sequence: int}
     */
    public function parse(string|int $id): array
    {
        $idInt = (int)$id;
        $sequence = $idInt & self::MAX_SEQUENCE;
        $workerId = ($idInt >> self::WORKER_ID_SHIFT) & self::MAX_WORKER_ID;
        $timestamp = ($idInt >> self::TIMESTAMP_SHIFT) + $this->epoch;

        return [
            'timestamp' => $timestamp,
            'worker_id' => $workerId,
            'sequence'  => $sequence,
        ];
    }

    private function tilNextMillis(int $lastTimestamp): int
    {
        $timestamp = $this->timeGen();
        while ($timestamp <= $lastTimestamp) {
            $timestamp = $this->timeGen();
        }
        return $timestamp;
    }

    private function timeGen(): int
    {
        return (int)floor(microtime(true) * 1000);
    }
}
