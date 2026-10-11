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
use Contracts\Gen\User\V1\ResolvePersonaModeRequest;
use Contracts\Gen\User\V1\ResolvePersonaModeResponse;
use Packages\GrpcCommon\BusinessActor;
use Packages\GrpcCommon\ErrorStatusMapper;
use Packages\GrpcCommon\InternalRpcAuthorizer;
use Packages\Observability\Span;
use Packages\Observability\Tracer;
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
        return $this->call($ctx, 'CreatePersona', function (): CreatePersonaResponse {
            // ALIAS is a server-owned anonymous mode. Keep the legacy RPC in
            // the protocol for rolling upgrades, but never create a public
            // fixed alias from client-controlled profile fields.
            throw new \RuntimeException(
                'creating personas is disabled; select MAIN or server-owned ALIAS mode',
                ErrorCode::PERSONA_FORBIDDEN->value,
            );
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
        return $this->call($ctx, 'ValidatePersonaOwnership', function () use ($ctx, $in): ValidatePersonaOwnershipResponse {
            $this->assertTrustedUserMatchesRequest($ctx, $in->getUserId());
            $persona = $this->userService->assertPersonaUsableByUser(
                userId: $this->requireId($in->getUserId(), 'user_id'),
                personaId: $this->requireId($in->getPersonaId(), 'persona_id'),
                operation: $in->getOperation(),
            );

            return (new ValidatePersonaOwnershipResponse())->setPersona($this->toPersonaProfile($persona));
        });
    }

    /**
     * Resolve MAIN or the canonical internal ALIAS for an authenticated
     * account. This RPC returns an internal profile and is never a public
     * directory lookup.
     *
     * @throws RandomException
     * @throws GRPCException
     */
    public function ResolvePersonaMode(
        ContextInterface $ctx,
        ResolvePersonaModeRequest $in
    ): ResolvePersonaModeResponse {
        return $this->call($ctx, 'ResolvePersonaMode', function () use ($ctx, $in): ResolvePersonaModeResponse {
            $this->assertTrustedUserMatchesRequest($ctx, $in->getUserId());
            $persona = $this->userService->resolvePersonaMode(
                userId: $this->requireId($in->getUserId(), 'user_id'),
                mode: $in->getMode(),
                operation: $in->getOperation(),
            );

            return (new ResolvePersonaModeResponse())->setPersona($this->toPersonaProfile($persona));
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
        $scope = Tracer::enter('grpc.server', Span::KIND_SERVER, [
            'rpc.system' => 'grpc',
            'rpc.method' => $method,
            'server.address' => getenv('SERVICE_NAME') ?: 'user-service',
        ]);
        $this->logger->info("Handling User gRPC call: $method", ['method' => $method]);

        try {
            return $operation();
        } catch (GRPCException $e) {
            $scope->fail($e);
            // GRPCException::getCode() 是 gRPC 传输状态（如 INVALID_ARGUMENT = 3），不是业务 ErrorCode，因此不能作为
            // x-app-error-code 传递。网关客户端通过自己的回退映射表处理传输状态。
            throw $e;
        } catch (Throwable $e) {
            $scope->fail($e);
            // 各服务自行映射领域异常和响应尾部元数据。
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
            $scope->close();
            TraceContext::reset();
        }
    }

    /**
     * `x-app-error-code` 仅能传递协议定义的 ErrorCode；网关客户端将存在的尾部元数据视为权威业务代码，并完全跳过 gRPC
     * 状态回退。基础设施异常代码（如 PDO 23000 或任意代码）解析为 null；OK（0）也须排除，因为它表示成功，不能作为失
     * 败代码发布。
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
     * 服务追踪解析与内部调用方授权保持独立。
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

    /**
     * Identity authorization is only valid with a trusted account metadata
     * value. Directory lookups intentionally do not use this guard, but
     * ResolvePersonaMode and ValidatePersonaOwnership must never fall back to
     * a body-only user id.
     */
    private function assertTrustedUserMatchesRequest(ContextInterface $ctx, int|string $requestUserId): void
    {
        $actor = BusinessActor::fromContext($ctx);
        if ($actor->userId !== (string)$requestUserId) {
            throw GRPCException::create('authenticated user does not match request', StatusCode::PERMISSION_DENIED);
        }
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
            ->setUpdatedAt($persona->updatedAt ?? '')
            ->setIsInternalAlias($persona->isInternalAlias)
            ->setIdentityMode($persona->type === 1 ? 'main' : 'alias')
            ->setProfileAccessible($persona->type === 1);
    }

    private function publicErrorMessage(Throwable $exception): string
    {
        // PDO 异常可能返回 SQLSTATE 字符串，保留整数规范化处理。
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
