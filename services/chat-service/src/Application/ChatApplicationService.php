<?php

declare(strict_types=1);

namespace Services\ChatService\Application;

use Contracts\Gen\Chat\V1\AnonymousChatTarget;
use Contracts\Gen\Chat\V1\PublicChatTarget;
use Contracts\Gen\Chat\V1\PublicConversation;
use Contracts\Gen\Chat\V1\PublicConversationMember;
use Contracts\Gen\Common\V1\IdentityMode;
use Contracts\Gen\Common\V1\PublicAuthor;
use Packages\Identity\AnonymousAuthorProjector;
use Packages\Identity\AnonymousReferenceFactory;
use Packages\GrpcCommon\OperationBudget;
use PDOException;
use Services\ChatService\Domain\Exception\ChatException;
use Services\ChatService\Domain\IdGenerator;
use Services\ChatService\Domain\Identity\UserIdentityClient;
use Services\ChatService\Domain\MessageSequenceReader;
use Services\ChatService\Domain\Social\BlockStatusResolver;
use Services\ChatService\Domain\Social\RejectingBlockStatusResolver;
use Services\ChatService\Domain\Target\ContentTargetResolver;
use Services\ChatService\Domain\Target\RejectingContentTargetResolver;
use Services\ChatService\Infrastructure\PdoChatRepository;
use Services\ChatService\Infrastructure\RandomIdGenerator;
use Throwable;

final class ChatApplicationService
{
    private const CREATE_CONVERSATION_OPERATION = 3;
    private const SEND_MESSAGE_OPERATION = 4;
    private const PRIVATE_CONVERSATION = 1;
    private const MAIN = 1;
    private const ALIAS = 2;

    public function __construct(
        private readonly PdoChatRepository $repository,
        private readonly UserIdentityClient $users,
        private readonly MessageSequenceReader $messageSequences,
        ?ContentTargetResolver $targets = null,
        ?BlockStatusResolver $blocks = null,
        ?AnonymousReferenceFactory $references = null,
        ?IdGenerator $ids = null,
        ?\Closure $clock = null,
    ) {
        $this->targets = $targets ?? new RejectingContentTargetResolver();
        $this->blocks = $blocks ?? new RejectingBlockStatusResolver();
        if ($references !== null) {
            $this->references = $references;
        } else {
            $key = trim((string)getenv('ANONYMOUS_IDENTITY_KEY'));
            if ($key === '') {
                throw new \RuntimeException('ANONYMOUS_IDENTITY_KEY is required');
            }
            $this->references = new AnonymousReferenceFactory($key);
        }
        $this->projector = new AnonymousAuthorProjector($this->references);
        $this->ids = $ids ?? new RandomIdGenerator();
        $this->clock = $clock ?? static fn (): string => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }

    private readonly ContentTargetResolver $targets;
    private readonly BlockStatusResolver $blocks;
    private readonly AnonymousReferenceFactory $references;
    private readonly AnonymousAuthorProjector $projector;
    private readonly IdGenerator $ids;
    /** @var \Closure():string */
    private readonly \Closure $clock;

