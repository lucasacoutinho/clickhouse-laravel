<?php

namespace ClickHouse\Laravel\Query\Concerns;

use ClickHouse\Laravel\Support\ClickHouseSql;
use Illuminate\Contracts\Database\Query\Expression;

trait HasPreWhere
{
    /**
     * @var list<
     *     array{type: 'raw', sql: string, boolean: string}
     *     |array{type: 'Null'|'NotNull', column: string, boolean: string}
     *     |array{type: 'In'|'NotIn', column: string, values: array<array-key, mixed>, boolean: string}
     *     |array{type: 'between', column: string, values: array{0: mixed, 1: mixed}, boolean: string, not: bool}
     *     |array{type: 'Basic', column: string, operator: string, value: mixed, boolean: string}
     * >
     */
    public array $preWheres = [];

    /**
     * PREWHERE — filter rows before reading all columns from disk.
     * Significantly faster than WHERE for selective filters on wide tables.
     *
     * Usage:
     *   ->preWhere('date', '>=', '2026-01-01')
     *   ->preWhere('active', true)
     *
     * @psalm-suppress MixedAssignment Shorthand operands are normalized immediately.
     */
    public function preWhere(string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        ClickHouseSql::nonEmpty($column, 'PREWHERE column');
        $boolean = $this->normalizePreWhereBoolean($boolean);
        [$value, $operator] = $this->preparePreWhereValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2,
        );
        $operator = $this->normalizePreWhereOperator($operator);

        if ($value === null) {
            $type = in_array($operator, ['!=', '<>'], true) ? 'NotNull' : 'Null';
            $this->preWheres[] = compact('type', 'column', 'boolean');

            return $this;
        }

        $type = 'Basic';
        $this->preWheres[] = compact('type', 'column', 'operator', 'value', 'boolean');

        if (! $value instanceof Expression) {
            $this->addBinding($value, 'prewhere');
        }

        return $this;
    }

    /** @psalm-suppress MixedAssignment Shorthand operands are normalized immediately. */
    public function orPreWhere(string $column, mixed $operator = null, mixed $value = null): static
    {
        [$value, $operator] = $this->preparePreWhereValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2,
        );

        return $this->preWhere($column, $operator, $value, 'or');
    }

    /** @param array<array-key, mixed> $bindings */
    public function preWhereRaw(string $sql, array $bindings = [], string $boolean = 'and'): static
    {
        ClickHouseSql::nonEmpty($sql, 'PREWHERE raw expression');
        $boolean = $this->normalizePreWhereBoolean($boolean);
        $this->preWheres[] = ['type' => 'raw', 'sql' => $sql, 'boolean' => $boolean];

        $this->addBinding($bindings, 'prewhere');

        return $this;
    }

    /** @param array<array-key, mixed> $bindings */
    public function orPreWhereRaw(string $sql, array $bindings = []): static
    {
        return $this->preWhereRaw($sql, $bindings, 'or');
    }

    /** @param array<array-key, mixed> $values */
    public function preWhereIn(string $column, array $values, string $boolean = 'and', bool $not = false): static
    {
        ClickHouseSql::nonEmpty($column, 'PREWHERE column');
        $boolean = $this->normalizePreWhereBoolean($boolean);
        $type = $not ? 'NotIn' : 'In';

        $this->preWheres[] = compact('type', 'column', 'values', 'boolean');

        $this->addBinding($this->cleanBindings($values), 'prewhere');

        return $this;
    }

    /** @param array<array-key, mixed> $values */
    public function preWhereNotIn(string $column, array $values, string $boolean = 'and'): static
    {
        return $this->preWhereIn($column, $values, $boolean, true);
    }

    /** @param array<array-key, mixed> $values */
    public function preWhereBetween(string $column, array $values, string $boolean = 'and', bool $not = false): static
    {
        ClickHouseSql::nonEmpty($column, 'PREWHERE column');

        if (count($values) !== 2) {
            throw new \InvalidArgumentException(
                'ClickHouse PREWHERE BETWEEN requires exactly two values.'
            );
        }

        $boolean = $this->normalizePreWhereBoolean($boolean);
        $type = 'between';
        /** @var array{0: mixed, 1: mixed} $values */
        $values = array_values($values);

        $this->preWheres[] = compact('type', 'column', 'values', 'boolean', 'not');

        $this->addBinding(array_slice($this->cleanBindings($values), 0, 2), 'prewhere');

        return $this;
    }

    /** @param array<array-key, mixed> $values */
    public function preWhereNotBetween(string $column, array $values, string $boolean = 'and'): static
    {
        return $this->preWhereBetween($column, $values, $boolean, true);
    }

    /** @param array<array-key, mixed>|string $columns */
    public function preWhereNull(array|string $columns, string $boolean = 'and'): static
    {
        foreach ((array) $columns as $column) {
            if (! is_string($column)) {
                throw new \InvalidArgumentException(
                    'ClickHouse PREWHERE NULL columns must be strings.'
                );
            }

            $this->preWhere($column, '=', null, $boolean);
        }

        return $this;
    }

    /** @param array<array-key, mixed>|string $columns */
    public function preWhereNotNull(array|string $columns, string $boolean = 'and'): static
    {
        foreach ((array) $columns as $column) {
            if (! is_string($column)) {
                throw new \InvalidArgumentException(
                    'ClickHouse PREWHERE NOT NULL columns must be strings.'
                );
            }

            $this->preWhere($column, '!=', null, $boolean);
        }

        return $this;
    }

    private function normalizePreWhereBoolean(string $boolean): string
    {
        $boolean = strtolower($boolean);

        if (! in_array($boolean, ['and', 'or'], true)) {
            throw new \InvalidArgumentException(
                'ClickHouse PREWHERE boolean must be "and" or "or".'
            );
        }

        return $boolean;
    }

    private function normalizePreWhereOperator(mixed $operator): string
    {
        if (! is_string($operator)) {
            throw new \InvalidArgumentException(
                'ClickHouse PREWHERE operator must be a string.'
            );
        }

        $normalized = strtolower(trim($operator));
        $allowed = [
            '=', '!=', '<>', '<', '<=', '>', '>=',
            'like', 'not like', 'ilike', 'not ilike',
        ];

        if (! in_array($normalized, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Unsupported ClickHouse PREWHERE operator: {$operator}."
            );
        }

        return str_contains($normalized, 'like') ? strtoupper($normalized) : $normalized;
    }

    /** @return array{0: mixed, 1: mixed} */
    private function preparePreWhereValueAndOperator(
        mixed $value,
        mixed $operator,
        bool $useDefault,
    ): array {
        if ($useDefault) {
            return [$operator, '='];
        }

        if (
            $value === null
            && is_string($operator)
            && ! in_array(strtolower(trim($operator)), ['=', '!=', '<>'], true)
        ) {
            throw new \InvalidArgumentException(
                'Illegal PREWHERE operator and null value combination.'
            );
        }

        return [$value, $operator];
    }
}
