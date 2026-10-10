<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Grpc;

use Contracts\Gen\Common\V1\HealthCheckRequest;
use Contracts\Gen\Common\V1\HealthCheckResponse;
use Contracts\Gen\User\V1\BatchGetPersonasRequest;
use Contracts\Gen\User\V1\BatchGetPersonasResponse;
use Contracts\Gen\User\V1\BatchGetUsersRequest;
use Contracts\Gen\User\V1\BatchGetUsersResponse;
use Contracts\Gen\User\V1\CreatePersonaRequest;
use Contracts\Gen\User\V1\CreatePersonaResponse;
use Contracts\Gen\User\V1\FindOrCreateByPhoneRequest;
use Contracts\Gen\User\V1\FindOrCreateByPhoneResponse;
use Contracts\Gen\User\V1\GetPersonaRequest;
use Contracts\Gen\User\V1\GetPersonaResponse;
use Contracts\Gen\User\V1\GetUserRequest;
use Contracts\Gen\User\V1\GetUserResponse;
use Contracts\Gen\User\V1\ListPersonasRequest;
use Contracts\Gen\User\V1\ListPersonasResponse;
use Contracts\Gen\User\V1\PersonaProfile;
use Contracts\Errors\ErrorCode;
use Contracts\Gen\User\V1\UpdatePersonaRequest;
use Contracts\Gen\User\V1\UpdatePersonaResponse;
use Contracts\Gen\User\V1\UpdateUserRequest;
use Contracts\Gen\User\V1\UpdateUserResponse;
use Contracts\Gen\User\V1\UserProfile;
use Google\Protobuf\Internal\Message;
use Packages\GrpcCommon\GrpcCallException;
use Packages\GrpcCommon\UnaryGrpcClient;
use RuntimeException;
use const Grpc\STATUS_DEADLINE_EXCEEDED;
use Apps\ApiGateway\Action\PersonaLookup;
use const Grpc\STATUS_INVALID_ARGUMENT;
use const Grpc\STATUS_NOT_FOUND;
use const Grpc\STATUS_PERMISSION_DENIED;
use const Grpc\STATUS_RESOURCE_EXHAUSTED;

final class UserServiceClient implements HealthCheckClient, PersonaLookup
{
    private ?UnaryGrpcClient $client;

    public function __construct(
        private readonly ?string $target = null,
        private readonly int $timeoutMs = 150,
        private readonly ?string $serviceToken = null,
        ?UnaryGrpcClient $client = null,
    ) {
        $this->client = $client;
    }

    public function findOrCreateByPhone(string $phone, string $registerChannel = 'default'): array
    {
        $response = $this->call('/user.v1.UserService/FindOrCreateByPhone', new FindOrCreateByPhoneRequest([
            'phone'            => $phone,
            'register_channel' => $registerChannel,
        ]), FindOrCreateByPhoneResponse::class);

        return [
            'user'        => $this->user($response->getUser()),
            'is_new_user' => $response->getIsNewUser(),
        ];
    }

    public function getUser(string $userId): array
    {
        $response = $this->call('/user.v1.UserService/GetUser', new GetUserRequest([
            'user_id' => $userId,
        ]), GetUserResponse::class);

        return ['user' => $this->user($response->getUser())];
    }

    public function batchGetUsers(array $userIds): array
    {
        $response = $this->call('/user.v1.UserService/BatchGetUsers', new BatchGetUsersRequest([
            'user_ids' => $userIds,
        ]), BatchGetUsersResponse::class);

        return ['users' => array_map(fn (UserProfile $user): array => $this->user($user), iterator_to_array($response->getUsers()))];
    }

    public function updateUser(string $userId, ?string $phone = null, ?string $email = null): array
    {
        $fields = [
            'user_id' => $userId,
        ];
        if ($phone !== null) {
            $fields['phone'] = $phone;
        }
        if ($email !== null) {
            $fields['email'] = $email;
        }

        $response = $this->call('/user.v1.UserService/UpdateUser', new UpdateUserRequest($fields), UpdateUserResponse::class);

        return ['user' => $this->user($response->getUser())];
    }

    public function createPersona(string $userId, int $type, string $nickname, ?string $avatarKey, ?string $bio): array
    {
        $response = $this->call('/user.v1.UserService/CreatePersona', new CreatePersonaRequest([
            'user_id'    => $userId,
            'type'       => $type,
            'nickname'   => $nickname,
            'avatar_key' => $avatarKey ?? '',
            'bio'        => $bio ?? '',
        ]), CreatePersonaResponse::class);

        return ['persona' => $this->persona($response->getPersona())];
    }

