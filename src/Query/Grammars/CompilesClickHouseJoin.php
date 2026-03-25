<?php

namespace ClickHouse\Laravel\Query\Grammars;

use ClickHouse\Laravel\Exceptions\ClickHouseGrammarException;
use Illuminate\Database\Query\Builder;

/**
 * Compiles ClickHouse-specific JOINs.
 *
 * Produces:
 *   [GLOBAL] [ANY|ALL] [LEFT|INNER|RIGHT|CROSS] JOIN `table` [AS `alias`] USING (`col1`, `col2`)
 *   [GLOBAL] [ANY|ALL] [LEFT|INNER] JOIN (SELECT ...) AS `alias` ON `a`.`id` = `b`.`id`
 */
trait CompilesClickHouseJoin
{
    protected function compileClickhousejoin(Builder $query, array $joins): string
    {
        if (empty($joins)) {
            return '';
        }

        $clauses = [];

        foreach ($joins as $join) {
            if ($join['using'] && $join['on']) {
                throw ClickHouseGrammarException::ambiguousJoinKeys();
            }

            $parts = [];

            if ($join['global']) {
                $parts[] = 'GLOBAL';
            }

            $parts[] = $join['strict'];
            $parts[] = $join['type'];
            $parts[] = 'JOIN';

            if ($join['subquery'] instanceof Builder) {
                $parts[] = '(' . $join['subquery']->toSql() . ')';
                $query->addBinding($join['subquery']->getBindings(), 'join');
            } else {
                $parts[] = $this->wrapTable($join['table']);
            }

            if ($join['alias']) {
                $parts[] = 'AS';
                $parts[] = $this->wrap($join['alias']);
            }

            if ($join['using']) {
                $using = implode(', ', array_map([$this, 'wrap'], $join['using']));
                $parts[] = "USING ({$using})";
            } elseif ($join['on']) {
                $conditions = [];
                foreach ($join['on'] as $condition) {
                    [$left, $operator, $right] = $condition;
                    $conditions[] = $this->wrap($left) . " {$operator} " . $this->wrap($right);
                }
                $parts[] = 'ON ' . implode(' AND ', $conditions);
            }

            $clauses[] = implode(' ', $parts);
        }

        return implode(' ', $clauses);
    }
}
