<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Grpc;

use Contracts\Errors\ErrorCode;
use Contracts\Gen\Common\V1\PaginationRequest;
use Contracts\Gen\Common\V1\PublicAuthor;
use Contracts\Gen\Content\V1\BatchGetPostsRequest;
use Contracts\Gen\Content\V1\BatchGetPostsResponse;
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
use Contracts\Gen\Content\V1\MediaItem;
use Contracts\Gen\Content\V1\PublicComment;
use Contracts\Gen\Content\V1\PublicPost;
use Exception;
use Google\Protobuf\Internal\Message;
use Packages\GrpcCommon\GrpcCallException;
use Packages\GrpcCommon\UnaryGrpcClient;
use RuntimeException;
use const Grpc\STATUS_DEADLINE_EXCEEDED;
use const Grpc\STATUS_INVALID_ARGUMENT;
use const Grpc\STATUS_NOT_FOUND;
use const Grpc\STATUS_PERMISSION_DENIED;
use const Grpc\STATUS_RESOURCE_EXHAUSTED;

/** HTTP gateway client for the public ContentService surface. */
final class ContentServiceClient
{
    private ?UnaryGrpcClient $client;

    public function __construct(
        private readonly ?string $target = null,
        private readonly int $timeoutMs = 500,
        private readonly ?string $serviceToken = null,
        ?UnaryGrpcClient $client = null,
    ) {
        $this->client = $client;
    }

    /**
     * @param array<string,mixed> $fields
     * @param list<array<string,mixed>> $media
     */
    public function createPost(array $fields, array $media): array
    {
        $items = [];
        foreach ($media as $index => $item) {
            $items[] = new MediaItem([
                'type' => (int)($item['type'] ?? 1),
                'object_key' => (string)($item['object_key'] ?? ''),
                'mime_type' => (string)($item['mime_type'] ?? 'application/octet-stream'),
                'width' => (int)($item['width'] ?? 0),
                'height' => (int)($item['height'] ?? 0),
                'duration_ms' => (int)($item['duration_ms'] ?? 0),
                'sort_order' => (int)($item['sort_order'] ?? $index),
            ]);
        }
        $response = $this->call('/content.v1.ContentService/CreatePost', new CreatePostRequest([
            'request_id' => (string)($fields['request_id'] ?? ''),
            'persona_id' => (string)($fields['persona_id'] ?? '0'),
            'type' => (int)($fields['type'] ?? 1),
            'content' => (string)($fields['content'] ?? ''),
            'visibility' => (int)($fields['visibility'] ?? 1),
            'emotion' => (string)($fields['emotion'] ?? ''),
            'media' => $items,
            'identity_mode' => (int)($fields['identity_mode'] ?? 0),
            'alias_display_name' => (string)($fields['alias_display_name'] ?? ''),
        ]), CreatePostResponse::class);

        return ['post' => $this->post($response->getPost())];
    }

    public function getPost(string $postId): array
    {
        $response = $this->call('/content.v1.ContentService/GetPost', new GetPostRequest([
            'post_id' => $postId,
        ]), GetPostResponse::class);

        return ['post' => $this->post($response->getPost())];
    }

    /** @param list<string> $postIds */
    public function batchGetPosts(array $postIds): array
    {
        $response = $this->call('/content.v1.ContentService/BatchGetPosts', new BatchGetPostsRequest([
            'post_ids' => $postIds,
        ]), BatchGetPostsResponse::class);

        return ['posts' => array_map(fn (PublicPost $post): array => $this->post($post), iterator_to_array($response->getPosts()))];
    }

    /** @param array<string,mixed> $fields */
    public function createComment(array $fields): array
    {
        $response = $this->call('/content.v1.ContentService/CreateComment', new CreateCommentRequest([
            'request_id' => (string)($fields['request_id'] ?? ''),
            'post_id' => (string)($fields['post_id'] ?? '0'),
            'persona_id' => (string)($fields['persona_id'] ?? '0'),
            'parent_id' => (string)($fields['parent_id'] ?? '0'),
            'reply_to_comment_id' => (string)($fields['reply_to_comment_id'] ?? '0'),
            'content' => (string)($fields['content'] ?? ''),
            'identity_mode' => (int)($fields['identity_mode'] ?? 0),
            'alias_display_name' => (string)($fields['alias_display_name'] ?? ''),
        ]), CreateCommentResponse::class);

        return ['comment' => $this->comment($response->getComment())];
    }

    public function listComments(string $postId, string $parentId, int $pageSize, string $cursor): array
    {
        $response = $this->call('/content.v1.ContentService/ListComments', new ListCommentsRequest([
            'post_id' => $postId,
            'parent_id' => $parentId !== '' ? $parentId : '0',
            'pagination' => new PaginationRequest([
                'page_size' => $pageSize,
                'cursor' => $cursor,
            ]),
        ]), ListCommentsResponse::class);

        $pagination = $response->getPagination();
        return [
            'comments' => array_map(fn (PublicComment $comment): array => $this->comment($comment), iterator_to_array($response->getComments())),
            'pagination' => [
                'next_cursor' => $pagination?->getNextCursor() ?? '',
                'has_more' => $pagination?->getHasMore() ?? false,
                'total' => (string)($pagination?->getTotal() ?? 0),
            ],
        ];
    }

