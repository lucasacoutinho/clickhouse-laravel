<?php

namespace ClickHouse\Laravel\Query\Concerns;

use ClickHouse\Laravel\Support\ClickHouseSql;
use Illuminate\Contracts\Database\Query\Expression;
use InvalidArgumentException;
use LogicException;

trait HasClickHousePredicates
{
    public function whereGlobalIn(
        Expression|string $column,
        mixed $values,
        string $boolean = 'and',
        bool $not = false,
    ): static {
        $boolean = $this->normalizeClickHouseBoolean($boolean);
        $index = count($this->wheres);

        $this->whereIn($column, $values, $boolean, $not);
        $where = $this->wheres[$index] ?? null;

        if (! is_array($where)) {
            throw new LogicException('ClickHouse could not register the GLOBAL IN predicate.');
        }

        $where['type'] = $not ? 'GlobalNotIn' : 'GlobalIn';
        $this->wheres[$index] = $where;

        return $this;
    }

    public function whereGlobalNotIn(
        Expression|string $column,
        mixed $values,
        string $boolean = 'and',
    ): static {
        return $this->whereGlobalIn($column, $values, $boolean, true);
    }

    public function orWhereGlobalIn(Expression|string $column, mixed $values): static
    {
        return $this->whereGlobalIn($column, $values, 'or');
    }

    public function orWhereGlobalNotIn(Expression|string $column, mixed $values): static
    {
        return $this->whereGlobalIn($column, $values, 'or', true);
    }

    /**
     * @param  array<array-key, mixed>|Expression|string  $columns
     *
     * @psalm-suppress MixedAssignment Array entries are validated before use.
     */
    public function whereEmpty(
        array|Expression|string $columns,
        string $boolean = 'and',
        bool $not = false,
    ): static {
        $boolean = $this->normalizeClickHouseBoolean($boolean);
        $type = $not ? 'NotEmpty' : 'Empty';

        $columns = is_array($columns) ? $columns : [$columns];

        foreach ($columns as $column) {
            $column = $this->validateEmptyPredicateColumn($column);
            $this->wheres[] = compact('type', 'column', 'boolean');
        }

        if ($columns === []) {
            throw new InvalidArgumentException(
                'ClickHouse empty predicates require at least one column.'
            );
        }

        return $this;
    }

    /**
     * @param  array<array-key, mixed>|Expression|string  $columns
     */
    public function whereNotEmpty(
        array|Expression|string $columns,
        string $boolean = 'and',
    ): static {
        return $this->whereEmpty($columns, $boolean, true);
    }

    /** @param array<array-key, mixed>|Expression|string $columns */
    public function orWhereEmpty(array|Expression|string $columns): static
    {
        return $this->whereEmpty($columns, 'or');
    }

    /** @param array<array-key, mixed>|Expression|string $columns */
    public function orWhereNotEmpty(array|Expression|string $columns): static
    {
        return $this->whereEmpty($columns, 'or', true);
    }

    /**
     * @param  array<array-key, mixed>|Expression|string  $columns
     *
     * @psalm-suppress MixedAssignment Array entries are validated before use.
     */
    public function havingEmpty(
        array|Expression|string $columns,
        string $boolean = 'and',
        bool $not = false,
    ): static {
        $boolean = $this->normalizeClickHouseBoolean($boolean);
        $type = $not ? 'NotEmpty' : 'Empty';

        $columns = is_array($columns) ? $columns : [$columns];

        foreach ($columns as $column) {
            $column = $this->validateEmptyPredicateColumn($column);
            $this->havings[] = compact('type', 'column', 'boolean');
        }

        if ($columns === []) {
            throw new InvalidArgumentException(
                'ClickHouse empty predicates require at least one column.'
            );
        }

        return $this;
    }

    /**
     * @param  array<array-key, mixed>|Expression|string  $columns
     */
    public function havingNotEmpty(
        array|Expression|string $columns,
        string $boolean = 'and',
    ): static {
        return $this->havingEmpty($columns, $boolean, true);
    }

    /** @param array<array-key, mixed>|Expression|string $columns */
    public function orHavingEmpty(array|Expression|string $columns): static
    {
        return $this->havingEmpty($columns, 'or');
    }

    /** @param array<array-key, mixed>|Expression|string $columns */
    public function orHavingNotEmpty(array|Expression|string $columns): static
    {
        return $this->havingEmpty($columns, 'or', true);
    }

    private function normalizeClickHouseBoolean(string $boolean): string
    {
        $boolean = strtolower($boolean);

        if (! in_array($boolean, ['and', 'or'], true)) {
            throw new InvalidArgumentException(
                'ClickHouse predicate boolean must be "and" or "or".'
            );
        }

        return $boolean;
    }

    private function validateEmptyPredicateColumn(mixed $column): Expression|string
    {
        if ($column instanceof Expression) {
            return $column;
        }

        if (! is_string($column)) {
            throw new InvalidArgumentException(
                'ClickHouse empty predicate columns must be strings or expressions.'
            );
        }

        ClickHouseSql::nonEmpty($column, 'empty predicate column');

        return $column;
    }
}
