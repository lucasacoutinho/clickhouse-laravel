<?php

namespace ClickHouse\Laravel\Query\Grammars;

use Illuminate\Database\Query\Builder;

trait CompilesLimitBy
{
    protected function compileLimitby(Builder $query, array $limitBy): string
    {
        if (empty($limitBy)) {
            return '';
        }

        $columns = implode(', ', array_map([$this, 'wrap'], $limitBy['columns']));

        return "LIMIT {$limitBy['count']} BY {$columns}";
    }
}
