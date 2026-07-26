<?php

namespace ClickHouse\Laravel\Eloquent;

use ClickHouse\Laravel\Query\ClickHouseQueryBuilder;
use Illuminate\Database\Eloquent\Builder as BaseBuilder;
use LogicException;

/**
 * @template TModel of ClickHouseModel
 *
 * @extends BaseBuilder<TModel>
 *
 * @api
 *
 * @psalm-suppress PropertyNotSetInConstructor Laravel initializes inherited Eloquent state.
 */
class Builder extends BaseBuilder
{
    public function delete(
        ?bool $lightweight = null,
        mixed $partition = null,
    ): mixed {
        if ($lightweight === null && $partition === null) {
            return parent::delete();
        }

        return $this->clickHouseQuery()->delete(
            lightweight: $lightweight,
            partition: $partition,
        );
    }

    public function forceDelete(
        ?bool $lightweight = null,
        mixed $partition = null,
    ): mixed {
        return $this->clickHouseQuery()->delete(
            lightweight: $lightweight,
            partition: $partition,
        );
    }

    public function deleteLightweight(mixed $partition = null): int
    {
        return $this->clickHouseQuery()->deleteLightweight($partition);
    }

    public function deleteMutation(mixed $partition = null): int
    {
        return $this->clickHouseQuery()->deleteMutation($partition);
    }

    private function clickHouseQuery(): ClickHouseQueryBuilder
    {
        $query = $this->toBase();

        if (! $query instanceof ClickHouseQueryBuilder) {
            throw new LogicException(
                'ClickHouse Eloquent deletes require ClickHouseQueryBuilder.'
            );
        }

        return $query;
    }
}
