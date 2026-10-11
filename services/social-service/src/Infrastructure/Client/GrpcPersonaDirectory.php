<?php

declare(strict_types=1);

namespace Services\SocialService\Infrastructure\Client;

use Contracts\Errors\ErrorCode;
use Contracts\Gen\User\V1\GetPersonaRequest;
use Contracts\Gen\User\V1\GetPersonaResponse;
use Contracts\Gen\User\V1\GetUserRequest;
use Contracts\Gen\User\V1\GetUserResponse;
use Contracts\Gen\User\V1\PersonaProfile;
use Contracts\Gen\User\V1\ResolvePersonaModeRequest;
use Contracts\Gen\User\V1\ResolvePersonaModeResponse;
use Contracts\Gen\User\V1\ValidatePersonaOwnershipRequest;
use Contracts\Gen\User\V1\ValidatePersonaOwnershipResponse;
use Exception;
use Google\Protobuf\Internal\Message;
use Packages\GrpcCommon\GrpcCallException;
use Packages\GrpcCommon\UnaryGrpcClient;
use RuntimeException;
use Services\SocialService\Domain\Entity\MainPersona;
use Services\SocialService\Domain\Service\PersonaDirectoryInterface;
use const Grpc\STATUS_DEADLINE_EXCEEDED;
use const Grpc\STATUS_INVALID_ARGUMENT;
use const Grpc\STATUS_NOT_FOUND;
use const Grpc\STATUS_PERMISSION_DENIED;

final class GrpcPersonaDirectory implements PersonaDirectoryInterface
{
    private ?UnaryGrpcClient $client = null;

    public function __construct(
        private readonly string $target,
        private readonly int $timeoutMs = 150,
        private readonly ?string $serviceToken = null,
        ?UnaryGrpcClient $client = null,
    ) {
        $this->client = $client;
    }

    public function assertOwnedMainPersona(string $userId, string $personaId, int $operation): MainPersona
    {
        try {
            $response = $this->call(
                '/user.v1.UserService/ValidatePersonaOwnership',
                new ValidatePersonaOwnershipRequest([
                    'user_id' => $userId,
                    'persona_id' => $personaId,
                    'operation' => $operation,
                ]),
                ValidatePersonaOwnershipResponse::class,
            );
        } catch (Exception $e) {
            throw $this->map($e);
        }

        $profile = $response->getPersona();
        if (!$profile instanceof PersonaProfile) {
            throw $this->error(ErrorCode::PERSONA_FORBIDDEN);
        }

        return $this->main($profile, $userId);
    }

    public function findActiveMainPersona(string $personaId): MainPersona
    {
        try {
            $response = $this->call(
                '/user.v1.UserService/GetPersona',
                new GetPersonaRequest(['persona_id' => $personaId]),
                GetPersonaResponse::class,
            );
        } catch (Exception $e) {
            throw $this->map($e);
        }

        $profile = $response->getPersona();
        if (!$profile instanceof PersonaProfile) {
            throw $this->error(ErrorCode::PERSONA_NOT_FOUND);
        }
        $persona = $this->main($profile);
        $this->assertActiveUser($persona->userId);

        return $persona;
    }

    public function assertActiveUser(string $userId): void
    {
        try {
            $response = $this->call(
                '/user.v1.UserService/GetUser',
                new GetUserRequest(['user_id' => $userId]),
                GetUserResponse::class,
            );
        } catch (Exception $e) {
            throw $this->map($e);
        }

        $profile = $response->getUser();
        if ($profile === null) {
            throw $this->error(ErrorCode::USER_NOT_FOUND);
        }
        if ((int)$profile->getStatus() !== 1) {
            throw $this->error(ErrorCode::USER_BANNED);
        }
    }

    public function assertActiveMainUser(string $userId, int $operation): MainPersona
    {
        try {
            $response = $this->call(
                '/user.v1.UserService/ResolvePersonaMode',
                new ResolvePersonaModeRequest([
                    'user_id' => $userId,
                    // Account-level Social actions still execute through the
                    // account's MAIN persona. Never accept a persona mode
                    // supplied by the public request body here.
                    'mode' => 1,
                    'operation' => $operation,
                ]),
                ResolvePersonaModeResponse::class,
            );
        } catch (Exception $e) {
            throw $this->map($e);
        }

        $profile = $response->getPersona();
        if (!$profile instanceof PersonaProfile) {
            throw $this->error(ErrorCode::PERSONA_FORBIDDEN);
        }

        return $this->main($profile, $userId);
    }

    private function main(PersonaProfile $profile, ?string $expectedUserId = null): MainPersona
    {
        $userId = (string)$profile->getUserId();
        if ((int)$profile->getType() !== 1
            || (int)$profile->getStatus() !== 1
            || $userId === ''
            || ($expectedUserId !== null && $userId !== $expectedUserId)
        ) {
            throw $this->error(ErrorCode::PERSONA_FORBIDDEN);
        }

        return new MainPersona((string)$profile->getId(), $userId, (int)$profile->getStatus());
    }

    /**
     * @template T of Message
     * @param class-string<T> $responseClass
     * @return T
     * @throws Exception
     */
    private function call(string $method, Message $request, string $responseClass): Message
    {
        $client = $this->client ??= new UnaryGrpcClient($this->target, $this->serviceToken);

        return $client->call($method, $request, $responseClass, $this->timeoutMs);
    }

    private function map(Exception $exception): RuntimeException
    {
        if ($exception instanceof RuntimeException && $exception->getCode() > 0) {
            return $exception;
        }
        if ($exception instanceof GrpcCallException) {
            $code = match ($exception->grpcStatus) {
                STATUS_DEADLINE_EXCEEDED => ErrorCode::RPC_TIMEOUT->value,
                STATUS_NOT_FOUND => ErrorCode::USER_NOT_FOUND->value,
                STATUS_PERMISSION_DENIED => ErrorCode::PERSONA_FORBIDDEN->value,
                STATUS_INVALID_ARGUMENT => ErrorCode::INVALID_ARGUMENT->value,
                default => ErrorCode::SERVICE_UNAVAILABLE->value,
            };

            return new RuntimeException($exception->getMessage(), $code, $exception);
        }

        return new RuntimeException(ErrorCode::SERVICE_UNAVAILABLE->message(), ErrorCode::SERVICE_UNAVAILABLE->value, $exception);
    }

    private function error(ErrorCode $code): RuntimeException
    {
        return new RuntimeException($code->message(), $code->value);
    }
}
