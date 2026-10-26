<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\Persistence\ConnectionRegistry;

/**
 * A connection registry that hands out the given connections, for tests that
 * build services by hand instead of from the container.
 */
final class StubConnectionRegistry implements ConnectionRegistry
{
    /**
     * @param array<string, object> $connections
     */
    public function __construct(private readonly array $connections = [])
    {
    }

    public function getDefaultConnectionName(): string
    {
        return 'default';
    }

    public function getConnection(?string $name = null): object
    {
        return $this->connections[$name ?? 'default'] ?? throw new \InvalidArgumentException(sprintf('No connection named "%s".', $name ?? 'default'));
    }

    public function getConnections(): array
    {
        return $this->connections;
    }

    public function getConnectionNames(): array
    {
        return array_combine(array_keys($this->connections), array_keys($this->connections));
    }
}
