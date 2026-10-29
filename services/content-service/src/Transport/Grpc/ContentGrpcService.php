<?php

declare(strict_types=1);

namespace Services\ContentService\Transport\Grpc;

use Contracts\Errors\ErrorCode;
use Contracts\Gen\Content\V1\BatchGetPostsRequest;
use Contracts\Gen\Content\V1\BatchGetPostsResponse;
use Contracts\Gen\Content\V1\CanAccessResourceRequest;
use Contracts\Gen\Content\V1\CanAccessResourceResponse;
use Contracts\Gen\Content\V1\ContentServiceInterface;
use Contracts\Gen\Content\V1\CreateCommentRequest;
use Contracts\Gen\Content\V1\CreateCommentResponse;
use Contracts\Gen\Content\V1\CreatePostRequest;
use Contracts\Gen\Content\V1\CreatePostResponse;
use Contracts\Gen\Content\V1\DeleteCommentRequest;
use Contracts\Gen\Content\V1\DeleteCommentResponse;
use Contracts\Gen\Content\V1\DeletePostRequest;
use Contracts\Gen\Content\V1\DeletePostResponse;
use Contracts\Gen\Content\V1\GetPostRequest;
use Contracts\Gen\Content\V1\GetPostResponse;
use Contracts\Gen\Content\V1\ListCommentsRequest;
use Contracts\Gen\Content\V1\ListCommentsResponse;
use Contracts\Gen\Content\V1\ListPostsRequest;
use Contracts\Gen\Content\V1\ListPostsResponse;
use Contracts\Gen\Content\V1\ResolveAnonymousTargetRequest;
use Contracts\Gen\Content\V1\ResolveAnonymousTargetResponse;
use Contracts\Gen\Content\V1\UpdatePostStatusRequest;
use Contracts\Gen\Content\V1\UpdatePostStatusResponse;
use Packages\GrpcCommon\BusinessActor;
use Packages\GrpcCommon\ErrorStatusMapper;
use Packages\GrpcCommon\InternalRpcAuthorizer;
use Packages\Logging\JsonLogger;
use Packages\Observability\Span;
use Packages\Observability\Tracer;
use Packages\Tracing\TraceContext;
use Services\ContentService\Application\ContentApplicationService;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\ResponseTrailers;
use Spiral\RoadRunner\GRPC\StatusCode;
use Throwable;

