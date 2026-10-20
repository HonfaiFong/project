<?php

declare(strict_types=1);

namespace Packages\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Database\QueryException;
use Closure;
use PDO;
use InvalidArgumentException;

/** 将服务现有的 PDO 注册到 Eloquent，保留共享事务。 */
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
                // 这些 PDO 由服务启动流程管理；保留失败结果，不通过 Laravel 连接工厂重放写入。
                'mysql' => new class($pdo, config: ['name' => $name]) extends MySqlConnection {
                    protected function handleQueryException(QueryException $e, $query, $bindings, Closure $callback)
                    {
                        throw $e;
                    }
                },
                'pgsql' => new class($pdo, config: ['name' => $name]) extends PostgresConnection {
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
     * 在工作进程启动时承担 Eloquent 的进程级初始化开销（类自动加载、trait 初始化、语法及首次查询编译），确保首个 RPC
     * 能满足网关的 gRPC 截止时间。
     *
     * @param class-string<Model> ...$modelClasses
     */
    public static function warmup(PDO $pdo, string ...$modelClasses): void
    {
        foreach ($modelClasses as $class) {
            self::model($class, $pdo)->newQuery()->exists();
        }
    }

    /** 服务工作进程或中继不再使用连接时，将其释放。 */
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
