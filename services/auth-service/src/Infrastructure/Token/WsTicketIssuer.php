<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Token;

use Random\RandomException;

final readonly class WsTicketIssuer
{
    public function __construct(
        private string $wsTicketSecret,
        private int $wsTicketTtl = 60
    ) {
    }

    /**
     * @return array{ticket: string, expire_at: int}
     * @throws RandomException when the CSPRNG cannot produce the ticket nonce
     */
    public function issue(string $userId, string $personaId, string $deviceId): array
    {
        $expireAt = time() + $this->wsTicketTtl;
        $nonce = bin2hex(random_bytes(8));

        // Format: user_id:persona_id:device_id:nonce:expire_at:signature
        $signData = sprintf('%s:%s:%s:%s:%d', $userId, $personaId, $deviceId, $nonce, $expireAt);
        $signature = hash_hmac('sha256', $signData, $this->wsTicketSecret);

        // base64url (RFC 4648 §5, unpadded): the ticket travels in the
        // `GET /ws?ticket=` query string, where standard base64's `+/=`
        // characters get mangled by URL query decoding.
        $ticket = rtrim(strtr(base64_encode(sprintf('%s:%s', $signData, $signature)), '+/', '-_'), '=');

        return [
            'ticket'    => $ticket,
            'expire_at' => $expireAt,
        ];
    }
}
