<?php

declare(strict_types=1);

namespace Tests\Unit;

use Apps\ApiGateway\Action\CreateWsTicketAction;
use Apps\ApiGateway\Action\PersonaLookup;
use Apps\ApiGateway\Action\WsTicketClient;
use Contracts\Errors\ErrorCode;
use Packages\Identity\PersonaOperation;
use Packages\Tracing\TraceContext;
use RuntimeException;
use Tests\TestCase;

final class CreateWsTicketActionTest extends TestCase
{
    protected function tearDown(): void
    {
        TraceContext::reset();
        parent::tearDown();
    }

    public function testRejectsMismatchedPersonaIds(): void
    {
        $action = new CreateWsTicketAction(
            $this->createMock(WsTicketClient::class),
            $this->createMock(PersonaLookup::class)
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(ErrorCode::PERSONA_FORBIDDEN->value);
        $action->execute(
            ['persona_id' => '30001'],
            ['user_id' => '20001', 'requested_persona_id' => '30002']
        );
    }

    public function testRejectsEmptyUserIdOrPersonaId(): void
    {
        $action = new CreateWsTicketAction(
            $this->createMock(WsTicketClient::class),
            $this->createMock(PersonaLookup::class)
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(ErrorCode::AUTH_UNAUTHORIZED->value);
        $action->execute(
            ['persona_id' => ''],
            ['user_id' => '']
        );
    }

    public function testRejectsPersonaNotOwnedByUser(): void
    {
        $userClient = $this->createMock(PersonaLookup::class);
        $userClient->method('validatePersonaOwnership')->willReturn([
            'persona' => [
                'id'      => '99999',
                'user_id' => '99999', // 不同用户
                'status'  => 1,
            ],
        ]);

        $action = new CreateWsTicketAction(
            $this->createMock(WsTicketClient::class),
            $userClient
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(ErrorCode::PERSONA_FORBIDDEN->value);
        $this->expectExceptionMessage('forbidden persona access');

        $action->execute(
            ['persona_id' => '30001'],
            ['user_id' => '20001']
        );
    }

    public function testRejectsInactivePersona(): void
    {
        $userClient = $this->createMock(PersonaLookup::class);
        $userClient->expects($this->once())
            ->method('validatePersonaOwnership')
            ->with('20001', '30001', PersonaOperation::CONNECT->value)
            ->willReturn([
            'persona' => [
                'id'      => '30001',
                'user_id' => '20001',
                'status'  => 0, // 非活跃状态
                'type'    => 1,
            ],
        ]);

        $action = new CreateWsTicketAction(
            $this->createMock(WsTicketClient::class),
            $userClient
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(ErrorCode::PERSONA_FORBIDDEN->value);
        $this->expectExceptionMessage('persona is inactive');

        $action->execute(
            ['persona_id' => '30001'],
            ['user_id' => '20001']
        );
    }

    public function testElevatesTraceContextAndIssuesTicket(): void
    {
        $userClient = $this->createMock(PersonaLookup::class);
        $userClient->expects($this->once())
            ->method('validatePersonaOwnership')
            ->with('20001', '30001', PersonaOperation::CONNECT->value)
            ->willReturn([
            'persona' => [
                'id'      => '30001',
                'user_id' => '20001',
                'status'  => 1,
                'type'    => 1,
                'is_internal_alias' => false,
            ],
        ]);

        $authClient = $this->createMock(WsTicketClient::class);
        $authClient->method('createWsTicket')->willReturnCallback(function (): array {
            $this->assertSame('20001', TraceContext::current()->userId);
            $this->assertSame('30001', TraceContext::current()->personaId);

            return [
                'ticket'     => 'test-ticket-xyz',
                'expires_at' => '1700000060',
            ];
        });

        $action = new CreateWsTicketAction($authClient, $userClient);

        $result = $action->execute(
            ['persona_id' => '30001'],
            ['user_id' => '20001', 'device_id' => 'dev-001']
        );

        $this->assertSame('test-ticket-xyz', $result['ticket']);
        $this->assertSame('1700000060', $result['expires_at']);
        $this->assertSame('30001', TraceContext::current()->personaId);
        $this->assertSame('20001', TraceContext::current()->userId);
    }

    public function testRestoresTraceContextWhenTicketIssuanceFails(): void
    {
        $original = TraceContext::create(
            requestId: 'request-before-ticket',
            traceId: str_repeat('a', 32),
            userId: '20001',
            personaId: null,
            deviceId: 'dev-001',
        );
        TraceContext::setCurrent($original);

        $userClient = $this->createMock(PersonaLookup::class);
        $userClient->expects($this->once())
            ->method('validatePersonaOwnership')
            ->with('20001', '30001', PersonaOperation::CONNECT->value)
            ->willReturn([
            'persona' => [
                'id' => '30001',
                'status' => 1,
                'type' => 1,
                'is_internal_alias' => false,
            ],
        ]);
        $authClient = $this->createMock(WsTicketClient::class);
        $authClient->method('createWsTicket')->willThrowException(new RuntimeException('issuer unavailable'));

        try {
            (new CreateWsTicketAction($authClient, $userClient))->execute(
                ['persona_id' => '30001'],
                ['user_id' => '20001', 'device_id' => 'dev-001'],
            );
            $this->fail('Expected ticket issuance to fail');
        } catch (RuntimeException $exception) {
            $this->assertSame('issuer unavailable', $exception->getMessage());
        }

        $this->assertSame($original, TraceContext::current());
    }

    public function testRejectsInternalAliasPersona(): void
    {
        $userClient = $this->createMock(PersonaLookup::class);
        $userClient->expects($this->once())
            ->method('validatePersonaOwnership')
            ->with('20001', '30001', PersonaOperation::CONNECT->value)
            ->willReturn([
                'persona' => [
                    'id' => '30001',
                    'status' => 1,
                    'type' => 2,
                    'is_internal_alias' => true,
                ],
            ]);

        $action = new CreateWsTicketAction(
            $this->createMock(WsTicketClient::class),
            $userClient,
        );

        $this->expectExceptionCode(ErrorCode::PERSONA_FORBIDDEN->value);
        $this->expectExceptionMessage('anonymous personas cannot open');
        $action->execute(
            ['persona_id' => '30001'],
            ['user_id' => '20001'],
        );
    }
}
