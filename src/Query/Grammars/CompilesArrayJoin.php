<?php

namespace ClickHouse\Laravel\Query\Grammars;

use Illuminate\Database\Query\Builder;

trait CompilesArrayJoin
{
    protected function compileArrayjoin(Builder $query, array $arrayJoins): string
    {
        if (empty($arrayJoins)) {
            return '';
        }

        $clauses = [];

        foreach ($arrayJoins as $join) {
            $prefix = $join['type'] === 'left' ? 'LEFT ARRAY JOIN' : 'ARRAY JOIN';
            $column = $this->wrap($join['column']);

            if ($join['alias']) {
                $column .= ' AS ' . $this->wrap($join['alias']);
            }

            $clauses[] = "{$prefix} {$column}";
        }

        return implode(' ', $clauses);
    }
}
