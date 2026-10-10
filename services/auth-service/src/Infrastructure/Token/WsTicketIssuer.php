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
     * @throws RandomException CSPRNG 无法生成票据随机数时抛出
     */
    public function issue(string $userId, string $personaId, string $deviceId): array
    {
        $expireAt = time() + $this->wsTicketTtl;
        $nonce = bin2hex(random_bytes(8));

        // 格式：user_id:persona_id:device_id:nonce:expire_at:signature
        $signData = sprintf('%s:%s:%s:%s:%d', $userId, $personaId, $deviceId, $nonce, $expireAt);
        $signature = hash_hmac('sha256', $signData, $this->wsTicketSecret);

        // 采用无填充的 base64url（RFC 4648 §5）；票据在 `GET /ws?ticket=` 查询字符串中传递，标准 base64 的 `+/=` 字符会
        // 被 URL 查询解码破坏。
        $ticket = rtrim(strtr(base64_encode(sprintf('%s:%s', $signData, $signature)), '+/', '-_'), '=');

        return [
            'ticket'    => $ticket,
            'expire_at' => $expireAt,
        ];
    }
}
