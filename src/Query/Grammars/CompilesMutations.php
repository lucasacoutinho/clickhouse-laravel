<?php

namespace ClickHouse\Laravel\Query\Grammars;

use ClickHouse\Laravel\Exceptions\ClickHouseGrammarException;
use ClickHouse\Laravel\Query\ClickHouseQueryBuilder;
use Illuminate\Database\Query\Builder;

/**
 * Compiles ClickHouse mutation statements.
 *
 * UPDATE → ALTER TABLE [ON CLUSTER x] ... UPDATE
 * DELETE → ALTER TABLE [ON CLUSTER x] ... DELETE (requires WHERE)
 * TRUNCATE → TRUNCATE TABLE
 */
trait CompilesMutations
{
    public function compileUpdate(Builder $query, array $values): string
    {
        $table = $this->wrapTable($query->from);
        $onCluster = $this->compileOnCluster($query);

        $columns = collect($values)->map(function ($value, $key) {
            return $this->wrap($key) . ' = ' . $this->parameter($value);
        })->implode(', ');

        $where = parent::compileWheres($query);

        return "ALTER TABLE {$table}{$onCluster} UPDATE {$columns} {$where}";
    }

    public function compileDelete(Builder $query): string
    {
        if (empty($query->wheres)) {
            throw ClickHouseGrammarException::deleteWithoutWhere();
        }

        $table = $this->wrapTable($query->from);
        $onCluster = $this->compileOnCluster($query);
        $where = parent::compileWheres($query);

        return "ALTER TABLE {$table}{$onCluster} DELETE {$where}";
    }

    public function compileInsertUsing(Builder $query, array $columns, string $sql): string
    {
        return 'INSERT INTO ' . $this->wrapTable($query->from)
            . ' (' . $this->columnize($columns) . ') ' . $sql;
    }

    /**
     * Compile INSERT INTO ... FORMAT for raw format inserts.
     *
     * Produces: INSERT INTO `table` FORMAT JSONEachRow
     */
    public function compileInsertFormat(Builder $query, string $format, array $columns = []): string
    {
        $table = $this->wrapTable($query->from);

        if (!empty($columns)) {
            $cols = ' (' . $this->columnize($columns) . ')';
        } else {
            $cols = '';
        }

        return "INSERT INTO {$table}{$cols} FORMAT {$format}";
    }

    public function compileTruncate(Builder $query): array
    {
        return ['TRUNCATE TABLE ' . $this->wrapTable($query->from) => []];
    }

    protected function compileOnCluster(Builder $query): string
    {
        if ($query instanceof ClickHouseQueryBuilder && $query->clusterName) {
            return ' ON CLUSTER ' . $query->clusterName;
        }

        return '';
    }
}
