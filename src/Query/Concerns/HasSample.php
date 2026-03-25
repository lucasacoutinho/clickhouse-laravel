<?php

namespace ClickHouse\Laravel\Query\Concerns;

trait HasSample
{
    public ?string $sampleClause = null;

    /**
     * SAMPLE — approximate query on a fraction of data.
     */
    public function sample(float|int $value): static
    {
        $this->sampleClause = (string) $value;
        return $this;
    }
}
