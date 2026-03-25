<?php

namespace ClickHouse\Laravel\Schema;

use Closure;
use Illuminate\Database\Schema\Builder;

class ClickHouseSchemaBuilder extends Builder
{
    public function create($table, Closure $callback): void
    {
        $this->build(
            tap($this->createBlueprint($table, $callback), function ($blueprint) {
                $blueprint->create();
            })
        );
    }

    protected function createBlueprint($table, ?Closure $callback = null): ClickHouseBlueprint
    {
        // Laravel 12+: Blueprint($connection, $table, $callback)
        // Laravel 10-11: Blueprint($table, $callback, $prefix)
        try {
            return new ClickHouseBlueprint($this->connection, $table, $callback);
        } catch (\TypeError) {
            $prefix = $this->connection->getConfig('prefix_indexes')
                ? $this->connection->getConfig('prefix')
                : '';
            return new ClickHouseBlueprint($table, $callback, $prefix);
        }
    }

    public function hasTable($table): bool
    {
        $grammar = $this->connection->getSchemaGrammar();
        $wrapped = $grammar->wrapTable($this->connection->getTablePrefix() . $table);

        $result = $this->connection->selectOne("EXISTS TABLE {$wrapped}");

        if (!$result) {
            return false;
        }

        // Handle both object and array results — grab the first column value
        $row = (array) $result;

        return (int) reset($row) === 1;
    }

    public function getTables($schema = null): array
    {
        $results = $this->connection->select('SHOW TABLES');

        return array_map(fn($row) => (array) $row, $results);
    }

    public function getColumnListing($table): array
    {
        $grammar = $this->connection->getSchemaGrammar();
        $wrapped = $grammar->wrapTable($this->connection->getTablePrefix() . $table);

        $results = $this->connection->select("DESCRIBE TABLE {$wrapped}");

        return array_map(fn($row) => $row->name, $results);
    }

    public function dropAllTables(): void
    {
        $tables = $this->getTables();
        foreach ($tables as $table) {
            $name = $table['name'] ?? reset($table);
            $this->drop($name);
        }
    }
}
