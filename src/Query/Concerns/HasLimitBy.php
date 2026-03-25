<?php

namespace ClickHouse\Laravel\Query\Concerns;

trait HasLimitBy
{
    public ?int $limitByCount = null;
    public array $limitByColumns = [];

    /**
     * LIMIT BY — return top N rows per group.
     *
     * Usage:
     *   ->limitBy(1, 'user_id')              // LIMIT 1 BY `user_id`
     *   ->limitBy(5, 'category', 'status')   // LIMIT 5 BY `category`, `status`
     */
    public function limitBy(int $count, string ...$columns): static
    {
        $this->limitByCount = $count;
        $this->limitByColumns = $columns;
        return $this;
    }
}
