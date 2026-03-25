<?php

namespace ClickHouse\Laravel\Query\Grammars;

use ClickHouse\Laravel\Query\ClickHouseQueryBuilder;
use Illuminate\Database\Query\Builder;

trait CompilesFrom
{
    protected function compileFrom(Builder $query, $table): string
    {
        $from = parent::compileFrom($query, $table);

        if ($query instanceof ClickHouseQueryBuilder) {
            if ($query->useFinal) {
                $from .= ' FINAL';
            }
            if ($query->sampleClause !== null) {
                $from .= ' SAMPLE ' . $query->sampleClause;
            }
        }

        return $from;
    }
}
