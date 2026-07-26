<?php

namespace ClickHouse\Laravel\Query\Grammars;

use ClickHouse\Laravel\Query\ClickHouseQueryBuilder;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use UnexpectedValueException;

/**
 * Compiles ORDER BY ... WITH FILL and INTERPOLATE clauses.
 *
 * Produces:
 *   ORDER BY `bucket` ASC WITH FILL FROM toDateTime64('...', 3) TO ... STEP toIntervalMinute(5)
 *   INTERPOLATE (`cumulative`)
 */
trait CompilesWithFill
{
    /**
     * @param  array<array-key, mixed>  $orders
     *
     * @psalm-suppress MixedAssignment Order entries are validated in the loop.
     * @psalm-suppress PossiblyUndefinedArrayOffset Builder-owned shapes are validated above.
     */
    protected function compileOrders(Builder $query, $orders): string
    {
        if (! $query instanceof ClickHouseQueryBuilder || empty($query->withFills)) {
            return parent::compileOrders($query, $orders);
        }

        $compiled = [];

        foreach (array_values($orders) as $i => $order) {
            if (! is_array($order)) {
                throw new UnexpectedValueException(
                    'ClickHouse ORDER BY entries must be arrays.'
                );
            }

            if (isset($order['sql'])) {
                if (! is_string($order['sql'])) {
                    throw new UnexpectedValueException(
                        'Raw ClickHouse ORDER BY clauses must be strings.'
                    );
                }

                $sql = $order['sql'];
            } else {
                $column = $order['column'] ?? null;
                $direction = $order['direction'] ?? null;

                if (
                    (! is_string($column) && ! $column instanceof Expression)
                    || ! is_string($direction)
                ) {
                    throw new UnexpectedValueException(
                        'ClickHouse ORDER BY clauses require a column and direction.'
                    );
                }

                $sql = $this->wrap($column).' '.$direction;
            }

            if (isset($query->withFills[$i])) {
                $sql .= $this->compileWithFillClause($query->withFills[$i]);
            }

            $compiled[] = $sql;
        }

        $result = 'order by '.implode(', ', $compiled);

        if (! empty($query->interpolateColumns)) {
            $columns = [];

            foreach ($query->interpolateColumns as $column) {
                $columns[] = isset($column['raw'])
                    ? $column['raw']
                    : $this->wrap($column['column']);
            }

            $result .= ' INTERPOLATE ('.implode(', ', $columns).')';
        }

        return $result;
    }

    /**
     * @param  array{raw: string}|array{from?: string, to?: string, step?: string}  $fill
     */
    protected function compileWithFillClause(array $fill): string
    {
        if (isset($fill['raw'])) {
            return ' WITH FILL '.$fill['raw'];
        }

        $sql = ' WITH FILL';

        if (isset($fill['from'])) {
            $sql .= ' FROM '.$fill['from'];
        }

        if (isset($fill['to'])) {
            $sql .= ' TO '.$fill['to'];
        }

        if (isset($fill['step'])) {
            $sql .= ' STEP '.$fill['step'];
        }

        return $sql;
    }
}
