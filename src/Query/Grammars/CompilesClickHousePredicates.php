<?php

namespace ClickHouse\Laravel\Query\Grammars;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use UnexpectedValueException;

trait CompilesClickHousePredicates
{
    /** @param array{column: Expression|string, values: array<array-key, mixed>} $where */
    protected function whereGlobalIn(Builder $query, array $where): string
    {
        if ($where['values'] === []) {
            return '0 = 1';
        }

        return $this->wrap($where['column'])
            .' global in ('.$this->parameterize($where['values']).')';
    }

    /** @param array{column: Expression|string, values: array<array-key, mixed>} $where */
    protected function whereGlobalNotIn(Builder $query, array $where): string
    {
        if ($where['values'] === []) {
            return '1 = 1';
        }

        return $this->wrap($where['column'])
            .' global not in ('.$this->parameterize($where['values']).')';
    }

    /** @param array{column: Expression|string} $where */
    protected function whereEmpty(Builder $query, array $where): string
    {
        return 'empty('.$this->wrap($where['column']).')';
    }

    /** @param array{column: Expression|string} $where */
    protected function whereNotEmpty(Builder $query, array $where): string
    {
        return 'not empty('.$this->wrap($where['column']).')';
    }

    /** @param array<array-key, mixed> $having */
    protected function compileBasicHaving($having): string
    {
        $type = $having['type'] ?? null;

        if ($type !== 'Empty' && $type !== 'NotEmpty') {
            return parent::compileBasicHaving($having);
        }

        $column = $having['column'] ?? null;

        if (! is_string($column) && ! $column instanceof Expression) {
            throw new UnexpectedValueException(
                'ClickHouse empty predicates require a string or expression column.'
            );
        }

        return match ($type) {
            'Empty' => 'empty('.$this->wrap($column).')',
            'NotEmpty' => 'not empty('.$this->wrap($column).')',
        };
    }
}
