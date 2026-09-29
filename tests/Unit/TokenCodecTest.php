<?php

declare(strict_types=1);

namespace Tests\Unit;

use RuntimeException;
use Services\AuthService\Infrastructure\Token\JwtTokenCodec;
use Services\AuthService\Infrastructure\Token\WsTicketIssuer;
use Tests\TestCase;

final class TokenCodecTest extends TestCase
{
    private const SECRET = 'test-secret-at-least-32-chars-long-12345';

    public function testJwtTokenCodecEncodeAndDecode(): void
    {
        $codec = new JwtTokenCodec(self::SECRET);
        $payload = [
            'sub'       => '20001',
            'device_id' => 'dev_1',
            'iat'       => time(),
            'exp'       => time() + 3600,
        ];

        $jwt = $codec->encode($payload);
        $this->assertNotEmpty($jwt);

        $decoded = $codec->decode($jwt);
        $this->assertSame('20001', $decoded['sub']);
        $this->assertSame('dev_1', $decoded['device_id']);
    }

    public function testJwtTokenCodecRejectsTamperedSignature(): void
    {
        $codec = new JwtTokenCodec(self::SECRET);
        $jwt = $codec->encode(['sub' => '20001', 'exp' => time() + 3600]);

        $tampered = $jwt . 'tamper';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid signature');
        $codec->decode($tampered);
    }

    public function testJwtTokenCodecRejectsExpiredToken(): void
    {
        $codec = new JwtTokenCodec(self::SECRET);
        $jwt = $codec->encode(['sub' => '20001', 'exp' => time() - 10]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Token expired or payload invalid');
        $codec->decode($jwt);
    }

    public function testWsTicketIssuerGeneratesVerifiableFormat(): void
    {
        $issuer = new WsTicketIssuer('ws-secret-at-least-32-chars-12345', 60);
        $ticketInfo = $issuer->issue('20001', '30001', 'dev-1');

        $this->assertNotEmpty($ticketInfo['ticket']);
        $this->assertGreaterThan(time(), $ticketInfo['expire_at']);

        // Decode base64url and verify payload format: user_id:persona_id:device_id:nonce:expire_at:signature
        $decoded = base64_decode(strtr($ticketInfo['ticket'], '-_', '+/'));
        $parts = explode(':', $decoded);
        $this->assertCount(6, $parts);
        $this->assertSame('20001', $parts[0]);
        $this->assertSame('30001', $parts[1]);
        $this->assertSame('dev-1', $parts[2]);
    }
}
