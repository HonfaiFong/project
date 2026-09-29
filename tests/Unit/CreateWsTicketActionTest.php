<?php

declare(strict_types=1);

namespace Tests\Unit;

use Apps\ApiGateway\Action\CreateWsTicketAction;
use Apps\ApiGateway\Action\PersonaLookup;
use Apps\ApiGateway\Action\WsTicketClient;
use Contracts\Errors\ErrorCode;
use Packages\Tracing\TraceContext;
use RuntimeException;
use Tests\TestCase;

final class CreateWsTicketActionTest extends TestCase
{
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
        $userClient->method('getPersona')->willReturn([
            'persona' => [
                'id'      => '30001',
                'user_id' => '99999', // Different user
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
        $userClient->method('getPersona')->willReturn([
            'persona' => [
                'id'      => '30001',
                'user_id' => '20001',
                'status'  => 0, // Inactive
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
        $userClient->method('getPersona')->willReturn([
            'persona' => [
                'id'      => '30001',
                'user_id' => '20001',
                'status'  => 1,
            ],
        ]);

        $authClient = $this->createMock(WsTicketClient::class);
        $authClient->method('createWsTicket')->willReturn([
            'ticket'     => 'test-ticket-xyz',
            'expires_at' => '1700000060',
        ]);

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
}
