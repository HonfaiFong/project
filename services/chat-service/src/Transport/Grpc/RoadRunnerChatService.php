<?php

declare(strict_types=1);

namespace Services\ChatService\Transport\Grpc;

use Contracts\Errors\ErrorCode;
use Contracts\Gen\Chat\V1\ChatServiceInterface;
use Contracts\Gen\Chat\V1\ConversationMemberSnapshot;
use Contracts\Gen\Chat\V1\GetConversationMemberSnapshotRequest;
use Contracts\Gen\Chat\V1\GetConversationMemberSnapshotResponse;
use Contracts\Gen\Chat\V1\GetConversationRequest;
use Contracts\Gen\Chat\V1\GetConversationResponse;
use Contracts\Gen\Chat\V1\GetOrCreatePrivateConversationRequest;
use Contracts\Gen\Chat\V1\GetOrCreatePrivateConversationResponse;
use Contracts\Gen\Chat\V1\ListConversationsRequest;
use Contracts\Gen\Chat\V1\ListConversationsResponse;
use Contracts\Gen\Chat\V1\CheckCanMessageRequest;
use Contracts\Gen\Chat\V1\CheckCanMessageResponse;
use Contracts\Gen\Chat\V1\MarkReadRequest;
use Contracts\Gen\Chat\V1\MarkReadResponse;
use Contracts\Gen\Common\V1\PaginationResponse;
use Packages\GrpcCommon\ErrorStatusMapper;
use Packages\GrpcCommon\InternalRpcAuthorizer;
use Packages\Observability\Span;
use Packages\Observability\Tracer;
use Packages\Tracing\TraceContext;
use Psr\Log\LoggerInterface;
use Services\ChatService\Application\ChatApplicationService;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\ResponseTrailers;
use Spiral\RoadRunner\GRPC\StatusCode;
use Throwable;

