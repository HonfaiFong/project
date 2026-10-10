<?php

declare(strict_types=1);

namespace Tests\Unit;

use Apps\ApiGateway\Controller\PersonaController;
use Apps\ApiGateway\Grpc\UserServiceClient;
use Apps\ApiGateway\Http\GatewayRequest;
use Contracts\Gen\User\V1\CreatePersonaRequest;
use Contracts\Gen\User\V1\CreatePersonaResponse;
use Contracts\Gen\User\V1\PersonaProfile;
use Contracts\Gen\User\V1\UpdatePersonaRequest;
use Contracts\Gen\User\V1\UpdatePersonaResponse;
use Packages\GrpcCommon\UnaryGrpcClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class GatewayPersonaControllerTest extends TestCase
{
    #[DataProvider('createOptionalFields')]
    public function testCreatePreservesEmptyValueSemantics(array $fields): void
    {
        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->expects($this->once())->method('call')->with(
            '/user.v1.UserService/CreatePersona',
            $this->callback(static function (CreatePersonaRequest $request): bool {
                self::assertSame('42', (string)$request->getUserId());
                self::assertSame('name', $request->getNickname());
                self::assertSame('', $request->getAvatarKey());
                self::assertSame('', $request->getBio());

                return true;
            }),
            CreatePersonaResponse::class,
            150,
        )->willReturn(new CreatePersonaResponse(['persona' => new PersonaProfile(['id' => '7', 'user_id' => '42'])]));

        $response = $this->controller($grpc)->create($this->request(['nickname' => ' name ', ...$fields]));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertArrayNotHasKey('user_id', json_decode($response->rawBody(), true)['data']);
    }

    public function testCreateUsesAuthenticatedUserAndAlwaysLeavesLegacyTypeUnset(): void
    {
        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->expects($this->once())->method('call')->with(
            '/user.v1.UserService/CreatePersona',
            $this->callback(static function (CreatePersonaRequest $request): bool {
                self::assertSame('42', (string)$request->getUserId());
                self::assertSame(0, $request->getType());
                self::assertSame('alias', $request->getNickname());

                return true;
            }),
            CreatePersonaResponse::class,
            150,
        )->willReturn(new CreatePersonaResponse(['persona' => new PersonaProfile(['id' => '7'])]));

        $request = $this->request([
            'nickname' => 'alias',
            'user_id' => '99999',
            'type' => 1,
        ]);
        $response = $this->controller($grpc)->create($request);

        $this->assertSame(201, $response->getStatusCode());
    }

    #[DataProvider('optionalFields')]
    public function testUpdatePreservesFieldPresenceForPatch(array $fields, string $expected, bool $present): void
    {
        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->expects($this->once())->method('call')->with(
            '/user.v1.UserService/UpdatePersona',
            $this->callback(static function (UpdatePersonaRequest $request) use ($expected, $present): bool {
                self::assertSame('42', (string)$request->getUserId());
                self::assertSame('7', (string)$request->getPersonaId());
                self::assertSame($present, $request->hasAvatarKey());
                self::assertSame($present, $request->hasBio());
                self::assertSame($expected, $request->getAvatarKey());
                self::assertSame($expected, $request->getBio());
                self::assertFalse($request->hasNickname());

                return true;
            }),
            UpdatePersonaResponse::class,
            150,
        )->willReturn(new UpdatePersonaResponse());

        $response = $this->controller($grpc)->update($this->request($fields), '7');

        $this->assertSame(200, $response->getStatusCode());
    }

    public static function optionalFields(): array
    {
        return [
            'missing' => [[], '', false],
            'null' => [['avatar_key' => null, 'bio' => null], '', false],
            'empty string' => [['avatar_key' => '', 'bio' => ''], '', true],
            'zero string' => [['avatar_key' => '0', 'bio' => '0'], '0', true],
        ];
    }

    public static function createOptionalFields(): array
    {
        return [
            'missing' => [[]],
            'null' => [['avatar_key' => null, 'bio' => null]],
            'empty string' => [['avatar_key' => '', 'bio' => '']],
            'zero string' => [['avatar_key' => '0', 'bio' => '0']],
        ];
    }

    private function request(array $body): GatewayRequest
    {
        $request = new GatewayRequest("POST /?bio=query HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $request->authContext['user_id'] = '42';
        $request->setPost($body);

        return $request;
    }

    private function controller(UnaryGrpcClient $grpc): PersonaController
    {
        return new PersonaController(new UserServiceClient(client: $grpc));
    }
}
