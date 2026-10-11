<?php

declare(strict_types=1);

namespace Services\ChatService\Infrastructure;

use Contracts\Gen\Content\V1\ResolveAnonymousTargetRequest;
use Contracts\Gen\Content\V1\ResolveAnonymousTargetResponse;
use Google\Protobuf\Internal\Message;
use Packages\GrpcCommon\UnaryGrpcClient;
use Services\ChatService\Domain\Target\ContentTargetResolver;

/** Calls ContentService's internal persisted-target resolver. */
final class GrpcContentTargetResolver implements ContentTargetResolver
{
    private ?UnaryGrpcClient $client = null;

    public function __construct(
        private readonly string $target,
        private readonly ?string $serviceToken = null,
        private readonly int $timeoutMs = 250,
        ?UnaryGrpcClient $client = null,
    ) {
        $this->client = $client;
    }

    public function resolveAnonymousTarget(
        string $requesterUserId,
        string $scopeType,
        string $scopeId,
        string $anonymousRef,
    ): array {
        /** @var ResolveAnonymousTargetResponse $response */
        $response = $this->call(
            '/content.v1.ContentService/ResolveAnonymousTarget',
            new ResolveAnonymousTargetRequest([
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'anonymous_ref' => $anonymousRef,
            ]),
            ResolveAnonymousTargetResponse::class,
            ['x-user-id' => [$requesterUserId]],
        );
        $userId = (string)$response->getUserId();
        $personaId = (string)$response->getPersonaId();
        $identityMode = (int)$response->getIdentityMode();
        $allowed = $identityMode === 2
            && $userId !== ''
            && $userId !== '0'
            && $personaId !== ''
            && $personaId !== '0';
        $displayName = null;
        $avatarKey = 'system:anonymous';
        $author = $response->getAuthor();
        $allowed = $allowed && $author !== null;
        if ($author !== null) {
            $allowed = $allowed
                && (int)$author->getIdentityMode() === 2
                && trim($author->getAnonymousRef()) === $anonymousRef
                && !$author->getProfileAccessible();
            $displayName = trim((string)$author->getDisplayName()) ?: null;
            $avatarKey = trim((string)$author->getAvatarKey()) ?: $avatarKey;
        }

        return [
            'allowed' => $allowed,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'anonymous_ref' => $anonymousRef,
            'owner_user_id' => $userId,
            'owner_persona_id' => $personaId,
            'identity_mode' => $identityMode,
            'profile_accessible' => false,
            // This is a write-time snapshot from ContentService. Chat copies
            // it into the conversation member and never reloads the persona
            // nickname while projecting the public conversation.
            'display_name' => $displayName,
            'avatar_key' => $avatarKey,
        ];
    }

    /**
     * @template T of Message
     * @param class-string<T> $responseClass
     * @param array<string, array<string>> $metadata
     * @return T
     */
    private function call(string $method, Message $request, string $responseClass, array $metadata = []): Message
    {
        $client = $this->client ??= new UnaryGrpcClient($this->target, $this->serviceToken);

        return $client->call($method, $request, $responseClass, $this->timeoutMs, $metadata);
    }
}
