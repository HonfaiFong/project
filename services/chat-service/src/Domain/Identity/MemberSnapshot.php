<?php

declare(strict_types=1);

namespace Services\ChatService\Domain\Identity;

use Services\ChatService\Domain\Exception\ChatException;

/** Immutable write-time member identity used by chat and message workers. */
final readonly class MemberSnapshot
{
    public function __construct(
        public string $userId,
        public string $personaId,
        public int $identityMode,
        public ?string $displayName,
        public ?string $avatarKey,
        public ?string $anonymousRef,
        public ?string $scopeType,
        public ?string $scopeId,
        public int|string $lastReadSeq = 0,
        public ?string $joinedAt = null,
    ) {
        if (blank($this->userId) || blank($this->personaId) || !in_array($this->identityMode, [1, 2], true)) {
            throw ChatException::invalid('invalid conversation member snapshot');
        }
        if ($this->identityMode === 1 && $this->anonymousRef !== null) {
            throw ChatException::invalid('MAIN snapshot cannot carry anonymous_ref');
        }
        if ($this->identityMode === 2 && ($this->scopeType === null || $this->scopeId === null)) {
            throw ChatException::invalid('ALIAS snapshot requires scope');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'persona_id' => $this->personaId,
            'identity_mode' => $this->identityMode,
            'display_name' => $this->displayName,
            'avatar_key' => $this->avatarKey,
            'anonymous_ref' => $this->anonymousRef,
            'scope_type' => $this->scopeType,
            'scope_id' => $this->scopeId,
            'last_read_seq' => (string)$this->lastReadSeq,
            'joined_at' => $this->joinedAt,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            userId: (string)$row['user_id'],
            personaId: (string)$row['persona_id'],
            identityMode: (int)$row['identity_mode'],
            displayName: self::nullable($row['display_name_snapshot'] ?? $row['display_name'] ?? null),
            avatarKey: self::nullable($row['avatar_key_snapshot'] ?? $row['avatar_key'] ?? null),
            anonymousRef: self::nullable($row['anonymous_ref'] ?? null),
            scopeType: self::nullable($row['scope_type'] ?? null),
            scopeId: self::nullable($row['scope_id'] ?? null),
            lastReadSeq: (string)($row['last_read_seq'] ?? '0'),
            joinedAt: self::nullable($row['joined_at'] ?? null),
        );
    }

    private static function nullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string)$value);

        return blank($value) ? null : $value;
    }
}
