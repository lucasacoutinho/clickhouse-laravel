<?php

namespace ClickHouse\Laravel\Query\Concerns;

trait HasArrayJoin
{
    /**
     * @var list<array{column: string, alias: string|null, type: 'inner'|'left'}>
     */
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
        if (trim($column) === '') {
            throw new \InvalidArgumentException(
                'ClickHouse ARRAY JOIN column must be a non-empty string.'
            );
        }

        if ($alias !== null && trim($alias) === '') {
            throw new \InvalidArgumentException(
                'ClickHouse ARRAY JOIN alias must be a non-empty string.'
            );
        }

        $type = strtolower($type);

        if (! in_array($type, ['inner', 'left'], true)) {
            throw new \InvalidArgumentException('ClickHouse ARRAY JOIN type must be "inner" or "left".');
        }

        $this->arrayJoins[] = [
            'column' => $column,
            'alias' => $alias,
            'type' => $type,
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
