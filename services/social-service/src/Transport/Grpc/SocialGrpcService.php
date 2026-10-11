<?php

declare(strict_types=1);

namespace Services\SocialService\Transport\Grpc;

use Contracts\Errors\ErrorCode;
use Contracts\Gen\Social\V1\BatchGetInteractionStateRequest;
use Contracts\Gen\Social\V1\BatchGetInteractionStateResponse;
use Contracts\Gen\Social\V1\BlockRequest;
use Contracts\Gen\Social\V1\BlockResponse;
use Contracts\Gen\Social\V1\CheckBidirectionalBlockRequest;
use Contracts\Gen\Social\V1\CheckBidirectionalBlockResponse;
use Contracts\Gen\Social\V1\FollowRequest;
use Contracts\Gen\Social\V1\FollowResponse;
use Contracts\Gen\Social\V1\InteractionState;
use Contracts\Gen\Social\V1\LikeRequest;
use Contracts\Gen\Social\V1\LikeResponse;
use Contracts\Gen\Social\V1\SocialServiceInterface;
use Contracts\Gen\Social\V1\UnblockRequest;
use Contracts\Gen\Social\V1\UnblockResponse;
use Contracts\Gen\Social\V1\UnlikeRequest;
use Contracts\Gen\Social\V1\UnlikeResponse;
use Contracts\Gen\Social\V1\UnfollowRequest;
use Contracts\Gen\Social\V1\UnfollowResponse;
use Packages\GrpcCommon\ErrorStatusMapper;
use Packages\GrpcCommon\InternalRpcAuthorizer;
use Packages\Observability\Span;
use Packages\Observability\Tracer;
use Packages\Tracing\TraceContext;
use Psr\Log\LoggerInterface;
use Services\SocialService\Application\Service\SocialApplicationService;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\ResponseTrailers;
use Spiral\RoadRunner\GRPC\StatusCode;
use Throwable;