    /**
     * Body user_id/persona_id values are deliberately ignored. The gateway's
     * authenticated x-user-id metadata is the only account authority.
     */
    public function getOrCreatePrivateConversation(string $trustedUserId, array $request): PublicConversation
    {
        $trustedUserId = $this->id($trustedUserId, 'user_id');
        $mode = (int)($request['identity_mode'] ?? 0);
        if (!in_array($mode, [self::MAIN, self::ALIAS], true)) {
            throw ChatException::forbidden('identity mode is required');
        }

        $ownProfile = $this->profile(
            $this->users->resolvePersonaMode($trustedUserId, $mode, self::CREATE_CONVERSATION_OPERATION),
            $trustedUserId,
            $mode,
        );
        $sourceAliasDisplayName = $this->aliasDisplayName($request['alias_display_name'] ?? null, $mode);
        $target = $this->resolveTarget($trustedUserId, $request['target'] ?? null);
        if ($this->blocks->isBlockedEitherWay($trustedUserId, $target['user_id'])) {
            throw ChatException::forbidden();
        }

        $privateKey = $this->privateKey($trustedUserId, $target, $mode, $ownProfile);
        $pdo = $this->repository->pdo();
        $attempt = 0;
        while (true) {
            try {
                $pdo->beginTransaction();
                $existing = $this->repository->findByPrivateKey($privateKey);
                if (!empty($existing)) {
                    if (empty($this->repository->findMember((string)$existing['id'], $trustedUserId))) {
                        // A key must never allow a caller to observe a session
                        // that does not contain the authenticated account.
                        $pdo->rollBack();
                        throw ChatException::forbidden();
                    }
                    $pdo->commit();

                    return $this->publicConversation($existing);
                }

                $conversationId = $this->id($this->ids->nextId(), 'conversation_id');
                $now = ($this->clock)();
                $own = $this->memberSnapshot($ownProfile, $mode, $conversationId, [], $sourceAliasDisplayName);
                $peer = $this->memberSnapshot($target['profile'], $target['mode'], $conversationId, $target);
                $this->repository->insert(
                    [
                        'id' => $conversationId,
                        'type' => self::PRIVATE_CONVERSATION,
                        'created_at' => $now,
                        'updated_at' => $now,
                        'private_key' => $privateKey,
                        'creation_request_id' => $this->nullableText($request['request_id'] ?? null),
                        'requesting_user_id' => $trustedUserId,
                        'target_mode' => $target['mode'],
                        'target_user_id' => $target['user_id'],
                        'target_persona_id' => $target['persona_id'],
                        'target_scope_type' => $target['scope_type'],
                        'target_scope_id' => $target['scope_id'],
                        'target_anonymous_ref' => $target['anonymous_ref'],
                    ],
                    [
                        ['id' => $this->ids->nextId()] + $own,
                        ['id' => $this->ids->nextId()] + $peer,
                    ],
                );
                $created = $this->repository->findById($conversationId);
                if (empty($created)) {
                    throw ChatException::notFound('created conversation could not be reloaded');
                }
                $pdo->commit();

                return $this->publicConversation($created);
            } catch (ChatException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if (!$this->isUniqueViolation($e) || $attempt >= 1) {
                    throw $e;
                }
                // A concurrent creator won the unique private_key race. The
                // next read returns its immutable snapshot.
                $existing = $this->repository->findByPrivateKey($privateKey);
                if (!empty($existing) && !empty($this->repository->findMember((string)$existing['id'], $trustedUserId))) {
                    return $this->publicConversation($existing);
                }
                $attempt++;
            }
        }
    }

    public function getConversation(string $trustedUserId, string $conversationId): PublicConversation
    {
        $trustedUserId = $this->id($trustedUserId, 'user_id');
        $conversationId = $this->id($conversationId, 'conversation_id');
        $conversation = $this->repository->findByIdForUser($conversationId, $trustedUserId);
        if (empty($conversation)) {
            throw ChatException::notFound();
        }

        return $this->publicConversation($conversation);
    }

    /** @return array{conversations:list<PublicConversation>,has_more:bool,next_cursor:string} */
    public function listConversations(string $trustedUserId, int $limit, ?string $cursor): array
    {
        $trustedUserId = $this->id($trustedUserId, 'user_id');
        $limit = max(1, min($limit > 0 ? $limit : 20, 100));
        $cursor = $cursor !== null && $cursor !== '' ? $cursor : null;
        $rows = $this->repository->listForUser($trustedUserId, $limit, $cursor);
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        return [
            'conversations' => array_map(fn (array $row): PublicConversation => $this->publicConversation($row), $rows),
            'has_more' => $hasMore,
            'next_cursor' => $hasMore ? $this->repository->listCursor($rows[array_key_last($rows)]) : '',
        ];
    }

    public function checkCanMessage(string $trustedUserId, string $recipientUserId): bool
    {
        $trustedUserId = $this->id($trustedUserId, 'user_id');
        $recipientUserId = $this->id($recipientUserId, 'recipient_user_id');
        if ($trustedUserId === $recipientUserId) {
            return false;
        }

        return !$this->blocks->isBlockedEitherWay($trustedUserId, $recipientUserId);
    }

