<?php

declare(strict_types=1);

namespace Services\UserService\Transport\Grpc;

use Contracts\Errors\ErrorCode;
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
use Contracts\Gen\User\V1\UpdatePersonaRequest;
use Contracts\Gen\User\V1\UpdatePersonaResponse;
use Contracts\Gen\User\V1\UpdateUserRequest;
use Contracts\Gen\User\V1\UpdateUserResponse;
use Contracts\Gen\User\V1\UserProfile;
use Contracts\Gen\User\V1\UserServiceInterface;
use Packages\Logging\JsonLogger;
use Packages\Tracing\TraceContext;
use Services\UserService\Application\Service\UserApplicationService;
use Services\UserService\Domain\Entity\Persona;
use Services\UserService\Domain\Entity\User;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\ResponseTrailers;
use Spiral\RoadRunner\GRPC\StatusCode;
use Throwable;

final class UserGrpcService implements UserServiceInterface
{
    public function __construct(
        private readonly UserApplicationService $userService,
        private readonly JsonLogger $logger,
    ) {
    }

    public function FindOrCreateByPhone(
        ContextInterface $ctx,
        FindOrCreateByPhoneRequest $in
    ): FindOrCreateByPhoneResponse {
        return $this->call($ctx, 'FindOrCreateByPhone', function () use ($in): FindOrCreateByPhoneResponse {
            $phone = trim($in->getPhone());
            if ($phone === '') {
                throw GRPCException::create('phone is required', StatusCode::INVALID_ARGUMENT);
            }

            $result = $this->userService->findOrCreateUserByPhone($phone);

            return (new FindOrCreateByPhoneResponse())
                ->setUser($this->toUserProfile($result['user']))
                ->setIsNewUser((bool)$result['is_new_user']);
        });
    }

    public function GetUser(ContextInterface $ctx, GetUserRequest $in): GetUserResponse
    {
        return $this->call($ctx, 'GetUser', function () use ($in): GetUserResponse {
            $user = $this->userService->getUser($this->requireId($in->getUserId(), 'user_id'));

            return (new GetUserResponse())->setUser($this->toUserProfile($user));
        });
    }

    public function BatchGetUsers(ContextInterface $ctx, BatchGetUsersRequest $in): BatchGetUsersResponse
    {
        return $this->call($ctx, 'BatchGetUsers', function () use ($in): BatchGetUsersResponse {
            $users = [];
            foreach ($this->userService->batchGetUsers($this->ids($in->getUserIds(), 'user_ids')) as $user) {
                $users[] = $this->toUserProfile($user);
            }

            return (new BatchGetUsersResponse())->setUsers($users);
        });
    }

    public function UpdateUser(ContextInterface $ctx, UpdateUserRequest $in): UpdateUserResponse
    {
        return $this->call($ctx, 'UpdateUser', function (): UpdateUserResponse {
            throw GRPCException::create('UpdateUser is not implemented', StatusCode::UNIMPLEMENTED);
        });
    }

    public function CreatePersona(ContextInterface $ctx, CreatePersonaRequest $in): CreatePersonaResponse
    {
        return $this->call($ctx, 'CreatePersona', function () use ($in): CreatePersonaResponse {
            $persona = $this->userService->createPersona(
                userId: $this->requireId($in->getUserId(), 'user_id'),
                type: $in->getType(),
                nickname: $in->getNickname(),
                avatarKey: $in->getAvatarKey() !== '' ? $in->getAvatarKey() : null,
                bio: $in->getBio() !== '' ? $in->getBio() : null,
            );

            return (new CreatePersonaResponse())->setPersona($this->toPersonaProfile($persona));
        });
    }

    public function GetPersona(ContextInterface $ctx, GetPersonaRequest $in): GetPersonaResponse
    {
        return $this->call($ctx, 'GetPersona', function () use ($in): GetPersonaResponse {
            $persona = $this->userService->getPersona($this->requireId($in->getPersonaId(), 'persona_id'));

            return (new GetPersonaResponse())->setPersona($this->toPersonaProfile($persona));
        });
    }