final readonly class ContentGrpcService implements ContentServiceInterface
{
    public function __construct(
        private ContentApplicationService $content,
        private JsonLogger $logger,
        private InternalRpcAuthorizer $authorizer,
    ) {
    }

    public function CreatePost(ContextInterface $ctx, CreatePostRequest $in): CreatePostResponse
    {
        return $this->call($ctx, 'CreatePost', function () use ($ctx, $in): CreatePostResponse {
            $actor = BusinessActor::fromContext($ctx);
            $media = [];
            foreach ($in->getMedia() as $item) {
                $media[] = [
                    'type' => $item->getType(),
                    'object_key' => $item->getObjectKey(),
                    'mime_type' => $item->getMimeType(),
                    'width' => $item->getWidth(),
                    'height' => $item->getHeight(),
                    'duration_ms' => $item->getDurationMs(),
                    'sort_order' => $item->getSortOrder(),
                ];
            }
            return new CreatePostResponse([
                'post' => $this->content->createPost(
                    actor: $actor,
                    identityMode: $in->getIdentityMode(),
                    requestedPersonaId: $this->id($in->getPersonaId(), 'persona_id', true),
                    type: $in->getType(),
                    content: $in->getContent(),
                    visibility: $in->getVisibility(),
                    emotion: $in->getEmotion(),
                    media: $media,
                    requestId: trim($in->getRequestId()),
                    aliasDisplayName: $in->getAliasDisplayName(),
                ),
            ]);
        });
    }

    public function GetPost(ContextInterface $ctx, GetPostRequest $in): GetPostResponse
    {
        return $this->call($ctx, 'GetPost', function () use ($ctx, $in): GetPostResponse {
            return new GetPostResponse([
                'post' => $this->content->getPost(
                    $this->optionalActor($ctx),
                    $this->id($in->getPostId(), 'post_id'),
                ),
            ]);
        });
    }

    public function BatchGetPosts(ContextInterface $ctx, BatchGetPostsRequest $in): BatchGetPostsResponse
    {
        return $this->call($ctx, 'BatchGetPosts', function () use ($ctx, $in): BatchGetPostsResponse {
            $ids = [];
            foreach ($in->getPostIds() as $postId) {
                $ids[] = $this->id($postId, 'post_ids');
            }
            return new BatchGetPostsResponse(['posts' => $this->content->batchGetPosts($this->optionalActor($ctx), $ids)]);
        });
    }

    public function ListPosts(ContextInterface $ctx, ListPostsRequest $in): ListPostsResponse
    {
        return $this->call($ctx, 'ListPosts', function () use ($ctx, $in): ListPostsResponse {
            $result = $this->content->listPosts(
                $this->optionalActor($ctx),
                $in->getScope(),
                $this->id($in->getPersonaId(), 'persona_id', true),
                $in->getIdentityFilter(),
                $in->getPagination(),
            );

            return new ListPostsResponse($result);
        });
    }

    public function DeletePost(ContextInterface $ctx, DeletePostRequest $in): DeletePostResponse
    {
        return $this->call($ctx, 'DeletePost', function () use ($ctx, $in): DeletePostResponse {
            $this->content->deletePost(BusinessActor::fromContext($ctx), $this->id($in->getPostId(), 'post_id'), '');
            return new DeletePostResponse(['success' => true]);
        });
    }

    public function CreateComment(ContextInterface $ctx, CreateCommentRequest $in): CreateCommentResponse
    {
        return $this->call($ctx, 'CreateComment', function () use ($ctx, $in): CreateCommentResponse {
            return new CreateCommentResponse([
                'comment' => $this->content->createComment(
                    actor: BusinessActor::fromContext($ctx),
                    identityMode: $in->getIdentityMode(),
                    requestedPersonaId: $this->id($in->getPersonaId(), 'persona_id', true),
                    postId: $this->id($in->getPostId(), 'post_id'),
                    parentId: $this->id($in->getParentId(), 'parent_id', true),
                    replyToCommentId: $this->id($in->getReplyToCommentId(), 'reply_to_comment_id', true),
                    content: $in->getContent(),
                    requestId: trim($in->getRequestId()),
                    aliasDisplayName: $in->getAliasDisplayName(),
                ),
            ]);
        });
    }

    public function ListComments(ContextInterface $ctx, ListCommentsRequest $in): ListCommentsResponse
    {
        return $this->call($ctx, 'ListComments', function () use ($ctx, $in): ListCommentsResponse {
            $result = $this->content->listComments(
                $this->optionalActor($ctx),
                $this->id($in->getPostId(), 'post_id'),
                $this->id($in->getParentId(), 'parent_id', true),
                $in->getPagination(),
            );
            return new ListCommentsResponse($result);
        });
    }

    public function DeleteComment(ContextInterface $ctx, DeleteCommentRequest $in): DeleteCommentResponse
    {
        return $this->call($ctx, 'DeleteComment', function () use ($ctx, $in): DeleteCommentResponse {
            $this->content->deleteComment(BusinessActor::fromContext($ctx), $this->id($in->getCommentId(), 'comment_id'), '');
            return new DeleteCommentResponse(['success' => true]);
        });
    }

    public function UpdatePostStatus(ContextInterface $ctx, UpdatePostStatusRequest $in): UpdatePostStatusResponse
    {
        return $this->call($ctx, 'UpdatePostStatus', function () use ($in): UpdatePostStatusResponse {
            $this->content->updatePostStatus($this->id($in->getPostId(), 'post_id'), $in->getStatus(), $in->getModerationStatus(), '');
            return new UpdatePostStatusResponse(['success' => true]);
        });
    }

    public function ResolveAnonymousTarget(ContextInterface $ctx, ResolveAnonymousTargetRequest $in): ResolveAnonymousTargetResponse
    {
        return $this->call($ctx, 'ResolveAnonymousTarget', function () use ($ctx, $in): ResolveAnonymousTargetResponse {
            $target = $this->content->resolveAnonymousTarget(
                BusinessActor::fromContext($ctx),
                $in->getScopeType(),
                $in->getScopeId(),
                $in->getAnonymousRef(),
            );
            return new ResolveAnonymousTargetResponse($target);
        });
    }

    public function CanAccessResource(ContextInterface $ctx, CanAccessResourceRequest $in): CanAccessResourceResponse
    {
        return $this->call($ctx, 'CanAccessResource', function () use ($ctx, $in): CanAccessResourceResponse {
            $result = $this->content->canAccessResource(
                BusinessActor::fromContext($ctx),
                trim($in->getResourceType()),
                $this->id($in->getResourceId(), 'resource_id'),
                trim($in->getAction()),
                checkBlocks: $this->metadataValue($ctx, 'x-service-name') !== 'social-service',
            );
            return new CanAccessResourceResponse($result);
        });
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function call(ContextInterface $ctx, string $method, callable $operation): mixed
    {
        $this->authorizer->authorize($ctx, $method);
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
            'server.address' => getenv('SERVICE_NAME') ?: 'content-service',
        ]);
        try {
            return $operation();
        } catch (GRPCException $e) {
            $scope->fail($e);
            throw $e;
        } catch (Throwable $e) {
            $scope->fail($e);
            $this->logger->error("Error in $method: " . $e->getMessage(), [
                'exception' => $e::class,
                'error_code' => $e->getCode(),
            ]);
            $error = ErrorCode::tryFrom((int)$e->getCode());
            if ($error !== null && $error !== ErrorCode::OK) {
                $trailers = $ctx->getValue(ResponseTrailers::class);
                if ($trailers instanceof ResponseTrailers) {
                    $trailers->set('x-app-error-code', (string)$error->value);
                }
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

    private function optionalActor(ContextInterface $ctx): ?BusinessActor
    {
        $values = $ctx->getValues();
        $metadata = is_array($values['metadata'] ?? null) ? $values['metadata'] : [];
        $hasActorMetadata = array_key_exists('x-user-id', $values)
            || array_key_exists('x-persona-id', $values)
            || array_key_exists('x-user-id', $metadata)
            || array_key_exists('x-persona-id', $metadata);
        if (!$hasActorMetadata) {
            return null;
        }

        return BusinessActor::fromContext($ctx);
    }

    private function id(int|string $id, string $field, bool $optional = false): string
    {
        $value = (string)$id;
        if ($optional && ($value === '' || $value === '0')) {
            return '';
        }
        if ($value === '' || $value === '0' || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            throw GRPCException::create($field . ' is required', StatusCode::INVALID_ARGUMENT);
        }

        return $value;
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

    private function publicErrorMessage(Throwable $exception): string
    {
        return match ((int)$exception->getCode()) {
            ErrorCode::INVALID_ARGUMENT->value,
            ErrorCode::CONTENT_POST_NOT_FOUND->value,
            ErrorCode::CONTENT_POST_DELETED->value,
            ErrorCode::CONTENT_COMMENT_NOT_FOUND->value,
            ErrorCode::CONTENT_UNDER_REVIEW->value,
            ErrorCode::PERSONA_FORBIDDEN->value,
            ErrorCode::USER_BANNED->value,
            ErrorCode::IDEMPOTENCY_CONFLICT->value => $exception->getMessage(),
            default => ErrorCode::INTERNAL_SERVER_ERROR->message(),
        };
    }
}