    public function markRead(
        string $trustedUserId,
        string $conversationId,
        string $seq,
        ?OperationBudget $budget = null,
    ): bool
    {
        $budget ??= new OperationBudget(600);
        $trustedUserId = $this->id($trustedUserId, 'user_id');
        $conversationId = $this->id($conversationId, 'conversation_id');
        $seq = $this->uint($seq, 'seq');
        $member = $this->repository->findMember($conversationId, $trustedUserId);
        if (empty($member)) {
            throw ChatException::notMember();
        }

        if ($this->compareUnsigned($seq, $this->uint($member['last_read_seq'], 'last_read_seq')) <= 0) {
            return $this->repository->markRead($conversationId, $trustedUserId, $seq);
        }

        $summarySeq = $this->uint($this->repository->latestMessageSequence($conversationId), 'last_message_seq');
        if ($this->compareUnsigned($seq, $summarySeq) > 0) {
            $latestSeq = $this->uint($this->messageSequences->latestSequence($conversationId, $budget), 'latest_seq');
            if ($this->compareUnsigned($seq, $latestSeq) > 0) {
                throw ChatException::invalid('seq exceeds the latest persisted message');
            }
        }

        return $this->repository->markRead($conversationId, $trustedUserId, $seq);
    }

    /**
     * Internal MessageService hop. The returned array contains private
     * identity fields and must never be serialized as a public response.
     *
     * @return array{allowed:bool,denial_reason:string,member?:array<string,mixed>,peer_user_id?:string}
     */
    public function getConversationMemberSnapshot(string $trustedUserId, string $conversationId): array
    {
        $trustedUserId = $this->id($trustedUserId, 'user_id');
        $conversationId = $this->id($conversationId, 'conversation_id');
        $conversation = $this->repository->findById($conversationId);
        if (empty($conversation)) {
            return ['allowed' => false, 'denial_reason' => 'conversation not found'];
        }
        $member = $this->repository->findMember($conversationId, $trustedUserId);
        if (empty($member)) {
            return ['allowed' => false, 'denial_reason' => 'user is not a member of conversation'];
        }

        $peerUserId = null;
        foreach ($conversation['members'] as $candidate) {
            if ((string)$candidate['user_id'] !== $trustedUserId) {
                $peerUserId = (string)$candidate['user_id'];
                break;
            }
        }
        if (blank($peerUserId) || $this->blocks->isBlockedEitherWay($trustedUserId, $peerUserId)) {
            return ['allowed' => false, 'denial_reason' => 'conversation is blocked'];
        }

        // A conversation stores the selected persona as an immutable public
        // snapshot, but the account/persona still has to be active when a
        // message operation is authorized. Validate the exact stored member
        // through UserService and keep the stored display fields untouched;
        // this must never refresh a historical snapshot.
        try {
            $validated = $this->users->validatePersonaOwnership(
                (string)$member['user_id'],
                (string)$member['persona_id'],
                self::SEND_MESSAGE_OPERATION,
            );
        } catch (Throwable $exception) {
            if (!in_array($exception->getCode(), [
                \Contracts\Errors\ErrorCode::USER_NOT_FOUND->value,
                \Contracts\Errors\ErrorCode::USER_BANNED->value,
                \Contracts\Errors\ErrorCode::PERSONA_NOT_FOUND->value,
                \Contracts\Errors\ErrorCode::PERSONA_FORBIDDEN->value,
            ], true)) {
                throw $exception;
            }
            return ['allowed' => false, 'denial_reason' => 'conversation identity is unavailable'];
        }
        $validatedProfile = $validated['persona'] ?? $validated;
        if (!is_array($validatedProfile)
            || (string)($validatedProfile['user_id'] ?? '') !== (string)$member['user_id']
            || (string)($validatedProfile['id'] ?? '') !== (string)$member['persona_id']
            || (int)($validatedProfile['identity_mode'] ?? $validatedProfile['type'] ?? 0) !== (int)($member['identity_mode'] ?? 0)
        ) {
            return ['allowed' => false, 'denial_reason' => 'conversation identity is unavailable'];
        }

        return [
            'allowed' => true,
            'denial_reason' => '',
            'member' => $member,
            'peer_user_id' => $peerUserId,
        ];
    }

