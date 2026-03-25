<?php

namespace ClickHouse\Laravel\Query\Concerns;

trait HasPreWhere
{
    public array $preWheres = [];

    /**
     * PREWHERE — filter rows before reading all columns from disk.
     * Significantly faster than WHERE for selective filters on wide tables.
     *
     * Usage:
     *   ->preWhere('date', '>=', '2026-01-01')
     *   ->preWhere('active', true)
     */
    public function preWhere(string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        $this->preWheres[] = compact('column', 'operator', 'value', 'boolean');

        $this->addBinding($value, 'where');

        return $this;
    }

    public function orPreWhere(string $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->preWhere($column, $operator, $value, 'or');
    }

    public function preWhereRaw(string $sql, array $bindings = [], string $boolean = 'and'): static
    {
        $this->preWheres[] = ['type' => 'raw', 'sql' => $sql, 'boolean' => $boolean];

        $this->addBinding($bindings, 'where');

        return $this;
    }

    public function preWhereIn(string $column, array $values, string $boolean = 'and', bool $not = false): static
    {
        $type = $not ? 'NotIn' : 'In';

        $this->preWheres[] = compact('type', 'column', 'values', 'boolean');

        $this->addBinding($this->cleanBindings($values), 'where');

        return $this;
    }

    public function preWhereNotIn(string $column, array $values, string $boolean = 'and'): static
    {
        return $this->preWhereIn($column, $values, $boolean, true);
    }

    public function preWhereBetween(string $column, array $values, string $boolean = 'and', bool $not = false): static
    {
        $type = 'between';

        $this->preWheres[] = compact('type', 'column', 'values', 'boolean', 'not');

        $this->addBinding(array_slice($this->cleanBindings($values), 0, 2), 'where');

        return $this;
    }
}
