<?php

declare(strict_types=1);

namespace Packages\Database;

use MongoDB\Client;
use Packages\GrpcCommon\OperationBudget;
use RuntimeException;

final class MongoConnectionFactory
{
    private const RPC_CONNECT_TIMEOUT_MS = 250;
    private const RPC_SERVER_SELECTION_TIMEOUT_MS = 250;
    private const RPC_SOCKET_TIMEOUT_MS = 500;
    private const RPC_QUERY_TIMEOUT_MS = 300;
    private const RPC_WRITE_TIMEOUT_MS = 250;
    private const RPC_COMMIT_TIMEOUT_MS = 150;
    private const RPC_IO_RESERVE_MS = 100;
    private const RPC_RETRY_DELAY_MS = 10;
    private const RPC_MAX_TRANSACTION_ATTEMPTS = 3;

    public static function forRpc(): Client
    {
        $overrides = [
            'MONGODB_CONNECT_TIMEOUT_MS' => (string) self::setting('MONGODB_RPC_CONNECT_TIMEOUT_MS', self::RPC_CONNECT_TIMEOUT_MS, 1, 60000),
            'MONGODB_SERVER_SELECTION_TIMEOUT_MS' => (string) self::setting('MONGODB_RPC_SERVER_SELECTION_TIMEOUT_MS', self::RPC_SERVER_SELECTION_TIMEOUT_MS, 1, 60000),
            'MONGODB_SOCKET_TIMEOUT_MS' => (string) self::setting('MONGODB_RPC_SOCKET_TIMEOUT_MS', self::RPC_SOCKET_TIMEOUT_MS, 1, 120000),
        ];
        return self::fromEnvironment($overrides);
    }

    public static function rpcQueryTimeoutMs(?OperationBudget $budget, int $additionalReserveMs = 0): int
    {
        $configured = self::setting('MONGODB_RPC_QUERY_TIMEOUT_MS', self::RPC_QUERY_TIMEOUT_MS, 1, 120000);
        return $budget === null
            ? $configured
            : min($configured, $budget->remainingMilliseconds(self::rpcReserveMs() + $additionalReserveMs));
    }

    public static function rpcWriteTimeoutMs(?OperationBudget $budget, int $additionalReserveMs = 0): int
    {
        $configured = self::setting('MONGODB_RPC_WRITE_TIMEOUT_MS', self::RPC_WRITE_TIMEOUT_MS, 1, 120000);
        return $budget === null
            ? $configured
            : min($configured, $budget->remainingMilliseconds(self::rpcReserveMs() + $additionalReserveMs));
    }

    public static function rpcCommitTimeoutMs(?OperationBudget $budget): int
    {
        $configured = self::setting('MONGODB_RPC_COMMIT_TIMEOUT_MS', self::RPC_COMMIT_TIMEOUT_MS, 1, 120000);
        return $budget === null
            ? $configured
            : min($configured, $budget->remainingMilliseconds(self::rpcReserveMs()));
    }

    public static function rpcReserveMs(): int
    {
        return self::setting('MONGODB_RPC_BUDGET_RESERVE_MS', self::RPC_IO_RESERVE_MS, 1, 120000);
    }

    public static function rpcRetryDelayMs(): int
    {
        return self::setting('MONGODB_RPC_RETRY_DELAY_MS', self::RPC_RETRY_DELAY_MS, 0, 1000);
    }

    public static function rpcMaxTransactionAttempts(): int
    {
        return self::setting('MONGODB_RPC_MAX_TRANSACTION_ATTEMPTS', self::RPC_MAX_TRANSACTION_ATTEMPTS, 1, 10);
    }

    /** @param array<string, scalar|null> $overrides */
    public static function fromEnvironment(array $overrides = []): Client
    {
        $value = static function (string $name, string $default = '') use ($overrides): string {
            if (array_key_exists($name, $overrides)) {
                $candidate = $overrides[$name];
                return $candidate === null ? $default : (string) $candidate;
            }
            $env = getenv($name);
            return $env === false ? $default : $env;
        };
        $uri = trim($value('MONGODB_URI'));
        if ($uri === '' || !str_starts_with($uri, 'mongodb')) {
            throw new RuntimeException('MONGODB_URI must be configured');
        }
        $connectTimeout = self::boundedInteger($value('MONGODB_CONNECT_TIMEOUT_MS', '3000'), 'MONGODB_CONNECT_TIMEOUT_MS', 1, 60000);
        $serverSelectionTimeout = self::boundedInteger($value('MONGODB_SERVER_SELECTION_TIMEOUT_MS', '3000'), 'MONGODB_SERVER_SELECTION_TIMEOUT_MS', 1, 60000);
        $socketTimeout = self::boundedInteger($value('MONGODB_SOCKET_TIMEOUT_MS', '5000'), 'MONGODB_SOCKET_TIMEOUT_MS', 1, 120000);

        $options = [
            'connectTimeoutMS' => $connectTimeout,
            'serverSelectionTimeoutMS' => $serverSelectionTimeout,
            'socketTimeoutMS' => $socketTimeout,
            'readPreference' => 'primary',
            'readConcernLevel' => 'majority',
            'w' => 'majority',
            'journal' => true,
            'retryReads' => false,
            'retryWrites' => false,
        ];
        $username = $value('MONGODB_USER');
        $password = $value('MONGODB_PASSWORD');
        if ($username !== '') {
            if ($password === '') {
                throw new RuntimeException('MONGODB_PASSWORD is required with MONGODB_USER');
            }
            $options['username'] = $username;
            $options['password'] = $password;
            $options['authSource'] = $value('MONGODB_AUTH_SOURCE', $value('MONGODB_DATABASE', 'message_db'));
        }
        return new Client($uri, $options);
    }

    private static function boundedInteger(string $value, string $name, int $min, int $max): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if ($parsed === false || $parsed < $min || $parsed > $max) {
            throw new RuntimeException($name . ' is outside its supported range');
        }
        return $parsed;
    }

    private static function setting(string $name, int $default, int $min, int $max): int
    {
        $value = getenv($name);
        $parsed = filter_var($value === false ? (string) $default : $value, FILTER_VALIDATE_INT);
        if ($parsed === false || $parsed < $min || $parsed > $max) {
            throw new RuntimeException($name . ' is outside its supported range');
        }
        return $parsed;
    }
}