    /**
     * @param mixed $request
     * @return array<string,mixed>
     */
    private function resolveTarget(string $requesterUserId, mixed $request): array
    {
        if (!$request instanceof PublicChatTarget) {
            throw ChatException::invalid('target is required');
        }
        if ($request->hasMainPersonaId()) {
            $personaId = $this->id($request->getMainPersonaId(), 'target.main_persona_id');
            $profile = $this->profile(
                $this->users->resolveMainPersona($requesterUserId, $personaId, self::CREATE_CONVERSATION_OPERATION),
                expectedUserId: null,
                expectedMode: self::MAIN,
            );
            $targetUserId = $this->id((string)($profile['user_id'] ?? ''), 'target.user_id');
            if ($targetUserId === $requesterUserId) {
                throw ChatException::forbidden();
            }

            return [
                'mode' => self::MAIN,
                'user_id' => $targetUserId,
                'persona_id' => $personaId,
                'profile' => $profile,
                'scope_type' => null,
                'scope_id' => null,
                'anonymous_ref' => null,
            ];
        }
        if (!$request->hasAnonymous()) {
            throw ChatException::invalid('target must be MAIN or anonymous');
        }
        $anonymous = $request->getAnonymous();
        if (!$anonymous instanceof AnonymousChatTarget) {
            throw ChatException::invalid('anonymous target is invalid');
        }
        $scopeType = $this->scope($anonymous->getScopeType(), 'scope_type');
        $scopeId = $this->scope($anonymous->getScopeId(), 'scope_id');
        $reference = trim($anonymous->getAnonymousRef());
        if (preg_match('/\Aanon_[A-Za-z0-9_-]{1,120}\z/', $reference) !== 1) {
            throw ChatException::forbidden('anonymous target reference is invalid');
        }
        $resolved = $this->targets->resolveAnonymousTarget($requesterUserId, $scopeType, $scopeId, $reference);
        if ($resolved['allowed'] !== true
            || ($resolved['deleted'] ?? false) === true
            || (string)($resolved['scope_type'] ?? '') !== $scopeType
            || (string)($resolved['scope_id'] ?? '') !== $scopeId
            || (string)($resolved['anonymous_ref'] ?? '') !== $reference
        ) {
            throw ChatException::forbidden('anonymous target is unavailable');
        }
        $targetUserId = $this->id((string)($resolved['owner_user_id'] ?? ''), 'target.user_id');
        $targetPersonaId = $this->id((string)($resolved['owner_persona_id'] ?? ''), 'target.persona_id');
        if ($targetUserId === $requesterUserId) {
            throw ChatException::forbidden();
        }
        $profile = $this->profile(
            $this->users->validatePersonaOwnership($targetUserId, $targetPersonaId, self::CREATE_CONVERSATION_OPERATION),
            $targetUserId,
            self::ALIAS,
        );
        if (!(bool)($profile['is_internal_alias'] ?? false)) {
            throw ChatException::forbidden('anonymous target is not an internal alias');
        }

        $resolvedDisplayName = $this->nullableText($resolved['display_name'] ?? null);
        if (filled($resolvedDisplayName)) {
            $profile['nickname'] = $resolvedDisplayName;
        }
        $resolvedAvatarKey = $this->nullableText($resolved['avatar_key'] ?? null);
        if (filled($resolvedAvatarKey)) {
            $profile['avatar_key'] = $resolvedAvatarKey;
        }

        return [
            'mode' => self::ALIAS,
            'user_id' => $targetUserId,
            'persona_id' => $targetPersonaId,
            'profile' => $profile,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'anonymous_ref' => $reference,
        ];
    }

