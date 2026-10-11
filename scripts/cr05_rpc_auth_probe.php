<?php

declare(strict_types=1);

/**
 * Verify the isolated service RPC boundary distinguishes a valid gateway
 * token from a deliberately invalid test token. No production secret belongs
 * in this script; all valid tokens are supplied through RPC_* environment
 * variables by the test runner.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Contracts\Gen\Chat\V1\GetConversationRequest;
use Contracts\Gen\Content\V1\GetPostRequest;
use Contracts\Gen\Message\V1\GetMessagesRequest;
use Contracts\Gen\Social\V1\LikeRequest;
use Contracts\Gen\User\V1\GetUserRequest;
use Contracts\Gen\User\V1\ResolvePersonaModeRequest;
use Contracts\Gen\User\V1\ResolvePersonaModeResponse;
use Contracts\Gen\User\V1\ValidatePersonaOwnershipRequest;
use Contracts\Gen\User\V1\ValidatePersonaOwnershipResponse;
use Google\Protobuf\Internal\Message;
use Packages\GrpcCommon\GrpcCallException;
use Packages\GrpcCommon\UnaryGrpcClient;
use Packages\Tracing\TraceContext;
use Spiral\RoadRunner\GRPC\StatusCode;

final class Cr05RpcAuthProbe
{
    /** @var array<string, string> */
    private array $targets;

    /** @var array<string, string> */
    private array $tokens;

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    public function __construct()
    {
        $this->targets = [
            'user' => $this->env('RPC_USER_TARGET', '127.0.0.1:50052'),
            'content' => $this->env('RPC_CONTENT_TARGET', '127.0.0.1:50053'),
            'social' => $this->env('RPC_SOCIAL_TARGET', '127.0.0.1:50054'),
            'chat' => $this->env('RPC_CHAT_TARGET', '127.0.0.1:50055'),
            'message' => $this->env('RPC_MESSAGE_TARGET', '127.0.0.1:50056'),
        ];
        $this->tokens = [
            'user' => $this->requiredEnv('RPC_GATEWAY_USER_TOKEN'),
            'content' => $this->requiredEnv('RPC_GATEWAY_CONTENT_TOKEN'),
            'social' => $this->requiredEnv('RPC_GATEWAY_SOCIAL_TOKEN'),
            'chat' => $this->requiredEnv('RPC_GATEWAY_CHAT_TOKEN'),
            'message' => $this->requiredEnv('RPC_GATEWAY_MESSAGE_TOKEN'),
        ];
    }

    public function run(): void
    {
        $cases = [
            ['user', '/user.v1.UserService/GetUser', new GetUserRequest(['user_id' => '0'])],
            ['content', '/content.v1.ContentService/GetPost', new GetPostRequest(['post_id' => '0'])],
            ['social', '/social.v1.SocialService/Like', new LikeRequest()],
            ['chat', '/chat.v1.ChatService/GetConversation', new GetConversationRequest(['conversation_id' => '0'])],
            ['message', '/message.v1.MessageService/GetMessages', new GetMessagesRequest(['conversation_id' => '0'])],
        ];
        $responseClasses = [
            'user' => \Contracts\Gen\User\V1\GetUserResponse::class,
            'content' => \Contracts\Gen\Content\V1\GetPostResponse::class,
            'social' => \Contracts\Gen\Social\V1\LikeResponse::class,
            'chat' => \Contracts\Gen\Chat\V1\GetConversationResponse::class,
            'message' => \Contracts\Gen\Message\V1\GetMessagesResponse::class,
        ];
        foreach ($cases as [$service, $method, $request]) {
            $this->expectStatus(
                $service . '.valid-token.invalid-request',
                $service,
                $this->tokens[$service],
                $method,
                $request,
                $responseClasses[$service],
                StatusCode::INVALID_ARGUMENT,
            );
            $this->expectStatus(
                $service . '.wrong-token.unauthenticated',
                $service,
                'cr05-invalid-token-only-for-test',
                $method,
                $request,
                $responseClasses[$service],
                StatusCode::UNAUTHENTICATED,
            );
        }

        // These two RPCs authorize a body-selected persona. A service token
        // alone is insufficient: the trusted account metadata must be
        // present before UserService reads the request user_id.
        $identityCases = [
            [
                'user.validate-persona.missing-user-metadata',
                'user',
                '/user.v1.UserService/ValidatePersonaOwnership',
                new ValidatePersonaOwnershipRequest([
                    'user_id' => '1001',
                    'persona_id' => '2001',
                    'operation' => 1,
                ]),
                ValidatePersonaOwnershipResponse::class,
            ],
            [
                'user.resolve-persona.missing-user-metadata',
                'user',
                '/user.v1.UserService/ResolvePersonaMode',
                new ResolvePersonaModeRequest([
                    'user_id' => '1001',
                    'mode' => 1,
                    'operation' => 1,
                ]),
                ResolvePersonaModeResponse::class,
            ],
        ];
        foreach ($identityCases as [$label, $service, $method, $request, $responseClass]) {
            $this->expectStatus(
                $label,
                $service,
                $this->tokens[$service],
                $method,
                $request,
                $responseClass,
                StatusCode::UNAUTHENTICATED,
            );
        }

        echo json_encode(['ok' => true, 'checks' => $this->rows], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
    }

    /** @param class-string<Message> $responseClass */
    private function expectStatus(
        string $label,
        string $service,
        string $token,
        string $method,
        Message $request,
        string $responseClass,
        int $expected,
    ): void {
        $previous = getenv('SERVICE_NAME');
        putenv('SERVICE_NAME=api-gateway');
        TraceContext::setCurrent(TraceContext::create(requestId: 'cr05-auth-' . bin2hex(random_bytes(6))));
        $caught = null;
        try {
            (new UnaryGrpcClient($this->targets[$service], $token))->call($method, $request, $responseClass, 5000);
        } catch (Throwable $e) {
            $caught = $e;
        } finally {
            TraceContext::reset();
            if ($previous === false) {
                putenv('SERVICE_NAME');
            } else {
                putenv('SERVICE_NAME=' . $previous);
            }
        }
        if (!$caught instanceof GrpcCallException) {
            throw new RuntimeException($label . ' did not return a gRPC failure', 0, $caught);
        }
        if ($caught->grpcStatus !== $expected) {
            throw new RuntimeException(sprintf(
                '%s returned grpc=%d, expected %d: %s', $label, $caught->grpcStatus, $expected, $caught->getMessage(),
            ), 0, $caught);
        }
        $this->rows[] = ['step' => $label, 'ok' => true, 'grpc_status' => $caught->grpcStatus];
    }

    private function env(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || trim($value) === '' ? $default : trim($value);
    }

    private function requiredEnv(string $name): string
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            throw new RuntimeException('Missing required test environment variable: ' . $name);
        }

        return trim($value);
    }
}

try {
    (new Cr05RpcAuthProbe())->run();
} catch (Throwable $e) {
    fwrite(STDERR, 'cr05_rpc_auth_probe failed: ' . $e::class . ': ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
