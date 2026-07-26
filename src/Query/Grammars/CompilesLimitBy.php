<?php

namespace ClickHouse\Laravel\Query\Grammars;

use Illuminate\Database\Query\Builder;

trait CompilesLimitBy
{
    /**
     * @param  array{}|array{count: int, columns: list<string>}  $limitBy
     *
     * @psalm-suppress PossiblyUndefinedArrayOffset The empty shape returns above.
     */
    protected function compileLimitby(Builder $query, array $limitBy): string
    {
        if (empty($limitBy)) {
            return '';
        }

        $columns = implode(', ', array_map(
            fn (string $column): string => $this->wrap($column),
            $limitBy['columns'],
        ));

        return "LIMIT {$limitBy['count']} BY {$columns}";
    }
}