    /**
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $target
     * @return array<string,mixed>
     */
    private function memberSnapshot(
        array $profile,
        int $mode,
        string $conversationId,
        array $target = [],
        ?string $displayNameOverride = null,
    ): array
    {
        $personaId = $this->id((string)($profile['id'] ?? ''), 'persona_id');
        $scopeType = $mode === self::ALIAS ? 'conversation' : null;
        $scopeId = $mode === self::ALIAS ? $conversationId : null;
        $anonymousRef = $mode === self::ALIAS
            ? $this->references->forAlias($personaId, 'conversation', $conversationId)
            : null;

        return [
            'user_id' => $this->id((string)($profile['user_id'] ?? ''), 'user_id'),
            'persona_id' => $personaId,
            'identity_mode' => $mode,
            'display_name' => $displayNameOverride
                ?? $this->nullableText($profile['nickname'] ?? $target['display_name'] ?? null)
                ?? ($mode === self::MAIN ? 'User' : 'Anonymous user'),
            'avatar_key' => $this->nullableText($profile['avatar_key'] ?? $target['avatar_key'] ?? null)
                ?? ($mode === self::ALIAS ? 'system:anonymous' : null),
            'anonymous_ref' => $anonymousRef,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'joined_at' => ($this->clock)(),
        ];
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private function profile(array $response, ?string $expectedUserId, int $expectedMode): array
    {
        $profile = $response['persona'] ?? $response;
        if (!is_array($profile) || empty($profile['id'])) {
            throw ChatException::forbidden('persona is unavailable');
        }
        $profile['id'] = $this->id((string)$profile['id'], 'persona_id');
        if ($expectedUserId !== null && (string)($profile['user_id'] ?? '') !== $expectedUserId) {
            throw ChatException::forbidden('persona does not belong to account');
        }
        if ((int)($profile['status'] ?? 0) !== 1) {
            throw ChatException::forbidden('persona is unavailable');
        }
        $isAlias = $expectedMode === self::ALIAS;
        if ($isAlias && !(bool)($profile['is_internal_alias'] ?? false)) {
            throw ChatException::forbidden('persona is not an internal alias');
        }
        if (!$isAlias && (int)($profile['type'] ?? self::MAIN) !== self::MAIN) {
            throw ChatException::forbidden('persona is not MAIN');
        }

        return $profile;
    }

    /**
     * @param array<string,mixed> $target
     * @param array<string,mixed> $ownProfile
     */
    private function privateKey(string $requesterUserId, array $target, int $sourceMode, array $ownProfile): string
    {
        $users = [$requesterUserId, (string)$target['user_id']];
        $targetMode = (int)$target['mode'];
        $bothMain = $sourceMode === self::MAIN && $targetMode === self::MAIN;
        if ($bothMain) {
            // Only a MAIN-to-MAIN session is symmetric. Including only the
            // requested peer persona would let A->B and B->A create two
            // sessions, while sorting an ALIAS source would let a private
            // alias session be reused by the peer's MAIN identity.
            sort($users, SORT_STRING);
            $identity = 'main-pair';
        } elseif ($targetMode === self::MAIN && $sourceMode === self::ALIAS) {
            // Keep an ALIAS source directional and bind it to its immutable
            // persona snapshot so a later MAIN request cannot discover it.
            $identity = 'alias-source:' . (string)($ownProfile['id'] ?? '')
                . ':main-target:' . (string)$target['persona_id'];
        } else {
            // Anonymous targets are also bound to the caller's selected
            // identity. Otherwise choosing MAIN after ALIAS (or vice versa)
            // for the same post reference would reuse the first session and
            // reveal an identity transition through the conversation key.
            $identity = ($sourceMode === self::MAIN ? 'main-source:' : 'alias-source:')
                . (string)($ownProfile['id'] ?? '')
                . ':target-alias:' . (string)$target['scope_type']
                . ':' . (string)$target['scope_id']
                . ':' . (string)$target['anonymous_ref'];
        }

        // Store only a fixed-width ASCII digest.  Raw target references can
        // contain mixed-case opaque values; using them directly under a
        // utf8mb4_unicode_ci unique index can collapse distinct sessions.
        return hash('sha256', json_encode(
            [$users[0], $users[1], $identity],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
    }

    /** @param array<string,mixed> $row */
    private function publicConversation(array $row): PublicConversation
    {
        $members = [];
        foreach ($row['members'] ?? [] as $member) {
            $projected = $this->projector->project(
                [
                    'identity_mode' => $member['identity_mode'] ?? null,
                    'persona_id' => $member['persona_id'] ?? null,
                    'display_name' => $member['display_name_snapshot'] ?? null,
                    'avatar_key' => $member['avatar_key_snapshot'] ?? null,
                    'anonymous_ref' => $member['anonymous_ref'] ?? null,
                    'scope_type' => $member['scope_type'] ?? null,
                    'scope_id' => $member['scope_id'] ?? null,
                ],
                'conversation',
                (string)$row['id'],
            );
            $mode = ($projected['identity_mode'] ?? 'alias') === 'main' ? self::MAIN : self::ALIAS;
            $author = new PublicAuthor([
                'identity_mode' => $mode === self::MAIN ? IdentityMode::IDENTITY_MODE_MAIN : IdentityMode::IDENTITY_MODE_ALIAS,
                'persona_id' => $mode === self::MAIN ? (string)($projected['persona_id'] ?? '0') : '0',
                'display_name' => (string)($projected['display_name'] ?? ($mode === self::MAIN ? 'User' : 'Anonymous user')),
                'avatar_key' => (string)($projected['avatar_key'] ?? ($mode === self::ALIAS ? 'system:anonymous' : '')),
                'anonymous_ref' => $mode === self::ALIAS ? (string)($projected['anonymous_ref'] ?? '') : '',
                'profile_accessible' => (bool)($projected['profile_accessible'] ?? false),
            ]);
            $members[] = new PublicConversationMember([
                'author' => $author,
                'last_read_seq' => (string)($member['last_read_seq'] ?? '0'),
                'joined_at' => (string)($member['joined_at'] ?? ''),
            ]);
        }

        return new PublicConversation([
            'conversation_id' => (string)$row['id'],
            'type' => (int)$row['type'],
            'last_message_id' => (string)($row['last_message_id'] ?? '0'),
            'last_message_at' => (string)($row['last_message_at'] ?? ''),
            'members' => $members,
            'created_at' => (string)$row['created_at'],
        ]);
    }

    private function id(int|string $value, string $field): string
    {
        $value = trim((string)$value);
        if (empty($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            throw ChatException::invalid($field . ' is required');
        }

        return $value;
    }

    private function uint(int|string $value, string $field): string
    {
        $value = trim((string)$value);
        if (blank($value) || preg_match('/\A[0-9]+\z/', $value) !== 1
            || strlen($value) > 19 || (strlen($value) === 19 && strcmp($value, '9223372036854775807') > 0)) {
            throw ChatException::invalid($field . ' is invalid');
        }

        return ltrim($value, '0') ?: '0';
    }

    private function compareUnsigned(string $left, string $right): int
    {
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    private function scope(string $value, string $field): string
    {
        $value = trim($value);
        if (blank($value) || strlen($value) > 255 || preg_match('/\A[\x20-\x7E]+\z/', $value) !== 1) {
            throw ChatException::forbidden($field . ' is invalid');
        }

        return $value;
    }

    private function nullableText(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return blank($value) ? null : $value;
    }

    private function aliasDisplayName(mixed $value, int $mode): ?string
    {
        if ($mode !== self::ALIAS || !is_string($value)) {
            return null;
        }
        $value = trim($value);
        $characters = preg_match_all('/./us', $value);
        if (blank($value) || preg_match('//u', $value) !== 1 || $characters === false || $characters > 64) {
            throw ChatException::invalid('alias_display_name is invalid');
        }

        return $value;
    }

    private function isUniqueViolation(Throwable $e): bool
    {
        if ($e instanceof PDOException && str_starts_with((string)$e->getCode(), '23')) {
            return true;
        }

        return str_contains(strtolower($e->getMessage()), 'unique')
            || str_contains(strtolower($e->getMessage()), 'duplicate');
    }
}
