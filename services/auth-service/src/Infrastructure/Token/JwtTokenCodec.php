<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Token;

use RuntimeException;

final readonly class JwtTokenCodec
{
    public function __construct(private string $jwtSecret)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function encode(array $payload): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $b64Header = $this->base64UrlEncode((string)json_encode($header));
        $b64Payload = $this->base64UrlEncode((string)json_encode($payload));

        $signature = hash_hmac('sha256', "$b64Header.$b64Payload", $this->jwtSecret, true);
        $b64Sig = $this->base64UrlEncode($signature);

        return "$b64Header.$b64Payload.$b64Sig";
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed JWT');
        }

        [$b64Header, $b64Payload, $b64Sig] = $parts;
        $expectedSig = hash_hmac('sha256', "$b64Header.$b64Payload", $this->jwtSecret, true);

        if (!hash_equals($expectedSig, $this->base64UrlDecode($b64Sig))) {
            throw new RuntimeException('Invalid signature');
        }

        $payload = json_decode($this->base64UrlDecode($b64Payload), true);
        if (!is_array($payload) || !isset($payload['exp']) || $payload['exp'] < time()) {
            throw new RuntimeException('Token expired or payload invalid');
        }

        return $payload;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return (string)base64_decode(strtr($data, '-_', '+/'));
    }
}
