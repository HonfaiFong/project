<?php

declare(strict_types=1);

namespace Packages\Identity;

use Contracts\Gen\User\V1\PersonaProfile;
use Contracts\Gen\User\V1\GetPersonaRequest;
use Contracts\Gen\User\V1\GetPersonaResponse;
use Contracts\Gen\User\V1\ResolvePersonaModeRequest;
use Contracts\Gen\User\V1\ResolvePersonaModeResponse;
use Contracts\Gen\User\V1\ValidatePersonaOwnershipRequest;
use Contracts\Gen\User\V1\ValidatePersonaOwnershipResponse;
use Google\Protobuf\Internal\Message;
use Packages\GrpcCommon\BusinessActor;
use Packages\GrpcCommon\UnaryGrpcClient;
use RuntimeException;

/** UserService-backed implementation shared by internal business services. */
final class UserServiceIdentityClient implements UserIdentityDirectory
{
    private ?UnaryGrpcClient $client = null;

    public function __construct(
        private readonly string $target,
        private readonly ?string $serviceToken,
        private readonly int $timeoutMs = 250,
        ?UnaryGrpcClient $client = null,
    ) {
        $this->client = $client;
    }

    public function resolve(
        string $userId,
        PersonaMode $mode,
        PersonaOperation $operation,
        ?BusinessActor $actor = null,
    ): UserIdentity {
        $this->assertActor($userId, $actor);
        $response = $this->call(
            '/user.v1.UserService/ResolvePersonaMode',
            new ResolvePersonaModeRequest([
                'user_id' => $userId,
                'mode' => $mode->value,
                'operation' => $operation->value,
            ]),
            ResolvePersonaModeResponse::class,
            $actor,
        );

        $persona = $response->getPersona();
        if (!$persona instanceof PersonaProfile) {
            throw new RuntimeException('resolved persona was not returned');
        }
        $identity = $this->identity($persona, $userId);
        if ($identity->mode !== $mode) {
            throw new RuntimeException('resolved persona mode does not match the request');
        }

        return $identity;
    }

    public function validate(
        string $userId,
        string $personaId,
        PersonaOperation $operation,
        ?BusinessActor $actor = null,
    ): UserIdentity {
        $this->assertActor($userId, $actor);
        $response = $this->call(
            '/user.v1.UserService/ValidatePersonaOwnership',
            new ValidatePersonaOwnershipRequest([
                'user_id' => $userId,
                'persona_id' => $personaId,
                'operation' => $operation->value,
            ]),
            ValidatePersonaOwnershipResponse::class,
            $actor,
        );

        $persona = $response->getPersona();
        if (!$persona instanceof PersonaProfile) {
            throw new RuntimeException('validated persona was not returned');
        }

        return $this->identity($persona, $userId);
    }

    public function main(string $personaId, PersonaOperation $operation, ?BusinessActor $actor = null): UserIdentity
    {
        if ($operation === PersonaOperation::UNSPECIFIED) {
            throw new RuntimeException('persona operation is required');
        }

        // A target MAIN persona can belong to another account (for example a
        // follow or a private-conversation target). GetPersona performs the
        // active-account and MAIN-type checks. Do not call the ownership RPC
        // with the caller actor here: the target owner is intentionally a
        // different account and the operation concerns the peer.
        $response = $this->call(
            '/user.v1.UserService/GetPersona',
            new GetPersonaRequest(['persona_id' => $personaId]),
            GetPersonaResponse::class,
            null,
        );
        $persona = $response->getPersona();
        if (!$persona instanceof PersonaProfile) {
            throw new RuntimeException('target main persona was not found');
        }
        return $this->identity($persona, (string)$persona->getUserId());
    }

    /**
     * @template T of Message
     * @param class-string<T> $responseClass
     * @return T
     * @throws \Exception
     */
    private function call(
        string $method,
        Message $request,
        string $responseClass,
        ?BusinessActor $actor,
    ): Message {
        $client = $this->client ??= new UnaryGrpcClient($this->target, $this->serviceToken);
        $metadata = $actor?->metadata() ?? [];

        return $client->call($method, $request, $responseClass, $this->timeoutMs, $metadata);
    }

    private function assertActor(string $userId, ?BusinessActor $actor): void
    {
        if ($actor !== null && $actor->userId !== $userId) {
            throw new RuntimeException('actor user id does not match requested user id');
        }
    }

    private function identity(PersonaProfile $persona, string $userId): UserIdentity
    {
        $profileMode = $this->profileMode($persona);

        return new UserIdentity(
            userId: $userId,
            personaId: (string)$persona->getId(),
            mode: $profileMode,
            displayName: $persona->getNickname(),
            avatarKey: $persona->getAvatarKey() !== '' ? $persona->getAvatarKey() : null,
            profileAccessible: $persona->getProfileAccessible(),
        );
    }

    private function profileMode(PersonaProfile $persona): PersonaMode
    {
        $mode = match (strtolower(trim($persona->getIdentityMode()))) {
            'main' => PersonaMode::MAIN,
            'alias' => PersonaMode::ALIAS,
            default => null,
        };
        if ($mode === null) {
            throw new RuntimeException('persona identity mode is invalid');
        }

        $type = (int)$persona->getType();
        if (($mode === PersonaMode::MAIN && $type !== 1)
            || ($mode === PersonaMode::ALIAS && $type !== 2)
            || ($mode === PersonaMode::ALIAS && !$persona->getIsInternalAlias())
        ) {
            throw new RuntimeException('persona identity type is inconsistent');
        }

        return $mode;
    }
}
