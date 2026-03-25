<?php

namespace ClickHouse\Laravel\Query\Grammars;

use Illuminate\Database\Query\Builder;

trait CompilesPrewhere
{
    protected function compilePrewhere(Builder $query, array $preWheres): string
    {
        if (empty($preWheres)) {
            return '';
        }

        $clauses = [];

        foreach ($preWheres as $i => $preWhere) {
            $boolean = $i === 0 ? '' : strtoupper($preWhere['boolean']) . ' ';

            if (($preWhere['type'] ?? null) === 'raw') {
                $clauses[] = $boolean . $preWhere['sql'];
            } elseif (($preWhere['type'] ?? null) === 'In') {
                $values = implode(', ', array_fill(0, count($preWhere['values']), '?'));
                $clauses[] = $boolean . $this->wrap($preWhere['column']) . " in ({$values})";
            } elseif (($preWhere['type'] ?? null) === 'NotIn') {
                $values = implode(', ', array_fill(0, count($preWhere['values']), '?'));
                $clauses[] = $boolean . $this->wrap($preWhere['column']) . " not in ({$values})";
            } elseif (($preWhere['type'] ?? null) === 'between') {
                $not = !empty($preWhere['not']) ? 'not ' : '';
                $clauses[] = $boolean . $this->wrap($preWhere['column']) . " {$not}between ? and ?";
            } else {
                $clauses[] = $boolean . $this->wrap($preWhere['column']) . ' ' . $preWhere['operator'] . ' ?';
            }
        }

        return 'prewhere ' . implode(' ', $clauses);
    }
}
