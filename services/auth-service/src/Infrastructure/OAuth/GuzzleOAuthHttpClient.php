<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\OAuth;

use Contracts\Errors\ErrorCode;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class GuzzleOAuthHttpClient implements OAuthHttpClientInterface
{
    public function __construct(
        private ClientInterface $client,
        private int $connectTimeoutMs = 1000,
        private int $timeoutMs = 3000,
        private int $maxBodyBytes = 65536,
        private bool $allowInsecureHttp = false,
    ) {
        if ($connectTimeoutMs < 1 || $timeoutMs < 1 || $connectTimeoutMs > $timeoutMs) {
            throw new InvalidArgumentException('OAuth HTTP timeouts must be positive and connect timeout must not exceed total timeout');
        }
    }

    public function get(string $url, array $query = []): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $testingHttp = $this->allowInsecureHttp
            && in_array(strtolower(trim((string)(getenv('APP_ENV') ?: 'production'))), ['local', 'development', 'dev', 'testing'], true)
            && $scheme === 'http';
        if (($scheme !== 'https' && !$testingHttp) || $host === '') {
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
        } catch (Throwable $e) {
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
