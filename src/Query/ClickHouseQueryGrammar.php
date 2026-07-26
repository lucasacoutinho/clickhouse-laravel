<?php

namespace ClickHouse\Laravel\Query;

use ClickHouse\Laravel\Query\Grammars\CompilesArrayJoin;
use ClickHouse\Laravel\Query\Grammars\CompilesClickHouseJoin;
use ClickHouse\Laravel\Query\Grammars\CompilesClickHousePredicates;
use ClickHouse\Laravel\Query\Grammars\CompilesCommonTableExpressions;
use ClickHouse\Laravel\Query\Grammars\CompilesFormat;
use ClickHouse\Laravel\Query\Grammars\CompilesFrom;
use ClickHouse\Laravel\Query\Grammars\CompilesLimitBy;
use ClickHouse\Laravel\Query\Grammars\CompilesMutations;
use ClickHouse\Laravel\Query\Grammars\CompilesPrewhere;
use ClickHouse\Laravel\Query\Grammars\CompilesSetOperations;
use ClickHouse\Laravel\Query\Grammars\CompilesSettings;
use ClickHouse\Laravel\Query\Grammars\CompilesWithFill;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Support\Arr;

/**
 * ClickHouse SQL grammar.
 *
 * Each ClickHouse-specific clause is compiled by its own trait,
 * following the component compiler pattern from ClickhouseBuilder.
 */
/** @api */
class ClickHouseQueryGrammar extends Grammar
{
    use CompilesArrayJoin;
    use CompilesClickHouseJoin;
    use CompilesClickHousePredicates;
    use CompilesCommonTableExpressions;
    use CompilesFormat;
    use CompilesFrom;
    use CompilesLimitBy;
    use CompilesMutations;
    use CompilesPrewhere;
    use CompilesSetOperations;
    use CompilesSettings;
    use CompilesWithFill;

    protected $selectComponents = [
        'withqueries',
        'aggregate',
        'columns',
        'from',
        'clickhousejoin',
        'joins',
        'arrayjoin',
        'prewhere',
        'wheres',
        'groups',
        'havings',
        'orders',
        'limitby',
        'limit',
        'offset',
        'lock',
        'format',
        'settings',
    ];

    public function compileSelect(Builder $query): string
    {
        if (
            ! $query instanceof ClickHouseQueryBuilder
            || $query->unions === null
            || $query->unions === []
        ) {
            return parent::compileSelect($query);
        }

        $format = $query->outputFormat;
        $settings = $query->querySettings;
        $withQueries = $query->withQueries;
        $query->outputFormat = null;
        $query->querySettings = [];
        $query->withQueries = [];

        if ($query->aggregate !== null) {
            try {
                $sql = parent::compileSelect($query);
            } finally {
                $query->outputFormat = $format;
                $query->querySettings = $settings;
                $query->withQueries = $withQueries;
            }

            if ($withQueries !== []) {
                $sql = $this->compileWithqueries($query, $withQueries).' '.$sql;
            }

            return $this->appendClickHouseOutput($query, $sql, $format, $settings);
        }

        $orders = $query->unionOrders;
        $limit = $query->unionLimit;
        $offset = $query->unionOffset;
        $query->unionOrders = null;
        $query->unionLimit = null;
        $query->unionOffset = null;

        try {
            $sql = parent::compileSelect($query);
        } finally {
            $query->unionOrders = $orders;
            $query->unionLimit = $limit;
            $query->unionOffset = $offset;
            $query->outputFormat = $format;
            $query->querySettings = $settings;
            $query->withQueries = $withQueries;
        }

        if (
            ($orders !== null && $orders !== [])
            || $limit !== null
            || $offset !== null
        ) {
            $sql = 'select * from ('.$sql.') AS '.$this->wrapTable('_clickhouse_set');

            if ($orders !== null && $orders !== []) {
                $sql .= ' '.$this->compileOrders($query, $orders);
            }

            if ($limit !== null) {
                $sql .= ' '.$this->compileLimit($query, $limit);
            }

            if ($offset !== null) {
                $sql .= ' '.$this->compileOffset($query, $offset);
            }
        }

        if ($withQueries !== []) {
            $sql = $this->compileWithqueries($query, $withQueries).' '.$sql;
        }

        return $this->appendClickHouseOutput($query, $sql, $format, $settings);
    }

    protected function wrapValue($value): string
    {
        if ($value === '*') {
            return $value;
        }

        return '`'.str_replace('`', '``', $value).'`';
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $settings
     */
    private function appendClickHouseOutput(
        Builder $query,
        string $sql,
        ?string $format,
        array $settings,
    ): string {
        if ($format !== null) {
            $sql .= ' '.$this->compileFormat($query, $format);
        }

        if ($settings !== []) {
            $sql .= ' '.$this->compileSettings($query, $settings);
        }

        return $sql;
    }

    /**
     * Inject ClickHouse-specific data from builder into component properties.
     *
     * @return array<string, mixed>
     */
    protected function compileComponents(Builder $query): array
    {
        if ($query instanceof ClickHouseQueryBuilder) {
            $query->withqueries = $query->withQueries;
            $query->arrayjoin = $query->arrayJoins;
            $query->settings = $query->querySettings;
            $query->prewhere = $query->preWheres;
            $query->clickhousejoin = $query->clickhouseJoins;
            $query->format = $query->outputFormat;

            if ($query->limitByCount !== null) {
                $query->limitby = [
                    'count' => $query->limitByCount,
                    'columns' => $query->limitByColumns,
                ];
            }
        }

        /** @var array<string, mixed> $components */
        $components = parent::compileComponents($query);

        return $components;
    }

    protected function compileLimit(Builder $query, $limit): string
    {
        return 'LIMIT '.$limit;
    }

    protected function compileOffset(Builder $query, $offset): string
    {
        return 'OFFSET '.$offset;
    }

    /** @param array<array-key, mixed> $values */
    public function compileInsert(Builder $query, array $values): string
    {
        $this->assertInsertIsLocal($query);
        $sql = parent::compileInsert($query, $values);

        if ($query instanceof ClickHouseQueryBuilder && $query->querySettings !== []) {
            $settings = $this->compileSettings($query, $query->querySettings);
            $sql = preg_replace(
                '/\s+values\s+/i',
                " {$settings} values ",
                $sql,
                1,
            ) ?? $sql;
        }

        return $sql;
    }

    /**
     * @param  array<array-key, mixed>  $bindings
     * @param  array<array-key, mixed>  $values
     * @return list<mixed>
     */
    public function prepareBindingsForUpdate(array $bindings, array $values): array
    {
        $values = Arr::flatten(array_map(
            fn (mixed $value): mixed => value($value),
            $values,
        ));
        $where = $bindings['where'] ?? [];
        if (! is_array($where)) {
            throw new \InvalidArgumentException(
                'ClickHouse WHERE bindings must be an array.'
            );
        }

        return array_values(array_merge($values, $where));
    }

    /**
     * @param  array<array-key, mixed>  $bindings
     * @return list<mixed>
     */
    public function prepareBindingsForDelete(array $bindings): array
    {
        $partition = $bindings['partition'] ?? [];
        $where = $bindings['where'] ?? [];
        if (! is_array($partition) || ! is_array($where)) {
            throw new \InvalidArgumentException(
                'ClickHouse PARTITION and WHERE bindings must be arrays.'
            );
        }

        return array_values(array_merge($partition, $where));
    }
}
