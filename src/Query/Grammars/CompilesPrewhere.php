<?php

namespace ClickHouse\Laravel\Query\Grammars;

use Illuminate\Database\Query\Builder;
use UnexpectedValueException;

trait CompilesPrewhere
{
    /**
     * @param list<
     *     array{type: 'raw', sql: string, boolean: string}
     *     |array{type: 'Null'|'NotNull', column: string, boolean: string}
     *     |array{type: 'In'|'NotIn', column: string, values: array<array-key, mixed>, boolean: string}
     *     |array{type: 'between', column: string, values: array{0: mixed, 1: mixed}, boolean: string, not: bool}
     *     |array{type: 'Basic', column: string, operator: string, value: mixed, boolean: string}
     * > $preWheres
     */
    protected function compilePrewhere(Builder $query, array $preWheres): string
    {
        if (empty($preWheres)) {
            return '';
        }

        $clauses = [];

        foreach ($preWheres as $i => $preWhere) {
            $boolean = $i === 0
                ? ''
                : strtoupper($this->preWhereString($preWhere, 'boolean')).' ';
            $type = $this->preWhereString($preWhere, 'type');

            if ($type === 'raw') {
                $clauses[] = $boolean.$this->preWhereString($preWhere, 'sql');
            } elseif ($type === 'Null') {
                $clauses[] = $boolean.$this->wrap(
                    $this->preWhereString($preWhere, 'column')
                ).' is null';
            } elseif ($type === 'NotNull') {
                $clauses[] = $boolean.$this->wrap(
                    $this->preWhereString($preWhere, 'column')
                ).' is not null';
            } elseif ($type === 'In') {
                $values = $this->preWhereValues($preWhere);
                if ($values === []) {
                    $clauses[] = $boolean.'0 = 1';

                    continue;
                }

                $parameters = $this->parameterize($values);
                $clauses[] = $boolean.$this->wrap(
                    $this->preWhereString($preWhere, 'column')
                )." in ({$parameters})";
            } elseif ($type === 'NotIn') {
                $values = $this->preWhereValues($preWhere);
                if ($values === []) {
                    $clauses[] = $boolean.'1 = 1';

                    continue;
                }

                $parameters = $this->parameterize($values);
                $clauses[] = $boolean.$this->wrap(
                    $this->preWhereString($preWhere, 'column')
                )." not in ({$parameters})";
            } elseif ($type === 'between') {
                $values = array_values($this->preWhereValues($preWhere));
                if (count($values) !== 2) {
                    throw new UnexpectedValueException(
                        'ClickHouse PREWHERE BETWEEN must contain exactly two values.'
                    );
                }

                $not = ($preWhere['not'] ?? false) === true ? 'not ' : '';
                $clauses[] = $boolean.$this->wrap(
                    $this->preWhereString($preWhere, 'column')
                )." {$not}between "
                    .$this->parameter($values[0])
                    .' and '
                    .$this->parameter($values[1]);
            } elseif ($type === 'Basic') {
                if (! array_key_exists('value', $preWhere)) {
                    throw new UnexpectedValueException(
                        'ClickHouse PREWHERE clause is missing its value.'
                    );
                }

                $clauses[] = $boolean.$this->wrap(
                    $this->preWhereString($preWhere, 'column')
                ).' '.$this->preWhereString($preWhere, 'operator').' '
                    .$this->parameter($preWhere['value']);
            } else {
                throw new UnexpectedValueException(
                    "Unknown ClickHouse PREWHERE clause type: {$type}."
                );
            }
        }

        return 'prewhere '.implode(' ', $clauses);
    }

    /** @param array<string, mixed> $preWhere */
    private function preWhereString(array $preWhere, string $key): string
    {
        $value = $preWhere[$key] ?? null;

        if (! is_string($value)) {
            throw new UnexpectedValueException(
                "ClickHouse PREWHERE {$key} must be a string."
            );
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $preWhere
     * @return array<array-key, mixed>
     */
    private function preWhereValues(array $preWhere): array
    {
        $values = $preWhere['values'] ?? null;

        if (! is_array($values)) {
            throw new UnexpectedValueException(
                'ClickHouse PREWHERE values must be an array.'
            );
        }

        return $values;
    }
}