final readonly class RoadRunnerChatService implements ChatServiceInterface
{
    public function __construct(
        private ChatApplicationService $chat,
        private LoggerInterface $logger,
        private ?InternalRpcAuthorizer $authorizer = null,
    ) {
    }

    public function GetOrCreatePrivateConversation(
        ContextInterface $ctx,
        GetOrCreatePrivateConversationRequest $in,
    ): GetOrCreatePrivateConversationResponse {
        return $this->call($ctx, 'GetOrCreatePrivateConversation', function () use ($ctx, $in): GetOrCreatePrivateConversationResponse {
            $conversation = $this->chat->getOrCreatePrivateConversation(
                $this->trustedUserId($ctx),
                [
                    'request_id' => $in->getRequestId(),
                    // Explicitly retained only for audit/debugging; the app
                    // ignores body user_id/persona_id as authorities.
                    'user_id' => $in->getUserId(),
                    'persona_id' => $in->getPersonaId(),
                    'identity_mode' => $in->getIdentityMode(),
                    'alias_display_name' => $in->getAliasDisplayName(),
                    'target' => $in->getTarget(),
                ],
            );

            return new GetOrCreatePrivateConversationResponse(['conversation' => $conversation]);
        });
    }

    public function GetConversation(ContextInterface $ctx, GetConversationRequest $in): GetConversationResponse
    {
        return $this->call($ctx, 'GetConversation', function () use ($ctx, $in): GetConversationResponse {
            $conversation = $this->chat->getConversation(
                $this->trustedUserId($ctx),
                $this->id($in->getConversationId(), 'conversation_id'),
            );

            return new GetConversationResponse(['conversation' => $conversation]);
        });
    }

    public function ListConversations(ContextInterface $ctx, ListConversationsRequest $in): ListConversationsResponse
    {
        return $this->call($ctx, 'ListConversations', function () use ($ctx, $in): ListConversationsResponse {
            $pagination = $in->getPagination();
            $result = $this->chat->listConversations(
                $this->trustedUserId($ctx),
                $pagination?->getPageSize() ?? 20,
                $pagination?->getCursor() ?: null,
            );

            return new ListConversationsResponse([
                'conversations' => $result['conversations'],
                'pagination' => new PaginationResponse([
                    'next_cursor' => $result['next_cursor'],
                    'has_more' => $result['has_more'],
                ]),
            ]);
        });
    }

    public function CheckCanMessage(ContextInterface $ctx, CheckCanMessageRequest $in): CheckCanMessageResponse
    {
        return $this->call($ctx, 'CheckCanMessage', function () use ($ctx, $in): CheckCanMessageResponse {
            $allowed = $this->chat->checkCanMessage(
                $this->trustedUserId($ctx),
                $this->id($in->getRecipientUserId(), 'recipient_user_id'),
            );

            return new CheckCanMessageResponse([
                'allowed' => $allowed,
                'reason' => $allowed ? '' : ErrorCode::CHAT_FORBIDDEN_BY_PRIVACY->message(),
            ]);
        });
    }

    public function MarkRead(ContextInterface $ctx, MarkReadRequest $in): MarkReadResponse
    {
        return $this->call($ctx, 'MarkRead', function () use ($ctx, $in): MarkReadResponse {
            $success = $this->chat->markRead(
                $this->trustedUserId($ctx),
                $this->id($in->getConversationId(), 'conversation_id'),
                $this->uint($in->getSeq(), 'seq'),
            );

            return new MarkReadResponse(['success' => $success]);
        });
    }

    /** Internal MessageService -> ChatService hop. */
    public function GetConversationMemberSnapshot(
        ContextInterface $ctx,
        GetConversationMemberSnapshotRequest $in,
    ): GetConversationMemberSnapshotResponse {
        return $this->call($ctx, 'GetConversationMemberSnapshot', function () use ($ctx, $in): GetConversationMemberSnapshotResponse {
            $result = $this->chat->getConversationMemberSnapshot(
                $this->trustedUserId($ctx),
                $this->id($in->getConversationId(), 'conversation_id'),
            );
            $fields = [
                'allowed' => (bool)$result['allowed'],
                'denial_reason' => (string)$result['denial_reason'],
            ];
            if (($result['member'] ?? null) !== null) {
                $member = $result['member'];
                $fields['member'] = new ConversationMemberSnapshot([
                    'user_id' => (string)$member['user_id'],
                    'persona_id' => (string)$member['persona_id'],
                    'identity_mode' => (int)$member['identity_mode'],
                    'display_name' => (string)($member['display_name_snapshot'] ?? ''),
                    'avatar_key' => (string)($member['avatar_key_snapshot'] ?? ''),
                    'anonymous_ref' => (string)($member['anonymous_ref'] ?? ''),
                    'scope_type' => (string)($member['scope_type'] ?? ''),
                    'scope_id' => (string)($member['scope_id'] ?? ''),
                    'profile_accessible' => (int)$member['identity_mode'] === 1,
                ]);
            }

            return new GetConversationMemberSnapshotResponse($fields);
        });
    }

    /** @param callable():mixed $operation @return mixed */
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
            'server.address' => getenv('SERVICE_NAME') ?: 'chat-service',
        ]);
        try {
            return $operation();
        } catch (GRPCException $e) {
            $scope->fail($e);
            throw $e;
        } catch (Throwable $e) {
            $scope->fail($e);
            $this->logger->error('Error in ' . $method . ': ' . $e->getMessage(), [
                'exception' => $e::class,
                'error_code' => $e->getCode(),
            ]);
            $error = $e->getCode() > 0 ? ErrorCode::tryFrom((int)$e->getCode()) : null;
            if ($error !== null && $error !== ErrorCode::OK) {
                $trailers = $ctx->getValue(ResponseTrailers::class);
                if ($trailers instanceof ResponseTrailers) {
                    $trailers->set('x-app-error-code', (string)$error->value);
                }
            }
            throw GRPCException::create(
                $e->getMessage(),
                ErrorStatusMapper::forExceptionCode((int)$e->getCode()),
                $e,
            );
        } finally {
            $scope->close();
            TraceContext::reset();
        }
    }

    private function trustedUserId(ContextInterface $ctx): string
    {
        return $this->id($this->metadataValue($ctx, 'x-user-id') ?? '', 'x-user-id');
    }

    private function id(int|string $id, string $field): string
    {
        $id = trim((string)$id);
        if ($id === '' || $id === '0' || preg_match('/\A[1-9][0-9]*\z/', $id) !== 1) {
            throw GRPCException::create($field . ' is required', StatusCode::INVALID_ARGUMENT);
        }

        return $id;
    }

    private function uint(int|string $id, string $field): string
    {
        $id = trim((string)$id);
        if (preg_match('/\A[0-9]+\z/', $id) !== 1) {
            throw GRPCException::create($field . ' is invalid', StatusCode::INVALID_ARGUMENT);
        }

        return $id;
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
}
