<?php

namespace ClickHouse\Laravel\Query\Concerns;

/**
 * Remote table functions — query tables on other ClickHouse servers.
 *
 * Usage:
 *   ->fromRemote('ch-replica:9000', 'analytics', 'events', 'default', 'password')
 *   ->fromMerge('analytics', '^events_.*')
 */
trait HasRemote
{
    /**
     * FROM remote('host:port', 'database', 'table', 'user', 'password')
     */
    public function fromRemote(string $address, string $database, string $table, string $user = 'default', string $password = ''): static
    {
        return $this->fromRaw("remote('{$address}', '{$database}', '{$table}', '{$user}', '{$password}')");
    }

    /**
     * FROM merge('database', 'regexp')
     */
    public function fromMerge(string $database, string $tableRegexp): static
    {
        return $this->fromRaw("merge('{$database}', '{$tableRegexp}')");
    }
}
