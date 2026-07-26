<?php

namespace ClickHouse\Laravel\Query\Grammars;

use ClickHouse\Laravel\Support\ClickHouseSql;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use UnexpectedValueException;

trait CompilesSetOperations
{
    /**
     * ClickHouse does not accept a trailing global ORDER BY after bare
     * parenthesized set operands. Promote each operand to a SELECT instead.
     *
     * @param  mixed  $sql
     */
    protected function wrapUnion($sql): string
    {
        if (! is_string($sql)) {
            throw new UnexpectedValueException(
                'ClickHouse set-operation SQL must be a string.'
            );
        }

        return "select * from ({$sql})";
    }

    /**
     * @param  array<array-key, mixed>  $union
     */
    protected function compileUnion(array $union): string
    {
        if (isset($union['operator'])) {
            if (! is_string($union['operator'])) {
                throw new UnexpectedValueException(
                    'ClickHouse set operators must be strings.'
                );
            }

            $operator = $union['operator'];
        } else {
            if (isset($union['all']) && ! is_bool($union['all'])) {
                throw new UnexpectedValueException(
                    'ClickHouse union mode must be a boolean.'
                );
            }

            $operator = ($union['all'] ?? false) === true
                ? 'UNION ALL'
                : 'UNION DISTINCT';
        }

        $operator = ClickHouseSql::oneOf($operator, [
            'UNION ALL',
            'UNION DISTINCT',
            'INTERSECT ALL',
            'INTERSECT DISTINCT',
            'EXCEPT ALL',
            'EXCEPT DISTINCT',
        ], 'set operator');
        $query = $union['query'] ?? null;

        if (! $query instanceof Builder && ! $query instanceof EloquentBuilder) {
            throw new UnexpectedValueException(
                'ClickHouse set operations require a query builder.'
            );
        }

        return " {$operator} {$this->wrapUnion($query->toSql())}";
    }
}
