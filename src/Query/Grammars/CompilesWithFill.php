<?php

namespace ClickHouse\Laravel\Query\Grammars;

use ClickHouse\Laravel\Query\ClickHouseQueryBuilder;
use Illuminate\Database\Query\Builder;

/**
 * Compiles ORDER BY ... WITH FILL and INTERPOLATE clauses.
 *
 * Produces:
 *   ORDER BY `bucket` ASC WITH FILL FROM toDateTime64('...', 3) TO ... STEP toIntervalMinute(5)
 *   INTERPOLATE (`cumulative`)
 */
trait CompilesWithFill
{
    protected function compileOrders(Builder $query, $orders): string
    {
        if (!$query instanceof ClickHouseQueryBuilder || empty($query->withFills)) {
            return parent::compileOrders($query, $orders);
        }

        $compiled = [];

        foreach ($orders as $i => $order) {
            $sql = isset($order['sql'])
                ? $order['sql']
                : $this->wrap($order['column']) . ' ' . $order['direction'];

            if (isset($query->withFills[$i])) {
                $sql .= $this->compileWithFillClause($query->withFills[$i]);
            }

            $compiled[] = $sql;
        }

        $result = 'order by ' . implode(', ', $compiled);

        if (!empty($query->interpolateColumns)) {
            $result .= ' INTERPOLATE (' . implode(', ', $query->interpolateColumns) . ')';
        }

        return $result;
    }

    protected function compileWithFillClause(array $fill): string
    {
        if (isset($fill['raw'])) {
            return ' WITH FILL ' . $fill['raw'];
        }

        $sql = ' WITH FILL';

        if (isset($fill['from'])) {
            $sql .= ' FROM ' . $fill['from'];
        }

        if (isset($fill['to'])) {
            $sql .= ' TO ' . $fill['to'];
        }

        if (isset($fill['step'])) {
            $sql .= ' STEP ' . $fill['step'];
        }

        return $sql;
    }
}
