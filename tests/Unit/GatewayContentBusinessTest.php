<?php

declare(strict_types=1);

namespace Tests\Unit;

use Apps\ApiGateway\Controller\ContentController;
use Apps\ApiGateway\Controller\SocialController;
use Apps\ApiGateway\Grpc\ContentServiceClient;
use Apps\ApiGateway\Grpc\SocialServiceClient;
use Apps\ApiGateway\Http\GatewayRequest;
use Contracts\Errors\ErrorCode;
use Contracts\Gen\Content\V1\CreatePostRequest;
use Contracts\Gen\Content\V1\CreatePostResponse;
use Contracts\Gen\Content\V1\PublicPost;
use Packages\GrpcCommon\UnaryGrpcClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Services\SocialService\Infrastructure\Client\GrpcResourceAccess;

final class GatewayContentBusinessTest extends TestCase
{
    public function testExplicitAliasSocialIntentIsRejectedBeforeRpc(): void
    {
        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->expects($this->never())->method('call');

        try {
            (new SocialController(new SocialServiceClient(target: '127.0.0.1:1', client: $grpc)))
                ->likePost($this->request(['identity_mode' => 'alias', 'persona_id' => '100']), '900');
            $this->fail('An explicit alias interaction must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(ErrorCode::PERSONA_FORBIDDEN->value, $exception->getCode());
        }
    }

    public function testInvalidSocialIdentityModeIsRejectedBeforeRpc(): void
    {
        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->expects($this->never())->method('call');

        $this->expectExceptionCode(ErrorCode::INVALID_ARGUMENT->value);
        (new SocialController(new SocialServiceClient(target: '127.0.0.1:1', client: $grpc)))
            ->likePost($this->request(['identity_mode' => 'unsupported', 'persona_id' => '100']), '900');
    }

    public function testAliasDisplayNameReachesContentRpcAsWriteSnapshotInput(): void
    {
        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->expects($this->once())
            ->method('call')
            ->with(
                '/content.v1.ContentService/CreatePost',
                $this->callback(function (mixed $request): bool {
                    self::assertInstanceOf(CreatePostRequest::class, $request);
                    self::assertSame(2, $request->getIdentityMode());
                    self::assertSame('temporary-name', $request->getAliasDisplayName());
                    return true;
                }),
                CreatePostResponse::class,
                500,
            )
            ->willReturn(new CreatePostResponse(['post' => new PublicPost(['post_id' => '900'])]));

        $response = (new ContentController(new ContentServiceClient(target: '127.0.0.1:1', client: $grpc)))
            ->createPost($this->request([
                'identity_mode' => 'alias',
                'persona_id' => '0',
                'content' => 'anonymous post',
                'alias_display_name' => 'temporary-name',
            ]));

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testAuthenticatedPersonaHeaderIsPassedAsPendingContentSelector(): void
    {
        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->expects($this->once())
            ->method('call')
            ->with(
                '/content.v1.ContentService/CreatePost',
                $this->callback(function (mixed $request): bool {
                    self::assertInstanceOf(CreatePostRequest::class, $request);
                    self::assertSame('110', (string)$request->getPersonaId());
                    return true;
                }),
                CreatePostResponse::class,
                500,
            )
            ->willReturn(new CreatePostResponse(['post' => new PublicPost(['post_id' => '901'])]));

        $request = $this->request(['identity_mode' => 'main', 'content' => 'main post']);
        $request->authContext['requested_persona_id'] = '110';
        $response = (new ContentController(new ContentServiceClient(target: '127.0.0.1:1', client: $grpc)))
            ->createPost($request);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testDeniedContentAuthorizationResponseUsesUint64ZeroFields(): void
    {
        $response = new \Contracts\Gen\Content\V1\CanAccessResourceResponse([
            'allowed' => false,
            'reason' => 'forbidden',
            'resource_author_user_id' => '0',
            'root_author_user_id' => '0',
        ]);
        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->method('call')->willReturn($response);

        $this->expectExceptionCode(ErrorCode::CONTENT_POST_NOT_FOUND->value);
        (new GrpcResourceAccess('127.0.0.1:1', client: $grpc))->assertAccessible('42', 1, '900');
    }

    public function testAllowedContentAuthorizationWithZeroOwnerFailsClosed(): void
    {
        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->method('call')->willReturn(new \Contracts\Gen\Content\V1\CanAccessResourceResponse([
            'allowed' => true,
            'reason' => 'ok',
            'resource_author_user_id' => '0',
            'root_author_user_id' => '0',
        ]));

        $this->expectExceptionCode(ErrorCode::SERVICE_UNAVAILABLE->value);
        (new GrpcResourceAccess('127.0.0.1:1', client: $grpc))->assertAccessible('42', 1, '900');
    }

    /** @param array<string,mixed> $body */
    private function request(array $body): GatewayRequest
    {
        $request = new GatewayRequest(
            "POST /api/v1/test HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: application/json\r\n\r\n"
            . (string)json_encode($body, JSON_THROW_ON_ERROR),
        );
        $request->authContext['user_id'] = '42';

        return $request;
    }
}
