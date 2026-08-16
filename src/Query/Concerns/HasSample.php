<?php

namespace ClickHouse\Laravel\Query\Concerns;

use ClickHouse\Laravel\Support\ClickHouseSql;

trait HasSample
{
    public ?string $sampleClause = null;

    public ?string $sampleOffsetClause = null;

    /**
     * SAMPLE runs an approximate query on a fraction of the data.
     */
    public function sample(float|int $value, float|int|null $offset = null): static
    {
        $this->sampleClause = $this->normalizeSampleValue($value);

        if ($offset !== null) {
            if ($value > 1) {
                throw new \InvalidArgumentException(
                    'ClickHouse SAMPLE OFFSET requires a fractional SAMPLE value between 0 and 1.'
                );
            }

            if (
                $offset < 0
                || $offset > 1
                || (is_float($offset) && ! is_finite($offset))
            ) {
                throw new \InvalidArgumentException(
                    'ClickHouse SAMPLE offset must be between 0 and 1.'
                );
            }

            $this->sampleOffsetClause = ClickHouseSql::literal($offset, 'SAMPLE offset');
        } else {
            $this->sampleOffsetClause = null;
        }

        return $this;
    }

    private function normalizeSampleValue(float|int $value): string
    {
        if (
            $value <= 0
            || (is_float($value) && ! is_finite($value))
            || (is_float($value) && $value > 1 && floor($value) !== $value)
        ) {
            throw new \InvalidArgumentException(
                'ClickHouse SAMPLE must be a fraction greater than 0 and at most 1, or a positive integer.'
            );
        }

        return ClickHouseSql::literal($value, 'SAMPLE value');
    }
}
