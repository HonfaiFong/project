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
use Contracts\Gen\User\V1\FindOrCreateBySocialIdentityRequest;
use Contracts\Gen\User\V1\FindOrCreateBySocialIdentityResponse;
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
use Contracts\Gen\User\V1\ValidatePersonaOwnershipRequest;
use Contracts\Gen\User\V1\ValidatePersonaOwnershipResponse;
use Packages\GrpcCommon\ErrorStatusMapper;
use Packages\GrpcCommon\InternalRpcAuthorizer;
use Psr\Log\LoggerInterface;
use Packages\Tracing\TraceContext;
use Services\UserService\Application\Service\UserApplicationService;
use Services\UserService\Domain\Entity\Persona;
use Services\UserService\Domain\Entity\User;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\ResponseTrailers;
use Spiral\RoadRunner\GRPC\StatusCode;
use Throwable;
use Random\RandomException;

final readonly class UserGrpcService implements UserServiceInterface
{
    public function __construct(
        private UserApplicationService $userService,
        private LoggerInterface $logger,
        private ?InternalRpcAuthorizer $authorizer = null,
    ) {
    }

    /**
     * @throws RandomException
     * @throws GRPCException
     */
    public function FindOrCreateByPhone(
        ContextInterface $ctx,
        FindOrCreateByPhoneRequest $in
    ): FindOrCreateByPhoneResponse {
        return $this->call($ctx, 'FindOrCreateByPhone', function () use ($in): FindOrCreateByPhoneResponse {
            $phone = trim($in->getPhone());
            if ($phone === '') {
                throw GRPCException::create('phone is required', StatusCode::INVALID_ARGUMENT);
            }

            $result = $this->userService->findOrCreateUserByPhone(
                $phone,
                trim($in->getRegisterChannel()) !== '' ? trim($in->getRegisterChannel()) : 'default',
            );

            return (new FindOrCreateByPhoneResponse())
                ->setUser($this->toUserProfile($result['user']))
                ->setIsNewUser((bool)$result['is_new_user']);
        });
    }

    /**
     * @throws RandomException
     * @throws GRPCException
     */
    public function FindOrCreateBySocialIdentity(
        ContextInterface $ctx,
        FindOrCreateBySocialIdentityRequest $in
    ): FindOrCreateBySocialIdentityResponse {
        return $this->call($ctx, 'FindOrCreateBySocialIdentity', function () use ($in): FindOrCreateBySocialIdentityResponse {
            $registrationKey = trim($in->getRegistrationKey());
            if ($registrationKey === '') {
                throw GRPCException::create('registration_key is required', StatusCode::INVALID_ARGUMENT);
            }

            $result = $this->userService->findOrCreateUserBySocialIdentity(
                $registrationKey,
                trim($in->getRegisterChannel()) !== '' ? trim($in->getRegisterChannel()) : 'default',
            );

            return (new FindOrCreateBySocialIdentityResponse())
                ->setUser($this->toUserProfile($result['user']))
                ->setIsNewUser((bool)$result['is_new_user']);
        });
    }

    /**
     * @throws RandomException
     * @throws GRPCException
     */
    public function GetUser(ContextInterface $ctx, GetUserRequest $in): GetUserResponse
    {
        return $this->call($ctx, 'GetUser', function () use ($in): GetUserResponse {
            $user = $this->userService->getUser($this->requireId($in->getUserId(), 'user_id'));

            return (new GetUserResponse())->setUser($this->toUserProfile($user));
        });
    }

    /**
     * @throws RandomException
     * @throws GRPCException
     */
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

    /**
     * @throws RandomException
     * @throws GRPCException
     */
    public function UpdateUser(ContextInterface $ctx, UpdateUserRequest $in): UpdateUserResponse
    {
        return $this->call($ctx, 'UpdateUser', function () use ($in): UpdateUserResponse {
            $user = $this->userService->updateUser(
                userId: $this->requireId($in->getUserId(), 'user_id'),
                phone: $in->hasPhone() ? $in->getPhone() : null,
                email: $in->hasEmail() ? $in->getEmail() : null,
            );

            return (new UpdateUserResponse())->setUser($this->toUserProfile($user));
        });
    }

    /**
     * @throws RandomException
     * @throws GRPCException
     */
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

    /**
     * @throws RandomException
     * @throws GRPCException
     */
    public function GetPersona(ContextInterface $ctx, GetPersonaRequest $in): GetPersonaResponse
    {
        return $this->call($ctx, 'GetPersona', function () use ($in): GetPersonaResponse {
            $persona = $this->userService->getPersona($this->requireId($in->getPersonaId(), 'persona_id'));

            return (new GetPersonaResponse())->setPersona($this->toPersonaProfile($persona));
        });
    }

    /**
     * Internal identity authorization used by gateway actions before they
     * promote a requested persona into trusted request context.
     *
     * @throws RandomException
     * @throws GRPCException
     */
    public function ValidatePersonaOwnership(
        ContextInterface $ctx,
        ValidatePersonaOwnershipRequest $in
    ): ValidatePersonaOwnershipResponse {
        return $this->call($ctx, 'ValidatePersonaOwnership', function () use ($in): ValidatePersonaOwnershipResponse {
            $persona = $this->userService->assertPersonaUsableByUser(
                userId: $this->requireId($in->getUserId(), 'user_id'),
                personaId: $this->requireId($in->getPersonaId(), 'persona_id'),
            );

            return (new ValidatePersonaOwnershipResponse())->setPersona($this->toPersonaProfile($persona));
        });
    }

    /**
     * @throws RandomException
     * @throws GRPCException
     */
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

    /**
     * @throws RandomException
     * @throws GRPCException
     */
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

    /**
     * @throws RandomException
     * @throws GRPCException
     */
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
     * @throws GRPCException
     * @throws RandomException
     */
    private function call(ContextInterface $ctx, string $method, callable $operation): mixed
    {
        $this->authorizer?->authorize($ctx, $method);

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
        $this->logger->info("Handling User gRPC call: $method", ['method' => $method]);

        try {
            return $operation();
        } catch (GRPCException $e) {
            // GRPCException::getCode() is the gRPC transport status (e.g. INVALID_ARGUMENT = 3),
            // never a business ErrorCode, so it must not be propagated as x-app-error-code.
            // The gateway client maps the transport status via its own fallback table.
            throw $e;
        } catch (Throwable $e) {
            // Each service maps its own domain exceptions and response trailers.
            /** @noinspection DuplicatedCode */
            $this->logger->error("Error in $method: " . $e->getMessage(), [
                'exception' => $e::class,
                'error_code' => $e->getCode(),
            ]);

            $error = $this->appErrorCode((int)$e->getCode());
            if ($error !== null) {
                $this->attachAppErrorTrailer($ctx, $error->value);
            }

            throw GRPCException::create(
                $this->publicErrorMessage($e),
                ErrorStatusMapper::forExceptionCode((int)$e->getCode()),
                $e,
            );
        } finally {
            TraceContext::reset();
        }
    }

    /**
     * Only contract ErrorCode values may travel in `x-app-error-code`: the gateway client treats a
     * present trailer as the authoritative business code and skips its gRPC status fallback
     * entirely. Infrastructure exception codes (PDO 23000, arbitrary codes) resolve to null, and
     * OK (0) is excluded too -- it means success, not a failure to publish.
     */
    private function appErrorCode(int $code): ?ErrorCode
    {
        $error = $code > 0 ? ErrorCode::tryFrom($code) : null;

        return $error === ErrorCode::OK ? null : $error;
    }

    private function attachAppErrorTrailer(ContextInterface $ctx, int $code): void
    {
        $trailers = $ctx->getValue(ResponseTrailers::class);
        if ($trailers instanceof ResponseTrailers) {
            $trailers->set('x-app-error-code', (string)$code);
        }
    }

    private function requireId(int|string $id, string $field): string
    {
        $value = (string)$id;
        if ($value === '' || $value === '0' || strspn($value, '0123456789') !== strlen($value)) {
            throw GRPCException::create("$field is required", StatusCode::INVALID_ARGUMENT);
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

    /**
     * Service trace parsing stays independent of internal caller authorization.
     * @noinspection DuplicatedCode
     */
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
            ->setId($user->id)
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
            ->setId($persona->id)
            ->setUserId($persona->userId)
            ->setType($persona->type)
            ->setNickname($persona->nickname)
            ->setAvatarKey($persona->avatarKey ?? '')
            ->setBio($persona->bio ?? '')
            ->setStatus($persona->status)
            ->setCreatedAt($persona->createdAt ?? '')
            ->setUpdatedAt($persona->updatedAt ?? '');
    }

    private function publicErrorMessage(Throwable $exception): string
    {
        // PDO exceptions can return SQLSTATE strings; retain integer normalization.
        /** @noinspection PhpCastIsUnnecessaryInspection */
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
