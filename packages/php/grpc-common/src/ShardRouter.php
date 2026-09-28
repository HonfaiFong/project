<?php

declare(strict_types=1);

namespace Packages\GrpcCommon;

final class ShardRouter
{
    public const TOTAL_SLOTS = 256;

    /**
     * @var array<string, array{min: int, max: int}>
     */
    private array $clusters;

    /**
     * @param array<string, array{min: int, max: int}>|null $clusters Custom cluster mappings
     */
    public function __construct(?array $clusters = null)
    {
        $this->clusters = $clusters ?? [
            'message_mysql_default' => ['min' => 0, 'max' => 255],
        ];
    }

    public function getSlot(string|int $conversationId): int
    {
        $hash = crc32((string)$conversationId);
        return abs($hash) % self::TOTAL_SLOTS;
    }

    public function getClusterForConversation(string|int $conversationId): string
    {
        $slot = $this->getSlot($conversationId);

        foreach ($this->clusters as $clusterName => $range) {
            if ($slot >= $range['min'] && $slot <= $range['max']) {
                return $clusterName;
            }
        }

        return 'message_mysql_default';
    }
}