    public function BatchGetPersonas(ContextInterface $ctx, BatchGetPersonasRequest $in): BatchGetPersonasResponse
    {
        return $this->call($ctx, 'BatchGetPersonas', function () use ($in): BatchGetPersonasResponse {
            $personas = [];
            foreach ($this->userService->batchGetPersonas($this->ids($in->getPersonaIds(), 'persona_ids')) as $persona) {
                $personas[] = $this->toPersonaProfile($persona);
            }

            return (new BatchGetPersonasResponse())->setPersonas($personas);
        });
    }

    public function UpdatePersona(ContextInterface $ctx, UpdatePersonaRequest $in): UpdatePersonaResponse
    {
        return $this->call($ctx, 'UpdatePersona', function () use ($in): UpdatePersonaResponse {
            $persona = $this->userService->updatePersona(
                personaId: $this->requireId($in->getPersonaId(), 'persona_id'),
                userId: $this->requireId($in->getUserId(), 'user_id'),
                nickname: $in->hasNickname() ? $in->getNickname() : null,
                avatarKey: $in->hasAvatarKey() ? $in->getAvatarKey() : null,
                bio: $in->hasBio() ? $in->getBio() : null,
            );

            return (new UpdatePersonaResponse())->setPersona($this->toPersonaProfile($persona));
        });
    }

