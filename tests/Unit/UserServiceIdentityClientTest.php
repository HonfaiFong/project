<?php

declare(strict_types=1);

namespace Tests\Unit;

use Contracts\Gen\User\V1\PersonaProfile;
use Contracts\Gen\User\V1\ResolvePersonaModeResponse;
use Contracts\Gen\User\V1\ValidatePersonaOwnershipResponse;
use Google\Protobuf\Internal\Message;
use Packages\GrpcCommon\UnaryGrpcClient;
use Packages\Identity\PersonaMode;
use Packages\Identity\PersonaOperation;
use Packages\Identity\UserServiceIdentityClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UserServiceIdentityClientTest extends TestCase
{
    public function testResolveMapsStringMainModeAfterProtoRoundTrip(): void
    {
        $response = new ResolvePersonaModeResponse([
            'persona' => $this->profile(11, 42, 1, 'main', false),
        ]);
        $roundTripped = new ResolvePersonaModeResponse();
        $roundTripped->mergeFromString($response->serializeToString());
        $client = new UserServiceIdentityClient(
            target: 'unused',
            serviceToken: null,
            client: new FakeIdentityUnaryClient($roundTripped),
        );

        $identity = $client->resolve('42', PersonaMode::MAIN, PersonaOperation::CREATE_POST);

        $this->assertSame(PersonaMode::MAIN, $identity->mode);
        $this->assertSame('11', $identity->personaId);
        $this->assertSame('Main profile', $identity->displayName);
    }

    public function testValidateMapsStringAliasModeAndRequiresInternalAlias(): void
    {
        $response = new ValidatePersonaOwnershipResponse([
            'persona' => $this->profile(12, 42, 2, 'alias', true),
        ]);
        $roundTripped = new ValidatePersonaOwnershipResponse();
        $roundTripped->mergeFromString($response->serializeToString());
        $client = new UserServiceIdentityClient(
            target: 'unused',
            serviceToken: null,
            client: new FakeIdentityUnaryClient($roundTripped),
        );

        $identity = $client->validate('42', '12', PersonaOperation::CREATE_POST);

        $this->assertSame(PersonaMode::ALIAS, $identity->mode);
        $this->assertSame('12', $identity->personaId);
    }

    public function testValidateRejectsInconsistentProfileTypeAndMode(): void
    {
        $response = new ValidatePersonaOwnershipResponse([
            'persona' => $this->profile(12, 42, 1, 'alias', true),
        ]);
        $client = new UserServiceIdentityClient(
            target: 'unused',
            serviceToken: null,
            client: new FakeIdentityUnaryClient($response),
        );

        $this->expectException(RuntimeException::class);
        $client->validate('42', '12', PersonaOperation::CREATE_POST);
    }

    private function profile(int $id, int $userId, int $type, string $mode, bool $internalAlias): PersonaProfile
    {
        return new PersonaProfile([
            'id' => $id,
            'user_id' => $userId,
            'type' => $type,
            'nickname' => $type === 1 ? 'Main profile' : '匿名用户',
            'identity_mode' => $mode,
            'is_internal_alias' => $internalAlias,
            'profile_accessible' => $type === 1,
        ]);
    }
}

final class FakeIdentityUnaryClient extends UnaryGrpcClient
{
    public function __construct(private readonly Message $response)
    {
    }

    public function call(
        string $method,
        Message $request,
        string $responseClass,
        int $timeoutMs,
        array $metadata = [],
    ): Message {
        return $this->response;
    }
}