    public function deletePost(string $postId): array
    {
        $response = $this->call('/content.v1.ContentService/DeletePost', new DeletePostRequest([
            'post_id' => $postId,
        ]), DeletePostResponse::class);

        return ['success' => $response->getSuccess()];
    }

    public function deleteComment(string $commentId): array
    {
        $response = $this->call('/content.v1.ContentService/DeleteComment', new DeleteCommentRequest([
            'comment_id' => $commentId,
        ]), DeleteCommentResponse::class);

        return ['success' => $response->getSuccess()];
    }

    /**
     * @template T of Message
     * @param class-string<T> $responseClass
     * @return T
     * @throws Exception
     */
    private function call(string $method, Message $request, string $responseClass): Message
    {
        $client = $this->client ??= new UnaryGrpcClient(
            $this->target ?: config('gateway.grpc.content.target', 'content-service:50053'),
            $this->serviceToken,
        );

        try {
            return $client->call($method, $request, $responseClass, $this->timeoutMs);
        } catch (GrpcCallException $e) {
            if ($e->appErrorCode !== null) {
                throw new RuntimeException($e->getMessage(), $e->appErrorCode, $e);
            }
            $code = match ($e->grpcStatus) {
                STATUS_DEADLINE_EXCEEDED => ErrorCode::RPC_TIMEOUT->value,
                STATUS_NOT_FOUND => ErrorCode::CONTENT_POST_NOT_FOUND->value,
                STATUS_PERMISSION_DENIED => ErrorCode::PERSONA_FORBIDDEN->value,
                STATUS_INVALID_ARGUMENT => ErrorCode::INVALID_ARGUMENT->value,
                STATUS_RESOURCE_EXHAUSTED => ErrorCode::RATE_LIMIT_EXCEEDED->value,
                default => ErrorCode::SERVICE_UNAVAILABLE->value,
            };
            $message = in_array($code, [ErrorCode::SERVICE_UNAVAILABLE->value, ErrorCode::RPC_TIMEOUT->value], true)
                ? ErrorCode::from($code)->message()
                : $e->getMessage();
            throw new RuntimeException($message, $code, $e);
        }
    }

    /** @return array<string,mixed> */
    private function post(?PublicPost $post): array
    {
        if ($post === null) {
            return [];
        }
        return [
            'post_id' => (string)$post->getPostId(),
            'type' => $post->getType(),
            'content' => $post->getContent(),
            'visibility' => $post->getVisibility(),
            'emotion' => $post->getEmotion(),
            'status' => $post->getStatus(),
            'moderation_status' => $post->getModerationStatus(),
            'like_count' => (string)$post->getLikeCount(),
            'comment_count' => (string)$post->getCommentCount(),
            'view_count' => (string)$post->getViewCount(),
            'media' => array_map(fn (MediaItem $media): array => $this->media($media), iterator_to_array($post->getMedia())),
            'published_at' => $post->getPublishedAt(),
            'created_at' => $post->getCreatedAt(),
            'author' => $this->author($post->getAuthor()),
        ];
    }

    /** @return array<string,mixed> */
    private function comment(?PublicComment $comment): array
    {
        if ($comment === null) {
            return [];
        }
        return [
            'comment_id' => (string)$comment->getCommentId(),
            'post_id' => (string)$comment->getPostId(),
            'parent_id' => (string)$comment->getParentId(),
            'reply_to_comment_id' => (string)$comment->getReplyToCommentId(),
            'content' => $comment->getContent(),
            'status' => $comment->getStatus(),
            'like_count' => (string)$comment->getLikeCount(),
            'created_at' => $comment->getCreatedAt(),
            'author' => $this->author($comment->getAuthor()),
        ];
    }

    /** @return array<string,mixed> */
    private function media(MediaItem $media): array
    {
        return [
            'type' => $media->getType(),
            'object_key' => $media->getObjectKey(),
            'mime_type' => $media->getMimeType(),
            'width' => $media->getWidth(),
            'height' => $media->getHeight(),
            'duration_ms' => $media->getDurationMs(),
            'sort_order' => $media->getSortOrder(),
        ];
    }

    /** @return array<string,mixed>|null */
    private function author(?PublicAuthor $author): ?array
    {
        if ($author === null) {
            return null;
        }
        return [
            'identity_mode' => $author->getIdentityMode(),
            'persona_id' => (string)$author->getPersonaId(),
            'display_name' => $author->getDisplayName(),
            'avatar_key' => $author->getAvatarKey(),
            'anonymous_ref' => $author->getAnonymousRef(),
            'profile_accessible' => $author->getProfileAccessible(),
        ];
    }
}
