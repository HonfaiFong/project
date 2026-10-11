<?php

declare(strict_types=1);

namespace Tests\Unit;

use Contracts\Gen\User\V1\ResolvePersonaModeRequest;
use Contracts\Gen\User\V1\ValidatePersonaOwnershipRequest;
use Packages\IdGenerator\Snowflake;
use Packages\Logging\JsonLogger;
use Packages\Phone\PhoneNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Services\UserService\Application\Service\UserApplicationService;
use Services\UserService\Domain\Repository\PersonaRepositoryInterface;
use Services\UserService\Domain\Repository\UserRepositoryInterface;
use Services\UserService\Transport\Grpc\UserGrpcService;
use Spiral\RoadRunner\GRPC\Context;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\ResponseTrailers;
use Spiral\RoadRunner\GRPC\StatusCode;
use Tests\Support\PassThroughTransactionManager;
use Tests\Support\RecordingEventOutbox;

final class UserGrpcIdentityMetadataTest extends TestCase
{
    #[DataProvider('missingOrInvalidMetadataCases')]
    public function testIdentityAuthorizationRequiresTrustedUserMetadata(
        string $rpc,
        object $request,
        array $metadata,
        int $expectedStatus,
    ): void {
        $service = $this->service();
        $context = new Context([
            'metadata' => $metadata,
            ResponseTrailers::class => new ResponseTrailers(),
        ]);

        try {
            if ($rpc === 'ValidatePersonaOwnership') {
                /** @var ValidatePersonaOwnershipRequest $request */
                $service->ValidatePersonaOwnership($context, $request);
            } else {
                /** @var ResolvePersonaModeRequest $request */
                $service->ResolvePersonaMode($context, $request);
            }
            $this->fail('Expected identity authorization metadata rejection');
        } catch (GRPCException $exception) {
            $this->assertSame($expectedStatus, $exception->getCode());
        }
    }

    /** @return iterable<string, array{string, object, array<string, array<string>>, int}> */
    public static function missingOrInvalidMetadataCases(): iterable
    {
        $validate = static fn (): ValidatePersonaOwnershipRequest => new ValidatePersonaOwnershipRequest([
            'user_id' => '42',
            'persona_id' => '7',
            'operation' => 1,
        ]);
        $resolve = static fn (): ResolvePersonaModeRequest => new ResolvePersonaModeRequest([
            'user_id' => '42',
            'mode' => 1,
            'operation' => 1,
        ]);

        yield 'validate-missing' => ['ValidatePersonaOwnership', $validate(), [], StatusCode::UNAUTHENTICATED];
        yield 'validate-invalid-zero' => [
            'ValidatePersonaOwnership',
            $validate(),
            ['x-user-id' => ['0']],
            StatusCode::UNAUTHENTICATED,
        ];
        yield 'validate-mismatch' => [
            'ValidatePersonaOwnership',
            $validate(),
            ['x-user-id' => ['43']],
            StatusCode::PERMISSION_DENIED,
        ];
        yield 'resolve-missing' => ['ResolvePersonaMode', $resolve(), [], StatusCode::UNAUTHENTICATED];
        yield 'resolve-invalid-text' => [
            'ResolvePersonaMode',
            $resolve(),
            ['x-user-id' => ['not-a-user']],
            StatusCode::UNAUTHENTICATED,
        ];
        yield 'resolve-mismatch' => [
            'ResolvePersonaMode',
            $resolve(),
            ['x-user-id' => ['43']],
            StatusCode::PERMISSION_DENIED,
        ];
    }

    private function service(): UserGrpcService
    {
        $application = new UserApplicationService(
            userRepo: $this->createMock(UserRepositoryInterface::class),
            personaRepo: $this->createMock(PersonaRepositoryInterface::class),
            snowflake: new Snowflake(1),
            transactionManager: new PassThroughTransactionManager(),
            outbox: new RecordingEventOutbox(),
            phoneNormalizer: new PhoneNormalizer(),
        );

        return new UserGrpcService(
            $application,
            new JsonLogger('user-grpc-identity-metadata-test', 'test', fopen('php://memory', 'rb+')),
        );
    }
}
