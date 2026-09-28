<?php

declare(strict_types=1);

namespace Services\UserService\Transport\Grpc;

use Contracts\Errors\ErrorCode;
use Packages\Logging\JsonLogger;
use Packages\Tracing\TraceContext;
use Services\UserService\Application\Service\UserApplicationService;
use Throwable;

final class UserGrpcHandler
{
    public function __construct(
        private readonly UserApplicationService $userService,
        private readonly JsonLogger $logger
    ) {
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function handle(string $method, array $request, array $metadata = []): array
    {
        $trace = TraceContext::create(
            requestId: $metadata['x-request-id'][0] ?? $metadata['x-request-id'] ?? null,
            traceId: $metadata['x-trace-id'][0] ?? $metadata['x-trace-id'] ?? null,
            userId: $metadata['x-user-id'][0] ?? $metadata['x-user-id'] ?? null,
            personaId: $metadata['x-persona-id'][0] ?? $metadata['x-persona-id'] ?? null,
            deviceId: $metadata['x-device-id'][0] ?? $metadata['x-device-id'] ?? null,
            appVersion: $metadata['x-app-version'][0] ?? $metadata['x-app-version'] ?? null,
            traceparent: $metadata['traceparent'][0] ?? $metadata['traceparent'] ?? null,
        );
        TraceContext::setCurrent($trace);

        $this->logger->info("Handling User gRPC call: {$method}", ['method' => $method]);

        try {
            return match ($method) {
                'FindOrCreateByPhone', 'user.v1.UserService/FindOrCreateByPhone' => $this->findOrCreateByPhone($request),
                'GetUser', 'user.v1.UserService/GetUser' => $this->getUser($request),
                'BatchGetUsers', 'user.v1.UserService/BatchGetUsers' => $this->batchGetUsers($request),
                'CreatePersona', 'user.v1.UserService/CreatePersona' => $this->createPersona($request),
                'GetPersona', 'user.v1.UserService/GetPersona' => $this->getPersona($request),
                'BatchGetPersonas', 'user.v1.UserService/BatchGetPersonas' => $this->batchGetPersonas($request),
                'UpdatePersona', 'user.v1.UserService/UpdatePersona' => $this->updatePersona($request),
                'ListPersonas', 'user.v1.UserService/ListPersonas' => $this->listPersonas($request),
                'Check', 'common.v1.HealthService/Check' => $this->checkHealth($request),
                default => [
                    'code'    => ErrorCode::INVALID_ARGUMENT->value,
                    'message' => "Unknown gRPC method: {$method}",
                ],
            };
        } catch (Throwable $e) {
            $this->logger->error("Error in {$method}: " . $e->getMessage(), ['exception' => $e::class]);
            $code = $e->getCode() > 0 ? (int)$e->getCode() : ErrorCode::INTERNAL_SERVER_ERROR->value;
            return [
                'code'    => $code,
                'message' => $e->getMessage(),
            ];
        } finally {
            TraceContext::reset();
        }
    }

    private function findOrCreateByPhone(array $req): array
    {
        $phone = (string)($req['phone'] ?? '');
        $res = $this->userService->findOrCreateUserByPhone($phone);

        return [
            'code'        => 0,
            'user'        => $res['user']->toArray(),
            'is_new_user' => $res['is_new_user'],
        ];
    }

    private function getUser(array $req): array
    {
        $userId = (string)($req['user_id'] ?? '');
        $user = $this->userService->getUser($userId);

        return [
            'code' => 0,
            'user' => $user->toArray(),
        ];
    }

    private function batchGetUsers(array $req): array
    {
        $userIds = (array)($req['user_ids'] ?? []);
        $users = $this->userService->batchGetUsers($userIds);

        return [
            'code'  => 0,
            'users' => array_map(fn($u) => $u->toArray(), $users),
        ];
    }

    private function createPersona(array $req): array
    {
        $userId = (string)($req['user_id'] ?? '');
        $type = (int)($req['type'] ?? 1);
        $nickname = (string)($req['nickname'] ?? '');
        $avatarKey = !empty($req['avatar_key']) ? (string)$req['avatar_key'] : null;
        $bio = !empty($req['bio']) ? (string)$req['bio'] : null;

        $persona = $this->userService->createPersona($userId, $type, $nickname, $avatarKey, $bio);

        return [
            'code'    => 0,
            'persona' => $persona->toArray(),
        ];
    }

    private function getPersona(array $req): array
    {
        $personaId = (string)($req['persona_id'] ?? '');
        $persona = $this->userService->getPersona($personaId);

        return [
            'code'    => 0,
            'persona' => $persona->toArray(),
        ];
    }

    private function batchGetPersonas(array $req): array
    {
        $personaIds = (array)($req['persona_ids'] ?? []);
        $personas = $this->userService->batchGetPersonas($personaIds);

        return [
            'code'     => 0,
            'personas' => array_map(fn($p) => $p->toArray(), $personas),
        ];
    }

    private function updatePersona(array $req): array
    {
        $personaId = (string)($req['persona_id'] ?? '');
        $userId = (string)($req['user_id'] ?? '');
        $nickname = isset($req['nickname']) ? (string)$req['nickname'] : null;
        $avatarKey = isset($req['avatar_key']) ? (string)$req['avatar_key'] : null;
        $bio = isset($req['bio']) ? (string)$req['bio'] : null;

        $persona = $this->userService->updatePersona($personaId, $userId, $nickname, $avatarKey, $bio);

        return [
            'code'    => 0,
            'persona' => $persona->toArray(),
        ];
    }

    private function listPersonas(array $req): array
    {
        $userId = (string)($req['user_id'] ?? '');
        $personas = $this->userService->listPersonas($userId);

        return [
            'code'     => 0,
            'personas' => array_map(fn($p) => $p->toArray(), $personas),
        ];
    }

    private function checkHealth(array $req): array
    {
        return [
            'status'  => 1, // HEALTH_STATUS_SERVING
            'message' => 'UserService is healthy',
        ];
    }
}
