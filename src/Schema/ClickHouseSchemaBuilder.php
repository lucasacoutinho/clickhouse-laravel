<?php

namespace ClickHouse\Laravel\Schema;

use Closure;
use Illuminate\Database\Schema\Builder;
use ReflectionClass;
use UnexpectedValueException;

/**
 * @api
 *
 * @psalm-suppress PropertyNotSetInConstructor Laravel owns the inherited resolver.
 */
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
        $reflection = new ReflectionClass(ClickHouseBlueprint::class);
        $constructor = $reflection->getConstructor();
        $parameters = $constructor?->getParameters() ?? [];
        $firstParameter = $parameters[0] ?? null;
        $usesConnectionConstructor = $firstParameter?->getName() === 'connection';

        $arguments = $usesConnectionConstructor
            ? [$this->connection, $table, $callback]
            : [
                $table,
                $callback,
                $this->connection->getConfig('prefix_indexes')
                    ? $this->connection->getConfig('prefix')
                    : '',
            ];

        return $reflection->newInstanceArgs($arguments);
    }

    /** @psalm-suppress MixedAssignment The driver result is validated below. */
    public function hasTable($table): bool
    {
        $grammar = $this->connection->getSchemaGrammar();
        $wrapped = $grammar->wrapTable($this->connection->getTablePrefix().$table);

        $result = $this->connection->selectOne("EXISTS TABLE {$wrapped}");

        if (! $result) {
            return false;
        }

        // Handle object and array results by reading the first column value.
        $row = (array) $result;

        $value = reset($row);

        return $value === 1 || $value === '1';
    }

    /**
     * @param  string|array<string>|null  $schema
     * @return list<array{
     *     name: string,
     *     schema: string|null,
     *     schema_qualified_name: string,
     *     size: int|null,
     *     comment: string|null,
     *     collation: string|null,
     *     engine: string|null
     * }>
     *
     * @psalm-suppress MixedAssignment Driver rows are normalized below.
     */
    public function getTables($schema = null): array
    {
        $results = $this->connection->select('SHOW TABLES');
        $schemaName = is_string($schema)
            ? $schema
            : $this->connection->getDatabaseName();
        $tables = [];

        foreach ($results as $result) {
            $row = (array) $result;
            $name = $row['name'] ?? reset($row);

            if (! is_string($name)) {
                throw new UnexpectedValueException(
                    'ClickHouse SHOW TABLES returned a row without a table name.'
                );
            }

            $tables[] = [
                'name' => $name,
                'schema' => $schemaName,
                'schema_qualified_name' => $schemaName !== ''
                    ? "{$schemaName}.{$name}"
                    : $name,
                'size' => null,
                'comment' => null,
                'collation' => null,
                'engine' => null,
            ];
        }

        return $tables;
    }

    /**
     * @param  string  $table
     * @return list<string>
     *
     * @psalm-suppress MixedAssignment Driver rows are normalized below.
     */
    public function getColumnListing($table): array
    {
        $grammar = $this->connection->getSchemaGrammar();
        $wrapped = $grammar->wrapTable($this->connection->getTablePrefix().$table);

        $results = $this->connection->select("DESCRIBE TABLE {$wrapped}");

        $columns = [];

        foreach ($results as $result) {
            $row = (array) $result;
            $name = $row['name'] ?? null;

            if (! is_string($name)) {
                throw new UnexpectedValueException(
                    'ClickHouse DESCRIBE TABLE returned a row without a column name.'
                );
            }

            $columns[] = $name;
        }

        return $columns;
    }

    public function dropAllTables(): void
    {
        $tables = $this->getTables();
        foreach ($tables as $table) {
            $this->drop($table['name']);
        }
    }
}