final readonly class SocialGrpcService implements SocialServiceInterface
{
    public function __construct(
        private SocialApplicationService $social,
        private LoggerInterface $logger,
        private ?InternalRpcAuthorizer $authorizer = null,
    ) {
    }

    public function Like(ContextInterface $ctx, LikeRequest $in): LikeResponse
    {
        return $this->call($ctx, 'Like', function () use ($ctx, $in): LikeResponse {
            $this->assertMainRequest($ctx, $in);
            return (new LikeResponse())->setSuccess($this->social->like(
                $this->actor($ctx),
                $this->requireId($in->getPersonaId(), 'persona_id'),
                (int)$in->getTargetType(),
                $this->requireId($in->getTargetId(), 'target_id'),
            ));
        });
    }

    public function Unlike(ContextInterface $ctx, UnlikeRequest $in): UnlikeResponse
    {
        return $this->call($ctx, 'Unlike', function () use ($ctx, $in): UnlikeResponse {
            $this->assertMainRequest($ctx, $in);
            return (new UnlikeResponse())->setSuccess($this->social->unlike(
                $this->actor($ctx),
                $this->requireId($in->getPersonaId(), 'persona_id'),
                (int)$in->getTargetType(),
                $this->requireId($in->getTargetId(), 'target_id'),
            ));
        });
    }

    public function Follow(ContextInterface $ctx, FollowRequest $in): FollowResponse
    {
        return $this->call($ctx, 'Follow', function () use ($ctx, $in): FollowResponse {
            $this->assertMainRequest($ctx, $in);
            return (new FollowResponse())->setSuccess($this->social->follow(
                $this->actor($ctx),
                $this->requireId($in->getFollowerPersonaId(), 'follower_persona_id'),
                $this->requireId($in->getFolloweePersonaId(), 'followee_persona_id'),
            ));
        });
    }

    public function Unfollow(ContextInterface $ctx, UnfollowRequest $in): UnfollowResponse
    {
        return $this->call($ctx, 'Unfollow', function () use ($ctx, $in): UnfollowResponse {
            $this->assertMainRequest($ctx, $in);
            return (new UnfollowResponse())->setSuccess($this->social->unfollow(
                $this->actor($ctx),
                $this->requireId($in->getFollowerPersonaId(), 'follower_persona_id'),
                $this->requireId($in->getFolloweePersonaId(), 'followee_persona_id'),
            ));
        });
    }

    public function Block(ContextInterface $ctx, BlockRequest $in): BlockResponse
    {
        return $this->call($ctx, 'Block', function () use ($ctx, $in): BlockResponse {
            $this->assertMainRequest($ctx, $in);
            $actor = $this->actor($ctx);
            $bodyActor = (string)$in->getBlockerUserId();
            if ($bodyActor !== '' && $bodyActor !== '0' && $bodyActor !== $actor) {
                throw GRPCException::create('blocker_user_id does not belong to the authenticated account', StatusCode::PERMISSION_DENIED);
            }

            return (new BlockResponse())->setSuccess($this->social->block(
                $actor,
                $this->requireId($in->getBlockedUserId(), 'blocked_user_id'),
            ));
        });
    }

    public function Unblock(ContextInterface $ctx, UnblockRequest $in): UnblockResponse
    {
        return $this->call($ctx, 'Unblock', function () use ($ctx, $in): UnblockResponse {
            $this->assertMainRequest($ctx, $in);
            $actor = $this->actor($ctx);
            $bodyActor = (string)$in->getBlockerUserId();
            if ($bodyActor !== '' && $bodyActor !== '0' && $bodyActor !== $actor) {
                throw GRPCException::create('blocker_user_id does not belong to the authenticated account', StatusCode::PERMISSION_DENIED);
            }

            return (new UnblockResponse())->setSuccess($this->social->unblock(
                $actor,
                $this->requireId($in->getBlockedUserId(), 'blocked_user_id'),
            ));
        });
    }

    public function BatchGetInteractionState(
        ContextInterface $ctx,
        BatchGetInteractionStateRequest $in,
    ): BatchGetInteractionStateResponse {
        return $this->call($ctx, 'BatchGetInteractionState', function () use ($ctx, $in): BatchGetInteractionStateResponse {
            $this->assertMainRequest($ctx, $in);
            $targetIds = [];
            foreach ($in->getTargetIds() as $targetId) {
                $targetIds[] = $this->requireId($targetId, 'target_ids');
            }
            $states = $this->social->batchInteractionStates(
                $this->actor($ctx),
                $this->requireId($in->getPersonaId(), 'persona_id'),
                (int)$in->getTargetType(),
                $targetIds,
            );
            $response = new BatchGetInteractionStateResponse();
            foreach ($states as $targetId => $state) {
                $response->getStates()->offsetSet(null, (new InteractionState())
                    ->setTargetId($targetId)
                    ->setIsLiked($state['liked'])
                    ->setIsFollowed($state['followed']));
            }

            return $response;
        });
    }

    public function CheckBidirectionalBlock(
        ContextInterface $ctx,
        CheckBidirectionalBlockRequest $in,
    ): CheckBidirectionalBlockResponse {
        return $this->call($ctx, 'CheckBidirectionalBlock', function () use ($in): CheckBidirectionalBlockResponse {
            return (new CheckBidirectionalBlockResponse())->setBlocked($this->social->checkBidirectionalBlock(
                $this->requireId($in->getUserIdA(), 'user_id_a'),
                $this->requireId($in->getUserIdB(), 'user_id_b'),
            ));
        });
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function call(ContextInterface $ctx, string $method, callable $operation): mixed
    {
        $this->authorizer?->authorize($ctx, $method);
        TraceContext::setCurrent(TraceContext::create(
            requestId: $this->metadataValue($ctx, 'x-request-id'),
            traceId: $this->metadataValue($ctx, 'x-trace-id'),
            userId: $this->metadataValue($ctx, 'x-user-id'),
            personaId: $this->metadataValue($ctx, 'x-persona-id'),
            deviceId: $this->metadataValue($ctx, 'x-device-id'),
            appVersion: $this->metadataValue($ctx, 'x-app-version'),
            traceparent: $this->metadataValue($ctx, 'traceparent'),
        ));
        $scope = Tracer::enter('grpc.server', Span::KIND_SERVER, [
            'rpc.system' => 'grpc',
            'rpc.method' => $method,
            'server.address' => getenv('SERVICE_NAME') ?: 'social-service',
        ]);
        $this->logger->info('Handling Social gRPC call: ' . $method, ['method' => $method]);

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
                $this->attachAppErrorTrailer($ctx, $error->value);
            }

            throw GRPCException::create(
                $error?->message() ?? ErrorCode::INTERNAL_SERVER_ERROR->message(),
                ErrorStatusMapper::forExceptionCode((int)$e->getCode()),
                $e,
            );
        } finally {
            $scope->close();
            TraceContext::reset();
        }
    }

    private function assertMainRequest(ContextInterface $ctx, object $request): void
    {
        $bodyMode = method_exists($request, 'getIdentityMode') ? (int)$request->getIdentityMode() : 0;
        if ($bodyMode === 2) {
            throw GRPCException::create(ErrorCode::PERSONA_FORBIDDEN->message(), StatusCode::PERMISSION_DENIED);
        }
        if ($bodyMode !== 0 && $bodyMode !== 1) {
            throw GRPCException::create('identity_mode is invalid', StatusCode::INVALID_ARGUMENT);
        }

        $metadataMode = strtolower(trim((string)($this->metadataValue($ctx, 'x-identity-mode') ?? '')));
        if ($metadataMode === 'alias' || $metadataMode === '2') {
            throw GRPCException::create(ErrorCode::PERSONA_FORBIDDEN->message(), StatusCode::PERMISSION_DENIED);
        }
        if ($metadataMode !== '' && $metadataMode !== 'main' && $metadataMode !== '1') {
            throw GRPCException::create('identity mode is invalid', StatusCode::INVALID_ARGUMENT);
        }
    }

    private function actor(ContextInterface $ctx): string
    {
        return $this->requireId($this->metadataValue($ctx, 'x-user-id') ?? '', 'authenticated user');
    }

    private function requireId(int|string $id, string $field): string
    {
        $value = (string)$id;
        if ($value === '' || $value === '0' || strspn($value, '0123456789') !== strlen($value)) {
            throw GRPCException::create($field . ' is required', StatusCode::INVALID_ARGUMENT);
        }

        return $value;
    }

    private function attachAppErrorTrailer(ContextInterface $ctx, int $code): void
    {
        $trailers = $ctx->getValue(ResponseTrailers::class);
        if ($trailers instanceof ResponseTrailers) {
            $trailers->set('x-app-error-code', (string)$code);
        }
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
