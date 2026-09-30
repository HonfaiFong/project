<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\OAuth;

use Contracts\Errors\ErrorCode;
use GuzzleHttp\ClientInterface;
use RuntimeException;

final class GuzzleOAuthHttpClient implements OAuthHttpClientInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly int $connectTimeoutMs = 1000,
        private readonly int $timeoutMs = 3000,
        private readonly int $maxBodyBytes = 65536,
    ) {
    }

    public function get(string $url, array $query = []): string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            throw $this->upstreamError();
        }

        try {
            $response = $this->client->request('GET', $url, [
                'query'           => $query,
                'connect_timeout' => $this->connectTimeoutMs / 1000,
                'timeout'         => $this->timeoutMs / 1000,
                'http_errors'     => false,
                'allow_redirects'  => false,
                'headers'         => ['Accept' => 'application/json, text/plain;q=0.9'],
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->message(),
                ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->value,
                $e
            );
        }

        $status = $response->getStatusCode();
        $body = (string)$response->getBody();
        if ($status < 200 || $status >= 300 || strlen($body) > $this->maxBodyBytes) {
            throw $this->upstreamError();
        }

        return $body;
    }

    private function upstreamError(): RuntimeException
    {
        return new RuntimeException(
            ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->message(),
            ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->value
        );
    }
}
