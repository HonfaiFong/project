<?php

declare(strict_types=1);

namespace Packages\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Database\QueryException;
use Closure;
use PDO;
use InvalidArgumentException;

/** Registers the service's existing PDO with Eloquent, preserving shared transactions. */
final class EloquentConnection
{
    private static ?ConnectionResolver $resolver = null;

    /** @var array<string, Connection> */
    private static array $connections = [];

    public static function fromPdo(PDO $pdo): Connection
    {
        self::$resolver ??= new ConnectionResolver();
        $name = 'pdo_' . spl_object_id($pdo);
        if (!self::$resolver->hasConnection($name)) {
            $connection = match ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
                // These PDOs are owned by service bootstrap, not Laravel's
                // connection factory. Preserve failures without replaying writes.
                'mysql' => new class($pdo, config: ['name' => $name]) extends MySqlConnection {
                    protected function handleQueryException(QueryException $e, $query, $bindings, Closure $callback)
                    {
                        throw $e;
                    }
                },
                'sqlite' => new class($pdo, config: ['name' => $name]) extends SQLiteConnection {
                    protected function handleQueryException(QueryException $e, $query, $bindings, Closure $callback)
                    {
                        throw $e;
                    }
                },
                default => throw new InvalidArgumentException('Unsupported Eloquent database driver'),
            };
            self::$resolver->addConnection($name, $connection);
            self::$connections[$name] = $connection;
        }
        Model::setConnectionResolver(self::$resolver);

        $connection = self::$resolver->connection($name);
        if (!$connection instanceof Connection) {
            throw new InvalidArgumentException('The registered database connection must be an Eloquent connection');
        }

        return $connection;
    }

    /**
     * Pay Eloquent's per-process boot cost (class autoload, trait boot, grammar
     * and first-query compilation) at worker start instead of on the first RPC,
     * which must fit inside the gateway's gRPC deadline.
     *
     * @param class-string<Model> ...$modelClasses
     */
    public static function warmup(PDO $pdo, string ...$modelClasses): void
    {
        foreach ($modelClasses as $class) {
            self::model($class, $pdo)->newQuery()->exists();
        }
    }

    /** Release a connection once its service worker or relay has stopped using it. */
    public static function forget(PDO $pdo): void
    {
        unset(self::$connections['pdo_' . spl_object_id($pdo)]);
        self::$resolver = new ConnectionResolver(self::$connections);
        Model::setConnectionResolver(self::$resolver);
    }

    /**
     * @template T of Model
     * @param class-string<T> $class
     * @return T
     */
    public static function model(string $class, PDO $pdo): Model
    {
        return (new $class())->setConnection(self::fromPdo($pdo)->getName());
    }
}
