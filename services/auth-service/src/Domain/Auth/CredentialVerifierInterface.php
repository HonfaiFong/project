<?php

declare(strict_types=1);

namespace Services\AuthService\Domain\Auth;

interface CredentialVerifierInterface
{
    /**
     * @param array<string, mixed> $credentials
     * @throws \RuntimeException
     */
    public function verify(string $phone, array $credentials): void;
}
