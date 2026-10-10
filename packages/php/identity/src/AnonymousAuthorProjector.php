<?php

declare(strict_types=1);

namespace Packages\Identity;

/**
 * Converts an immutable write-time author snapshot into a safe public shape.
 *
 * The projector never reads a current Persona row. A historical or malformed
 * snapshot fails closed to an unlinked anonymous author.
 */
final readonly class AnonymousAuthorProjector
{
    public function __construct(private AnonymousReferenceFactory $references)
    {
    }

    /**
     * @param array<string, mixed>|AnonymousAuthorSnapshot $snapshot
     * @return array<string, mixed>
     */
    public function project(array|AnonymousAuthorSnapshot $snapshot, string $scopeType, string $scopeId): array
    {
        if ($snapshot instanceof AnonymousAuthorSnapshot) {
            $snapshot = $snapshot->toArray();
        }

        $mode = $this->mode($snapshot);
        if ($mode === PersonaMode::MAIN) {
            $publicId = $this->publicId($snapshot);
            if ($publicId !== null) {
                return [
                    'identity_mode' => 'main',
                    'persona_id' => $publicId,
                    'display_name' => $this->text($snapshot['display_name'] ?? null) ?? '用户',
                    'avatar_key' => $this->text($snapshot['avatar_key'] ?? null),
                    'profile_accessible' => true,
                ];
            }
        }

        // Only an explicitly marked ALIAS row may use its write-time display
        // name. Unknown/historic modes must not inherit a current Persona
        // nickname, which could create a stable public alias.
        $displayName = $mode === PersonaMode::ALIAS
            ? ($this->text($snapshot['display_name'] ?? null) ?? '匿名用户')
            : '匿名用户';
        // Alias references are generated and persisted at write time. Never
        // derive one from a persona id while projecting: doing so would turn
        // an internal stable id into a linkable public identity and would
        // make old/unknown rows share a fabricated anonymous reference.
        $anonymousRef = $this->anonymousRef($snapshot, $scopeType, $scopeId);

        return [
            'identity_mode' => 'alias',
            'display_name' => $displayName,
            'avatar_key' => 'system:anonymous',
            // A missing write-time reference must remain unlinked. In
            // particular, unknown historical snapshots never share a value.
            'anonymous_ref' => $mode === PersonaMode::ALIAS ? $anonymousRef : null,
            'profile_accessible' => false,
        ];
    }

    private function mode(array $snapshot): PersonaMode
    {
        $raw = $snapshot['identity_mode'] ?? null;
        if (is_int($raw) && ($raw === PersonaMode::MAIN->value || $raw === PersonaMode::ALIAS->value)) {
            return PersonaMode::from($raw);
        }
        if (is_string($raw)) {
            return match ($raw) {
                'main' => PersonaMode::MAIN,
                'alias' => PersonaMode::ALIAS,
                default => PersonaMode::UNSPECIFIED,
            };
        }

        // Historical rows do not have a trustworthy mode. Treat them as
        // anonymous rather than exposing the current Persona type.
        return PersonaMode::UNSPECIFIED;
    }

    private function publicId(array $snapshot): ?string
    {
        $raw = $snapshot['persona_id'] ?? null;
        if (is_int($raw) && $raw > 0) {
            return (string)$raw;
        }
        if (!is_string($raw)) {
            return null;
        }

        $id = trim($raw);
        if (strlen($id) > 20 || (strlen($id) === 20 && strcmp($id, '18446744073709551615') > 0)) {
            return null;
        }

        return preg_match('/\A[1-9][0-9]*\z/', $id) === 1 ? $id : null;
    }

    /** @param array<string, mixed> $snapshot */
    private function anonymousRef(array $snapshot, string $scopeType, string $scopeId): ?string
    {
        $storedScopeType = $this->text($snapshot['scope_type'] ?? null);
        $storedScopeId = $this->text($snapshot['scope_id'] ?? null);
        if ($storedScopeType !== $scopeType || $storedScopeId !== $scopeId) {
            return null;
        }

        $value = $snapshot['anonymous_ref'] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $this->references->isOpaqueReference($value) ? $value : null;
    }

    private function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
