<?php

namespace ClickHouse\Laravel\Query\Concerns;

trait HasArrayJoin
{
    public array $arrayJoins = [];

    /**
     * ARRAY JOIN — explode an array/map column into rows.
     *
     * Usage:
     *   ->arrayJoin('tags')                         // ARRAY JOIN `tags`
     *   ->arrayJoin('tags', 'tag')                  // ARRAY JOIN `tags` AS `tag`
     *   ->arrayJoin('items', 'item', 'left')        // LEFT ARRAY JOIN `items` AS `item`
     */
    public function arrayJoin(string $column, ?string $alias = null, string $type = 'inner'): static
    {
        $this->arrayJoins[] = [
            'column' => $column,
            'alias'  => $alias,
            'type'   => $type,
        ];

        return $this;
    }

    /**
     * LEFT ARRAY JOIN — keeps rows with empty arrays.
     */
    public function leftArrayJoin(string $column, ?string $alias = null): static
    {
        return $this->arrayJoin($column, $alias, 'left');
    }
}