    public function ListPersonas(ContextInterface $ctx, ListPersonasRequest $in): ListPersonasResponse
    {
        return $this->call($ctx, 'ListPersonas', function () use ($in): ListPersonasResponse {
            $personas = [];
            foreach ($this->userService->listPersonas($this->requireId($in->getUserId(), 'user_id')) as $persona) {
                $personas[] = $this->toPersonaProfile($persona);
            }

            return (new ListPersonasResponse())->setPersonas($personas);
        });
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function call(ContextInterface $ctx, string $method, callable $operation): mixed
    {
        $trace = TraceContext::create(
            requestId: $this->metadataValue($ctx, 'x-request-id'),
            traceId: $this->metadataValue($ctx, 'x-trace-id'),
            userId: $this->metadataValue($ctx, 'x-user-id'),
            personaId: $this->metadataValue($ctx, 'x-persona-id'),
            deviceId: $this->metadataValue($ctx, 'x-device-id'),
            appVersion: $this->metadataValue($ctx, 'x-app-version'),
            traceparent: $this->metadataValue($ctx, 'traceparent'),
        );
        TraceContext::setCurrent($trace);
        $this->logger->info("Handling User gRPC call: {$method}", ['method' => $method]);

        try {
            return $operation();
        } catch (GRPCException $e) {
            $code = (int)$e->getCode();
            if ($code > 0) {
                $this->attachAppErrorTrailer($ctx, $code);
            }
            throw $e;
        } catch (Throwable $e) {
            $this->logger->error("Error in {$method}: " . $e->getMessage(), [
                'exception' => $e::class,
                'error_code' => $e->getCode(),
            ]);

            $code = (int)$e->getCode();
            if ($code > 0) {
                $this->attachAppErrorTrailer($ctx, $code);
            }

            throw GRPCException::create(
                $this->publicErrorMessage($e),
                // @phpstan-ignore argument.type (grpcStatus returns StatusCode constants; the stub's literal-union param can't be inferred through match)
                $this->grpcStatus($e),
                $e,
            );
        } finally {
            TraceContext::reset();
        }
    }

    private function attachAppErrorTrailer(ContextInterface $ctx, int $code): void
    {
        if ($code <= 0) {
            return;
        }

        $trailers = $ctx->getValue(ResponseTrailers::class);
        if ($trailers instanceof ResponseTrailers) {
            $trailers->set('x-app-error-code', (string)$code);
        }
    }

    private function requireId(int|string $id, string $field): string
    {
        $value = (string)$id;
        if ($value === '' || $value === '0' || strspn($value, '0123456789') !== strlen($value)) {
            throw GRPCException::create("{$field} is required", StatusCode::INVALID_ARGUMENT);
        }

        return $value;
    }

    /**
     * @param iterable<int, int|string> $ids
     * @return array<string>
     */
    private function ids(iterable $ids, string $field): array
    {
        $result = [];
        foreach ($ids as $id) {
            $result[] = $this->requireId($id, $field);
        }

        return $result;
    }

    private function metadataValue(ContextInterface $ctx, string $key): ?string
    {
        $values = $ctx->getValues();
        $value = $values[$key] ?? null;
        if ($value === null && is_array($values['metadata'] ?? null)) {
            $value = $values['metadata'][$key] ?? null;
        }
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_scalar($value) && (string)$value !== '' ? (string)$value : null;
    }

    private function toUserProfile(User $user): UserProfile
    {
        return (new UserProfile())
            ->setId((string)$user->id)
            ->setStatus($user->status)
            ->setPhone($user->phone ?? '')
            ->setEmail($user->email ?? '')
            ->setRegisterChannel($user->registerChannel)
            ->setRiskLevel($user->riskLevel)
            ->setCreatedAt($user->createdAt ?? '')
            ->setUpdatedAt($user->updatedAt ?? '');
    }

    private function toPersonaProfile(Persona $persona): PersonaProfile
    {
        return (new PersonaProfile())
            ->setId((string)$persona->id)
            ->setUserId((string)$persona->userId)
            ->setType($persona->type)
            ->setNickname($persona->nickname)
            ->setAvatarKey($persona->avatarKey ?? '')
            ->setBio($persona->bio ?? '')
            ->setStatus($persona->status)
            ->setCreatedAt($persona->createdAt ?? '')
            ->setUpdatedAt($persona->updatedAt ?? '');
    }

    private function grpcStatus(Throwable $exception): int
    {
        return match ((int)$exception->getCode()) {
            ErrorCode::INVALID_ARGUMENT->value => StatusCode::INVALID_ARGUMENT,
            ErrorCode::USER_NOT_FOUND->value,
            ErrorCode::PERSONA_NOT_FOUND->value => StatusCode::NOT_FOUND,
            ErrorCode::USER_ALREADY_EXISTS->value => StatusCode::ALREADY_EXISTS,
            ErrorCode::PERSONA_FORBIDDEN->value,
            ErrorCode::USER_BANNED->value => StatusCode::PERMISSION_DENIED,
            ErrorCode::PERSONA_LIMIT_EXCEEDED->value,
            ErrorCode::RATE_LIMIT_EXCEEDED->value => StatusCode::RESOURCE_EXHAUSTED,
            ErrorCode::RPC_TIMEOUT->value => StatusCode::DEADLINE_EXCEEDED,
            ErrorCode::IDEMPOTENCY_CONFLICT->value => StatusCode::ABORTED,
            ErrorCode::SERVICE_UNAVAILABLE->value => StatusCode::UNAVAILABLE,
            default => StatusCode::INTERNAL,
        };
    }

    private function publicErrorMessage(Throwable $exception): string
    {
        return match ((int)$exception->getCode()) {
            ErrorCode::INVALID_ARGUMENT->value,
            ErrorCode::USER_NOT_FOUND->value,
            ErrorCode::PERSONA_NOT_FOUND->value,
            ErrorCode::USER_ALREADY_EXISTS->value,
            ErrorCode::PERSONA_FORBIDDEN->value,
            ErrorCode::USER_BANNED->value,
            ErrorCode::PERSONA_LIMIT_EXCEEDED->value,
            ErrorCode::RATE_LIMIT_EXCEEDED->value,
            ErrorCode::RPC_TIMEOUT->value,
            ErrorCode::IDEMPOTENCY_CONFLICT->value,
            ErrorCode::SERVICE_UNAVAILABLE->value => $exception->getMessage(),
            default => ErrorCode::INTERNAL_SERVER_ERROR->message(),
        };
    }
}
