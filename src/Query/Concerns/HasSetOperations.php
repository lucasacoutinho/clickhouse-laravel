<?php

namespace ClickHouse\Laravel\Query\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

trait HasSetOperations
{
    public function unionDistinct(mixed $query): static
    {
        return $this->addClickHouseSetOperation($query, 'UNION DISTINCT');
    }

    public function intersect(mixed $query, bool $all = false): static
    {
        return $this->addClickHouseSetOperation(
            $query,
            $all ? 'INTERSECT ALL' : 'INTERSECT DISTINCT',
        );
    }

    public function intersectAll(mixed $query): static
    {
        return $this->intersect($query, true);
    }

    public function intersectDistinct(mixed $query): static
    {
        return $this->intersect($query);
    }

    public function except(mixed $query, bool $all = false): static
    {
        return $this->addClickHouseSetOperation(
            $query,
            $all ? 'EXCEPT ALL' : 'EXCEPT DISTINCT',
        );
    }

    public function exceptAll(mixed $query): static
    {
        return $this->except($query, true);
    }

    public function exceptDistinct(mixed $query): static
    {
        return $this->except($query);
    }

    private function addClickHouseSetOperation(mixed $query, string $operator): static
    {
        if ($query instanceof Closure) {
            $query($query = $this->newQuery());
        }

        if (! $query instanceof Builder && ! $query instanceof EloquentBuilder) {
            throw new InvalidArgumentException(
                'ClickHouse set operations require a query builder, Eloquent builder, or closure.'
            );
        }

        $this->unions[] = [
            'query' => $query,
            'all' => str_ends_with($operator, ' ALL'),
            'operator' => $operator,
        ];
        $this->addBinding($query->getBindings(), 'union');

        return $this;
    }
}
