<?php

namespace ClickHouse\Laravel\Query\Concerns;

use ClickHouse\Laravel\Support\ClickHouseSql;

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
        ClickHouseSql::nonEmpty($address, 'remote address');
        ClickHouseSql::nonEmpty($database, 'remote database');
        ClickHouseSql::nonEmpty($table, 'remote table');
        ClickHouseSql::nonEmpty($user, 'remote user');

        $arguments = array_map(
            ClickHouseSql::quoteString(...),
            [$address, $database, $table, $user, $password],
        );

        $expression = 'remote('.implode(', ', $arguments).')';

        return $this->fromTrustedTableFunction($expression);
    }

    /**
     * FROM merge('database', 'regexp')
     */
    public function fromMerge(string $database, string $tableRegexp): static
    {
        ClickHouseSql::nonEmpty($database, 'merge database');
        ClickHouseSql::nonEmpty($tableRegexp, 'merge table regexp');

        /** @var literal-string $expression */
        $expression = sprintf(
            'merge(%s, %s)',
            ClickHouseSql::quoteString($database),
            ClickHouseSql::quoteString($tableRegexp),
        );

        return $this->fromTrustedTableFunction($expression);
    }

    private function fromTrustedTableFunction(string $expression): static
    {
        /** @var literal-string $expression */
        return $this->fromRaw($expression);
    }
}
