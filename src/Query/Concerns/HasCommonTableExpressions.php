<?php

namespace ClickHouse\Laravel\Query\Concerns;

use ClickHouse\Laravel\Support\ClickHouseSql;
use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

trait HasCommonTableExpressions
{
    /**
     * @var list<array{
     *     identifier: string,
     *     expression: mixed,
     *     subquery: bool,
     *     recursive: bool,
     *     raw: bool
     * }>
     */
    public array $withQueries = [];

    /**
     * Add a parameter-bound common scalar expression, or a query-builder CTE.
     */
    public function withQuery(
        mixed $expression,
        string $identifier,
        bool $subquery = false,
    ): static {
        if ($this->isQueryable($expression)) {
            return $this->withQuerySub($expression, $identifier);
        }

        if ($subquery) {
            throw new InvalidArgumentException(
                'Raw ClickHouse CTE subqueries must use withQueryRaw() or withQuerySub().'
            );
        }

        $this->validateCteIdentifier($identifier);

        if ($expression instanceof Expression) {
            $this->withQueries[] = [
                'identifier' => $identifier,
                'expression' => $expression,
                'subquery' => false,
                'recursive' => false,
                'raw' => true,
            ];

            return $this;
        }

        ClickHouseSql::literal($expression, 'WITH expression');
        $this->withQueries[] = [
            'identifier' => $identifier,
            'expression' => $expression,
            'subquery' => false,
            'recursive' => false,
            'raw' => false,
        ];
        $this->addBinding($expression, 'with');

        return $this;
    }

    /**
     * Add an explicitly trusted raw common scalar expression or CTE subquery.
     *
     * @param  array<array-key, mixed>  $bindings
     */
    public function withQueryRaw(
        string $expression,
        string $identifier,
        array $bindings = [],
        bool $subquery = false,
        bool $recursive = false,
    ): static {
        $this->validateCteIdentifier($identifier);
        ClickHouseSql::safeExpression($expression, 'WITH expression');

        if ($recursive && ! $subquery) {
            throw new InvalidArgumentException(
                'A recursive ClickHouse WITH expression must be a subquery.'
            );
        }

        $this->withQueries[] = compact(
            'identifier',
            'expression',
            'subquery',
            'recursive',
        ) + ['raw' => true];
        $this->addBinding($bindings, 'with');

        return $this;
    }

    /**
     * Add a query builder or closure as a CTE.
     */
    public function withQuerySub(
        mixed $query,
        string $identifier,
        bool $recursive = false,
    ): static {
        if (! $this->isQueryable($query)) {
            throw new InvalidArgumentException(
                'A ClickHouse CTE must be a query builder, Eloquent builder, relation, or closure.'
            );
        }

        if ($query instanceof Relation) {
            [$sql, $bindings] = $this->parseSub($query);
        } else {
            /** @var Closure|Builder|EloquentBuilder<Model> $query */
            [$sql, $bindings] = $this->createSub($query);
        }

        if (! is_string($sql) || ! is_array($bindings)) {
            throw new InvalidArgumentException('Could not compile the ClickHouse CTE subquery.');
        }

        return $this->withQueryRaw(
            $sql,
            $identifier,
            $bindings,
            subquery: true,
            recursive: $recursive,
        );
    }

    public function withQueryRecursive(mixed $query, string $identifier): static
    {
        return $this->withQuerySub($query, $identifier, recursive: true);
    }

    public function withRecursiveQuery(mixed $query, string $identifier): static
    {
        return $this->withQueryRecursive($query, $identifier);
    }

    private function validateCteIdentifier(string $identifier): void
    {
        ClickHouseSql::quoteIdentifier($identifier, 'WITH identifier');
    }
}
