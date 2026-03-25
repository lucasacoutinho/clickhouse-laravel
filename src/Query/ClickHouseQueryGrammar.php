<?php

namespace ClickHouse\Laravel\Query;

use ClickHouse\Laravel\Query\Grammars\CompilesArrayJoin;
use ClickHouse\Laravel\Query\Grammars\CompilesClickHouseJoin;
use ClickHouse\Laravel\Query\Grammars\CompilesFormat;
use ClickHouse\Laravel\Query\Grammars\CompilesFrom;
use ClickHouse\Laravel\Query\Grammars\CompilesLimitBy;
use ClickHouse\Laravel\Query\Grammars\CompilesMutations;
use ClickHouse\Laravel\Query\Grammars\CompilesPrewhere;
use ClickHouse\Laravel\Query\Grammars\CompilesSettings;
use ClickHouse\Laravel\Query\Grammars\CompilesWithFill;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;

/**
 * ClickHouse SQL grammar.
 *
 * Each ClickHouse-specific clause is compiled by its own trait,
 * following the component compiler pattern from ClickhouseBuilder.
 */
class ClickHouseQueryGrammar extends Grammar
{
    use CompilesFrom;
    use CompilesPrewhere;
    use CompilesArrayJoin;
    use CompilesClickHouseJoin;
    use CompilesLimitBy;
    use CompilesFormat;
    use CompilesSettings;
    use CompilesMutations;
    use CompilesWithFill;

    protected $selectComponents = [
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

    protected function wrapValue($value): string
    {
        if ($value === '*') return $value;
        return '`' . str_replace('`', '``', $value) . '`';
    }

    /**
     * Inject ClickHouse-specific data from builder into component properties.
     */
    protected function compileComponents(Builder $query): array
    {
        if ($query instanceof ClickHouseQueryBuilder) {
            $query->arrayjoin = $query->arrayJoins;
            $query->settings = $query->querySettings;
            $query->prewhere = $query->preWheres;
            $query->clickhousejoin = $query->clickhouseJoins;
            $query->format = $query->outputFormat;

            if ($query->limitByCount !== null) {
                $query->limitby = [
                    'count'   => $query->limitByCount,
                    'columns' => $query->limitByColumns,
                ];
            }
        }

        return parent::compileComponents($query);
    }

    protected function compileLimit(Builder $query, $limit): string
    {
        return 'LIMIT ' . (int) $limit;
    }

    protected function compileOffset(Builder $query, $offset): string
    {
        return 'OFFSET ' . (int) $offset;
    }
}
