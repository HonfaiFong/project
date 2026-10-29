<?php

declare(strict_types=1);

namespace Tests\Unit;

use Apps\ApiGateway\Controller\ContentController;
use Apps\ApiGateway\Grpc\ContentServiceClient;
use Apps\ApiGateway\Grpc\UserServiceClient;
use Apps\ApiGateway\Http\GatewayRequest;
use Contracts\Errors\ErrorCode;
use Contracts\Gen\Content\V1\ListPostsRequest;
use Contracts\Gen\Content\V1\ListPostsResponse;
use Contracts\Gen\User\V1\GetPersonaResponse;
use Contracts\Gen\User\V1\PersonaProfile;
use Packages\GrpcCommon\UnaryGrpcClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** The feed, persona-home and own-posts entries must map query input onto the ListPosts contract. */
final class GatewayFeedControllerTest extends TestCase
{
    public function testFeedMapsChannelOntoBoardScopeBeforeRpc(): void
    {
        $captured = null;
        $grpc = $this->grpcClient(static function (mixed $request) use (&$captured): ListPostsResponse {
            $captured = $request;

            return new ListPostsResponse();
        });

        (new ContentController(
            new ContentServiceClient(target: '127.0.0.1:1', client: $grpc),
            $this->userClientNeverCalled(),
        ))->feed($this->request('/api/v1/feed?channel=alias&page_size=5&cursor=abc'));

        self::assertInstanceOf(ListPostsRequest::class, $captured);
        self::assertSame(2, $captured->getScope());
        self::assertSame('0', (string)$captured->getPersonaId());
        self::assertSame(0, $captured->getIdentityFilter());
        self::assertSame(5, $captured->getPagination()->getPageSize());
        self::assertSame('abc', $captured->getPagination()->getCursor());
    }

    public function testUnknownFeedChannelIsRejectedBeforeRpc(): void
    {
        $grpc = $this->neverCalledGrpc();

        try {
            (new ContentController(
                new ContentServiceClient(target: '127.0.0.1:1', client: $grpc),
                $this->userClientNeverCalled(),
            ))->feed($this->request('/api/v1/feed?channel=following'));
            $this->fail('An unimplemented channel must be rejected at the gateway.');
        } catch (RuntimeException $exception) {
            self::assertSame(ErrorCode::INVALID_ARGUMENT->value, $exception->getCode());
        }
    }

    public function testPersonaHomeIsRejectedForInternalAliasPersonaBeforeContentRpc(): void
    {
        $grpc = $this->neverCalledGrpc();
        $user = $this->createMock(UnaryGrpcClient::class);
        $user->expects($this->once())->method('call')->willReturn(new GetPersonaResponse([
            'persona' => new PersonaProfile([
                'id' => '50001', 'user_id' => '10', 'type' => 2,
                'nickname' => 'internal', 'status' => 1, 'is_internal_alias' => true,
            ]),
        ]));

        try {
            (new ContentController(
                new ContentServiceClient(target: '127.0.0.1:1', client: $grpc),
                new UserServiceClient(target: '127.0.0.1:1', client: $user),
            ))->personaPosts($this->request('/api/v1/personas/50001/posts'), '50001');
            $this->fail('An internal alias must not expose a persona home.');
        } catch (RuntimeException $exception) {
            self::assertSame(ErrorCode::PERSONA_NOT_FOUND->value, $exception->getCode());
        }
    }

    public function testOwnPostsMapIdentityFilterOntoTheContract(): void
    {
        $captured = null;
        $grpc = $this->grpcClient(static function (mixed $request) use (&$captured): ListPostsResponse {
            $captured = $request;

            return new ListPostsResponse();
        });

        (new ContentController(
            new ContentServiceClient(target: '127.0.0.1:1', client: $grpc),
            $this->userClientNeverCalled(),
        ))->myPosts($this->request('/api/v1/me/posts?identity_mode=main'));

        self::assertInstanceOf(ListPostsRequest::class, $captured);
        self::assertSame(4, $captured->getScope());
        self::assertSame(1, $captured->getIdentityFilter());
    }

    /** @param callable(mixed): mixed $response */
    private function grpcClient(callable $response): UnaryGrpcClient
    {
        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->expects($this->once())->method('call')->willReturnCallback(
            static fn (): mixed => $response(func_get_args()[1] ?? null),
        );

        return $grpc;
    }

    private function neverCalledGrpc(): UnaryGrpcClient
    {
        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->expects($this->never())->method('call');

        return $grpc;
    }

    private function userClientNeverCalled(): UserServiceClient
    {
        $user = $this->createMock(UnaryGrpcClient::class);
        $user->expects($this->never())->method('call');

        return new UserServiceClient(target: '127.0.0.1:1', client: $user);
    }

    private function request(string $target): GatewayRequest
    {
        $request = new GatewayRequest("GET {$target} HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $request->authContext['user_id'] = '42';

        return $request;
    }
}
