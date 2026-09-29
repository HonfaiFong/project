<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Auth;

use Services\AuthService\Infrastructure\Auth\LoginCredentialVerifier as BaseLoginCredentialVerifier;

/**
 * Compatibility wrapper. The real verifier now lives in Services\AuthService\Infrastructure\Auth.
 */
class LoginCredentialVerifier extends BaseLoginCredentialVerifier
{
}
