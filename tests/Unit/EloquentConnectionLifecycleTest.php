<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Packages\Database\EloquentConnection;
use Packages\Outbox\OutboxRepository;
use PDO;
use Tests\TestCase;
use WeakReference;

final class EloquentConnectionLifecycleTest extends TestCase
{
    public function testRepeatedRelayConnectionsAreReleasedFromTheResolver(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $pdo = new PDO('sqlite::memory:');
            $weak = WeakReference::create($pdo);
            $name = 'pdo_' . spl_object_id($pdo);
            $repository = new OutboxRepository($pdo);
            EloquentConnection::forget($pdo);
            unset($repository, $pdo);
            $resolver = Model::getConnectionResolver();
            self::assertInstanceOf(\Illuminate\Database\ConnectionResolver::class, $resolver);
            self::assertFalse($resolver->hasConnection($name));
            unset($resolver);
            gc_collect_cycles();
            self::assertNull($weak->get());
        }
    }
}
