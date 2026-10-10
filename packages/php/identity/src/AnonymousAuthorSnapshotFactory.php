<?php

declare(strict_types=1);

namespace Packages\Identity;

/**
 * Builds write-time author snapshots. All values are copied into the
 * immutable DTO; callers must not project a current persona row later.
 */
final readonly class AnonymousAuthorSnapshotFactory
{
    public function __construct(private AnonymousReferenceFactory $references)
    {
    }

    public function forMain(string $personaId, string $displayName, ?string $avatarKey = null): AnonymousAuthorSnapshot
    {
        $personaId = $this->personaId($personaId);
        $displayName = $this->displayName($displayName);

        return new AnonymousAuthorSnapshot(
            mode: PersonaMode::MAIN,
            personaId: $personaId,
            displayName: $displayName,
            avatarKey: $this->optionalText($avatarKey),
            anonymousRef: null,
        );
    }

    public function forAlias(
        string $personaId,
        string $scopeType,
        string $scopeId,
        string $displayName = '匿名用户',
    ): AnonymousAuthorSnapshot {
        $personaId = $this->personaId($personaId);
        $displayName = $this->displayName($displayName);
        $scopeType = $this->requiredText($scopeType, 'scope type');
        $scopeId = $this->requiredText($scopeId, 'scope id');

        return new AnonymousAuthorSnapshot(
            mode: PersonaMode::ALIAS,
            personaId: $personaId,
            displayName: $displayName,
            avatarKey: 'system:anonymous',
            anonymousRef: $this->references->forAlias($personaId, $scopeType, $scopeId),
            scopeType: $scopeType,
            scopeId: $scopeId,
        );
    }

    public function unknown(): AnonymousAuthorSnapshot
    {
        return new AnonymousAuthorSnapshot(
            mode: PersonaMode::UNSPECIFIED,
            personaId: null,
            displayName: null,
            avatarKey: 'system:anonymous',
            anonymousRef: null,
        );
    }

    private function requiredText(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException($field . ' is required');
        }

        return $value;
    }

    private function personaId(string $value): string
    {
        $value = $this->requiredText($value, 'persona id');
        if (preg_match('/\A[1-9][0-9]*\z/', $value) !== 1
            || strlen($value) > 20
            || (strlen($value) === 20 && strcmp($value, '18446744073709551615') > 0)
        ) {
            throw new \InvalidArgumentException('persona id must be a positive uint64');
        }

        return $value;
    }

    private function displayName(string $value): string
    {
        $value = $this->requiredText($value, 'display name');
        if ($this->characterLength($value) > 64) {
            throw new \InvalidArgumentException('display name exceeds 64 characters');
        }

        return $value;
    }

    private function optionalText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($this->characterLength($value) > 255) {
            throw new \InvalidArgumentException('avatar key exceeds 255 characters');
        }

        return $value === '' ? null : $value;
    }

    private function characterLength(string $value): int
    {
        $matches = [];
        if (preg_match_all('/./us', $value, $matches) === false) {
            throw new \InvalidArgumentException('snapshot text must be valid UTF-8');
        }

        return count($matches[0]);
    }
}
