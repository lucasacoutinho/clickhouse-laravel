<?php

namespace ClickHouse\Laravel\Query\Grammars;

use ClickHouse\Laravel\Exceptions\ClickHouseGrammarException;
use ClickHouse\Laravel\Query\ClickHouseQueryBuilder;
use ClickHouse\Laravel\Support\ClickHouseSql;
use Illuminate\Contracts\Database\Query\Expression;
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
    /** @param array<array-key, mixed> $values */
    public function compileUpdate(Builder $query, array $values): string
    {
        $this->assertMutationClausesAreSupported($query);

        if (empty($query->wheres)) {
            throw ClickHouseGrammarException::updateWithoutWhere();
        }

        $table = $this->wrapTable($query->from);
        $onCluster = $this->compileOnCluster($query);

        $columns = collect($values)->map(function (mixed $value, int|string $key): string {
            if (! is_string($key)) {
                throw new \InvalidArgumentException(
                    'ClickHouse UPDATE column names must be strings.'
                );
            }

            return $this->wrap($key).' = '.$this->parameter($value);
        })->implode(', ');

        $where = parent::compileWheres($query);

        return trim("ALTER TABLE {$table}{$onCluster} UPDATE {$columns} {$where}")
            .$this->compileMutationSettings($query);
    }

    public function compileDelete(
        Builder $query,
        ?bool $lightweight = null,
        mixed $partition = null,
    ): string {
        $this->assertMutationClausesAreSupported($query);

        if (empty($query->wheres)) {
            throw ClickHouseGrammarException::deleteWithoutWhere();
        }

        $table = $this->wrapTable($query->from);
        $onCluster = $this->compileOnCluster($query);
        $partitionClause = $partition === null
            ? ''
            : ' IN PARTITION '.$this->parameter($partition);
        $where = parent::compileWheres($query);
        $command = $lightweight
            ? "DELETE FROM {$table}{$onCluster}{$partitionClause}"
            : "ALTER TABLE {$table}{$onCluster} DELETE{$partitionClause}";

        return trim("{$command} {$where}")
            .$this->compileMutationSettings($query);
    }

    /** @param array<array-key, mixed> $columns */
    public function compileInsertUsing(Builder $query, array $columns, string $sql): string
    {
        $this->assertInsertIsLocal($query);
        $normalizedColumns = $this->normalizeInsertColumns($columns);
        $columnList = $normalizedColumns === [] || $normalizedColumns === ['*']
            ? ''
            : ' ('.$this->columnize($normalizedColumns).')';

        return 'INSERT INTO '.$this->wrapTable($query->from)
            .$columnList
            .$this->compileMutationSettings($query)
            .' '.$sql;
    }

    /**
     * Compile INSERT INTO ... FORMAT for raw format inserts.
     *
     * Produces: INSERT INTO `table` FORMAT JSONEachRow
     */
    /** @param list<Expression|string> $columns */
    public function compileInsertFormat(Builder $query, string $format, array $columns = []): string
    {
        $this->assertInsertIsLocal($query);
        $format = ClickHouseSql::token($format, 'input format');
        $table = $this->wrapTable($query->from);

        if (! empty($columns)) {
            $cols = ' ('.$this->columnize($columns).')';
        } else {
            $cols = '';
        }

        return "INSERT INTO {$table}{$cols}"
            .$this->compileMutationSettings($query)
            ." FORMAT {$format}";
    }

    /** @return array<string, list<mixed>> */
    public function compileTruncate(Builder $query): array
    {
        return [
            'TRUNCATE TABLE '.$this->wrapTable($query->from).$this->compileOnCluster($query) => [],
        ];
    }

    protected function compileOnCluster(Builder $query): string
    {
        if ($query instanceof ClickHouseQueryBuilder && $query->clusterName !== null) {
            return ' ON CLUSTER '.ClickHouseSql::quoteIdentifier($query->clusterName, 'cluster name');
        }

        return '';
    }

    protected function compileMutationSettings(Builder $query): string
    {
        if (! $query instanceof ClickHouseQueryBuilder || $query->querySettings === []) {
            return '';
        }

        return ' '.$this->compileSettings($query, $query->querySettings);
    }

    protected function assertMutationClausesAreSupported(Builder $query): void
    {
        foreach ([
            'JOIN' => $query->joins ?? null,
            'GROUP BY' => $query->groups ?? null,
            'HAVING' => $query->havings ?? null,
            'ORDER BY' => $query->orders ?? null,
            'LIMIT' => $query->limit ?? null,
            'OFFSET' => $query->offset ?? null,
            'UNION' => $query->unions ?? null,
        ] as $clause => $value) {
            if ($value !== null && $value !== []) {
                throw ClickHouseGrammarException::unsupportedMutationClause($clause);
            }
        }

        if (! $query instanceof ClickHouseQueryBuilder) {
            return;
        }

        if ($query->preWheres !== []) {
            throw ClickHouseGrammarException::preWhereOnMutation();
        }

        foreach ([
            'WITH' => $query->withQueries,
            'ARRAY JOIN' => $query->arrayJoins,
            'ClickHouse JOIN' => $query->clickhouseJoins,
            'LIMIT BY' => $query->limitByCount,
            'SAMPLE' => $query->sampleClause,
            'FORMAT' => $query->outputFormat,
            'WITH FILL' => $query->withFills,
        ] as $clause => $value) {
            if ($value !== null && $value !== []) {
                throw ClickHouseGrammarException::unsupportedMutationClause($clause);
            }
        }

        if ($query->useFinal) {
            throw ClickHouseGrammarException::unsupportedMutationClause('FINAL');
        }

        if ($query->interpolateColumns !== []) {
            throw ClickHouseGrammarException::unsupportedMutationClause('INTERPOLATE');
        }
    }

    protected function assertInsertIsLocal(Builder $query): void
    {
        if ($query instanceof ClickHouseQueryBuilder && $query->clusterName !== null) {
            throw ClickHouseGrammarException::onClusterInsert();
        }
    }

    /**
     * @param  array<array-key, mixed>  $columns
     * @return list<Expression|string>
     */
    private function normalizeInsertColumns(array $columns): array
    {
        $normalized = [];

        foreach ($columns as $column) {
            if (
                ! is_string($column)
                && ! $column instanceof Expression
            ) {
                throw new \InvalidArgumentException(
                    'ClickHouse INSERT column names must be strings or expressions.'
                );
            }

            $normalized[] = $column;
        }

        return $normalized;
    }
}
