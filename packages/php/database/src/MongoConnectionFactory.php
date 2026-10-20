<?php

declare(strict_types=1);

namespace Packages\Database;

use MongoDB\Client;
use RuntimeException;

final class MongoConnectionFactory
{
    // PHP's driver has no per-operation client-side deadline API. Keep each
    // transport phase short for RPC clients; repositories reserve three such
    // phases before issuing I/O and check the shared deadline between calls.
    public const RPC_IO_TIMEOUT_MS = 50;
    public const RPC_IO_RESERVE_MS = 150;

    public static function forRpc(): Client
    {
        $overrides = [];
        foreach (['MONGODB_CONNECT_TIMEOUT_MS', 'MONGODB_SERVER_SELECTION_TIMEOUT_MS', 'MONGODB_SOCKET_TIMEOUT_MS'] as $name) {
            $configured = getenv($name);
            $milliseconds = $configured === false ? self::RPC_IO_TIMEOUT_MS : filter_var($configured, FILTER_VALIDATE_INT);
            if (!is_int($milliseconds) || $milliseconds < 1) {
                throw new RuntimeException($name . ' must be a positive integer');
            }
            $overrides[$name] = min($milliseconds, self::RPC_IO_TIMEOUT_MS);
        }
        return self::fromEnvironment($overrides);
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
}
