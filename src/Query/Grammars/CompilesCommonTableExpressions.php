<?php

namespace ClickHouse\Laravel\Query\Grammars;

use ClickHouse\Laravel\Support\ClickHouseSql;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use UnexpectedValueException;

trait CompilesCommonTableExpressions
{
    /**
     * @param list<array{
     *     identifier: string,
     *     expression: mixed,
     *     subquery: bool,
     *     recursive: bool,
     *     raw: bool
     * }> $withQueries
     */
    protected function compileWithqueries(Builder $query, array $withQueries): string
    {
        if ($withQueries === []) {
            return '';
        }

        $recursive = false;
        $expressions = [];

        foreach ($withQueries as $withQuery) {
            $identifier = ClickHouseSql::quoteIdentifier(
                $withQuery['identifier'],
                'WITH identifier',
            );
            $recursive = $recursive || $withQuery['recursive'];

            if ($withQuery['subquery']) {
                if (! is_string($withQuery['expression'])) {
                    throw new UnexpectedValueException(
                        'ClickHouse CTE subqueries must compile to SQL strings.'
                    );
                }

                $expressions[] = "{$identifier} AS ({$withQuery['expression']})";

                continue;
            }

            $expression = $withQuery['raw']
                ? $this->compileRawWithExpression($withQuery['expression'])
                : $this->parameter($withQuery['expression']);
            $expressions[] = "{$expression} AS {$identifier}";
        }

        return 'WITH '.($recursive ? 'RECURSIVE ' : '').implode(', ', $expressions);
    }

    private function compileRawWithExpression(mixed $expression): string
    {
        if ($expression instanceof Expression) {
            $value = $this->getValue($expression);

            if (! is_string($value)) {
                throw new UnexpectedValueException(
                    'Raw ClickHouse WITH expressions must compile to strings.'
                );
            }

            return $value;
        }

        if (! is_string($expression)) {
            throw new UnexpectedValueException(
                'Raw ClickHouse WITH expressions must be strings or expressions.'
            );
        }

        return $expression;
    }
}