    public function getPersona(string $personaId): array
    {
        $response = $this->call('/user.v1.UserService/GetPersona', new GetPersonaRequest([
            'persona_id' => $personaId,
        ]), GetPersonaResponse::class);

        return ['persona' => $this->persona($response->getPersona())];
    }

    public function batchGetPersonas(array $personaIds): array
    {
        $response = $this->call('/user.v1.UserService/BatchGetPersonas', new BatchGetPersonasRequest([
            'persona_ids' => $personaIds,
        ]), BatchGetPersonasResponse::class);

        return ['personas' => array_map(fn (PersonaProfile $persona): array => $this->persona($persona), iterator_to_array($response->getPersonas()))];
    }

    public function updatePersona(string $personaId, string $userId, ?string $nickname, ?string $avatarKey, ?string $bio): array
    {
        // UpdatePersonaRequest fields are proto3 `optional`: only set the keys that
        // were actually provided, otherwise the server sees presence and overwrites
        // the stored value with an empty string (PATCH would clear unspecified fields).
        $fields = [
            'persona_id' => $personaId,
            'user_id'    => $userId,
        ];
        if ($nickname !== null) {
            $fields['nickname'] = $nickname;
        }
        if ($avatarKey !== null) {
            $fields['avatar_key'] = $avatarKey;
        }
        if ($bio !== null) {
            $fields['bio'] = $bio;
        }

        $response = $this->call('/user.v1.UserService/UpdatePersona', new UpdatePersonaRequest($fields), UpdatePersonaResponse::class);

        return ['persona' => $this->persona($response->getPersona())];
    }

    public function listPersonas(string $userId): array
    {
        $response = $this->call('/user.v1.UserService/ListPersonas', new ListPersonasRequest([
            'user_id' => $userId,
        ]), ListPersonasResponse::class);

        return ['personas' => array_map(fn (PersonaProfile $persona): array => $this->persona($persona), iterator_to_array($response->getPersonas()))];
    }

    public function checkHealth(): array
    {
        $response = $this->call('/common.v1.HealthService/Check', new HealthCheckRequest(), HealthCheckResponse::class);

        return [
            'status'  => $response->getStatus(),
            'message' => $response->getMessage(),
        ];
    }

    /**
     * @template T of Message
     * @param class-string<T> $responseClass
     * @return T
     */
    private function call(string $method, Message $request, string $responseClass): Message
    {
        $client = $this->client ??= new UnaryGrpcClient(
            $this->target ?: config('gateway.grpc.user.target', 'user-service:50052'),
            $this->serviceToken,
        );

        try {
            return $client->call($method, $request, $responseClass, $this->timeoutMs);
        } catch (GrpcCallException $e) {
            throw $this->mapGrpcCallException($e);
        }
    }

    private function mapGrpcCallException(GrpcCallException $e): RuntimeException
    {
        if ($e->appErrorCode !== null) {
            return new RuntimeException($e->getMessage(), $e->appErrorCode, $e);
        }

        $code = match ($e->grpcStatus) {
            STATUS_DEADLINE_EXCEEDED => ErrorCode::RPC_TIMEOUT->value,
            STATUS_NOT_FOUND => ErrorCode::USER_NOT_FOUND->value,
            STATUS_PERMISSION_DENIED => ErrorCode::PERSONA_FORBIDDEN->value,
            STATUS_INVALID_ARGUMENT => ErrorCode::INVALID_ARGUMENT->value,
            STATUS_RESOURCE_EXHAUSTED => ErrorCode::RATE_LIMIT_EXCEEDED->value,
            default => ErrorCode::SERVICE_UNAVAILABLE->value,
        };

        return new RuntimeException($e->getMessage(), $code, $e);
    }

    /** @return array<string, mixed> */
    private function user(?UserProfile $user): array
    {
        if ($user === null) {
            return [];
        }

        return [
            'id'               => (string)$user->getId(),
            'status'           => $user->getStatus(),
            'phone'            => $user->getPhone(),
            'email'            => $user->getEmail(),
            'register_channel' => $user->getRegisterChannel(),
            'risk_level'       => $user->getRiskLevel(),
            'created_at'       => $user->getCreatedAt(),
            'updated_at'       => $user->getUpdatedAt(),
        ];
    }

    /** @return array<string, mixed> */
    private function persona(?PersonaProfile $persona): array
    {
        if ($persona === null) {
            return [];
        }

        return [
            'id'         => (string)$persona->getId(),
            'user_id'    => (string)$persona->getUserId(),
            'type'       => $persona->getType(),
            'nickname'   => $persona->getNickname(),
            'avatar_key' => $persona->getAvatarKey(),
            'bio'        => $persona->getBio(),
            'status'     => $persona->getStatus(),
            'created_at' => $persona->getCreatedAt(),
            'updated_at' => $persona->getUpdatedAt(),
        ];
    }
}
