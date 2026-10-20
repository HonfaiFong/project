<?php

declare(strict_types=1);

namespace Services\MessageService\Transport\Grpc;

use Contracts\Errors\ErrorCode;
use Contracts\Gen\Common\V1\PaginationResponse;
use Contracts\Gen\Message\V1\GetMessagesRequest;
use Contracts\Gen\Message\V1\GetMessagesResponse;
use Contracts\Gen\Message\V1\MessageServiceInterface;
use Contracts\Gen\Message\V1\PublicSendMessageRequest;
use Contracts\Gen\Message\V1\SendMessageResponse;
use Contracts\Gen\Message\V1\SyncMessagesRequest;
use Contracts\Gen\Message\V1\SyncMessagesResponse;
use Packages\GrpcCommon\ErrorStatusMapper;
use Packages\GrpcCommon\InternalRpcAuthorizer;
use Packages\GrpcCommon\OperationBudget;
use Packages\Observability\Span;
use Packages\Observability\Tracer;
use Packages\Tracing\TraceContext;
use Psr\Log\LoggerInterface;
use Services\MessageService\Application\MessageApplicationService;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\ResponseTrailers;
use Spiral\RoadRunner\GRPC\StatusCode;
use Throwable;

final readonly class RoadRunnerMessageService implements MessageServiceInterface
{
    public function __construct(
        private MessageApplicationService $messages,
        private LoggerInterface $logger,
        private ?InternalRpcAuthorizer $authorizer = null,
    ) {
    }

    public function SendMessage(ContextInterface $ctx, PublicSendMessageRequest $in): SendMessageResponse
    {
        return $this->call($ctx, 'SendMessage', function () use ($ctx, $in): SendMessageResponse {
            $budget = $this->operationBudget();
            $message = $this->messages->sendMessage(
                $this->trustedUserId($ctx),
                [
                    'client_message_id' => $in->getClientMessageId(),
                    'conversation_id' => $in->getConversationId(),
                    'type' => $in->getType(),
                    'content' => $in->getContent(),
                ],
                $budget,
            );

            return new SendMessageResponse(['message' => $message]);
        });
    }

    public function SyncMessages(ContextInterface $ctx, SyncMessagesRequest $in): SyncMessagesResponse
    {
        return $this->call($ctx, 'SyncMessages', function () use ($ctx, $in): SyncMessagesResponse {
            $budget = $this->operationBudget();
            $result = $this->messages->syncMessages(
                $this->trustedUserId($ctx),
                $this->id($in->getConversationId(), 'conversation_id'),
                $this->uint($in->getAfterSeq(), 'after_seq'),
                $in->getLimit(),
                $budget,
            );

            return new SyncMessagesResponse([
                'messages' => $result['messages'],
                'has_more' => $result['has_more'],
                'latest_seq' => $result['latest_seq'],
            ]);
        });
    }

    public function GetMessages(ContextInterface $ctx, GetMessagesRequest $in): GetMessagesResponse
    {
        return $this->call($ctx, 'GetMessages', function () use ($ctx, $in): GetMessagesResponse {
            $budget = $this->operationBudget();
            $pagination = $in->getPagination();
            $result = $this->messages->getMessages(
                $this->trustedUserId($ctx),
                $this->id($in->getConversationId(), 'conversation_id'),
                $pagination?->getPageSize() ?? 50,
                $pagination?->getCursor() ?: null,
                $budget,
            );

            return new GetMessagesResponse([
                'messages' => $result['messages'],
                'pagination' => new PaginationResponse([
                    'next_cursor' => $result['next_cursor'],
                    'has_more' => $result['has_more'],
                ]),
            ]);
        });
    }

    /**
     * @param callable():mixed $operation
     * @return mixed
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
            'server.address' => getenv('SERVICE_NAME') ?: 'message-service',
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

    /**
     * RoadRunner's GRPC Context exposes metadata but not the transport deadline.
     * Use a bounded in-process deadline and keep it below the gateway's default
     * message RPC timeout; callers can lower it to match their configured route.
     */
    private function operationBudget(): OperationBudget
    {
        return OperationBudget::fromEnvironment('MESSAGE_OPERATION_BUDGET_MS', 600);
    }
}
