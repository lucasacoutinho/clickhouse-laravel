<?php

namespace ClickHouse\Laravel\Query\Concerns;

trait HasLimitBy
{
    public ?int $limitByCount = null;

    /** @var list<string> */
    public array $limitByColumns = [];

    /**
     * LIMIT BY returns the top N rows per group.
     *
     * Usage:
     *   ->limitBy(1, 'user_id')              // LIMIT 1 BY `user_id`
     *   ->limitBy(5, 'category', 'status')   // LIMIT 5 BY `category`, `status`
     */
    /**
     * @param  array<array-key, mixed>|string  ...$columns
     *
     * @psalm-suppress MixedAssignment Array entries are validated in the loop.
     */
    public function limitBy(int $count, array|string ...$columns): static
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('ClickHouse LIMIT BY count must be at least 1.');
        }

        $normalized = [];

        foreach ($columns as $column) {
            foreach ((array) $column as $name) {
                if (! is_string($name) || trim($name) === '') {
                    throw new \InvalidArgumentException(
                        'ClickHouse LIMIT BY columns must be non-empty strings.'
                    );
                }

                $normalized[] = $name;
            }
        }

        if ($normalized === []) {
            throw new \InvalidArgumentException('ClickHouse LIMIT BY requires at least one column.');
        }

        $this->limitByCount = $count;
        $this->limitByColumns = $normalized;

        return $this;
    }
}
