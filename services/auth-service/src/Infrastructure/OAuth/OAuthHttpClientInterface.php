<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\OAuth;

interface OAuthHttpClientInterface
{
    /** @param array<string, string> $query */
    public function get(string $url, array $query = []): string;
}
