<?php

namespace ClickHouse\Laravel\Query\Grammars;

use ClickHouse\Laravel\Exceptions\ClickHouseGrammarException;
use Illuminate\Contracts\Database\Query\Expression;
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
    /**
     * @param list<array{
     *     strict: string,
     *     type: string,
     *     global: bool,
     *     alias: string|null,
     *     using: list<string>|null,
     *     on: list<array{0: Expression|string, 1: string, 2: Expression|string}>|null,
     *     subquery: string|null,
     *     table: string|null
     * }> $joins
     */
    protected function compileClickhousejoin(Builder $query, array $joins): string
    {
        if (empty($joins)) {
            return '';
        }

        $clauses = [];

        foreach ($joins as $join) {
            if ($join['using'] !== null && $join['on'] !== null) {
                throw ClickHouseGrammarException::ambiguousJoinKeys();
            }

            $parts = [];

            if ($join['global']) {
                $parts[] = 'GLOBAL';
            }

            if ($join['type'] !== 'CROSS') {
                $parts[] = $join['strict'];
            }
            $parts[] = $join['type'];
            $parts[] = 'JOIN';

            if (is_string($join['subquery'])) {
                $parts[] = '('.$join['subquery'].')';
            } else {
                if ($join['table'] === null) {
                    throw ClickHouseGrammarException::missingJoinKeys();
                }

                $parts[] = $this->wrapTable($join['table']);
            }

            if ($join['alias'] !== null) {
                $parts[] = 'AS';
                $parts[] = $this->wrap($join['alias']);
            }

            if ($join['using'] !== null) {
                $using = implode(', ', array_map(
                    fn (string $column): string => $this->wrap($column),
                    $join['using'],
                ));
                $parts[] = "USING ({$using})";
            } elseif ($join['on'] !== null) {
                $conditions = [];
                foreach ($join['on'] as $condition) {
                    [$left, $operator, $right] = $condition;
                    $conditions[] = $this->wrap($left)
                        .' '.strtoupper($operator).' '
                        .$this->wrap($right);
                }
                $parts[] = 'ON '.implode(' AND ', $conditions);
            }

            $clauses[] = implode(' ', $parts);
        }

        return implode(' ', $clauses);
    }
}
