<?php

namespace ClickHouse\Laravel\Query\Concerns;

trait HasFinal
{
    public bool $useFinal = false;

    /**
     * FINAL — deduplicate rows in ReplacingMergeTree.
     */
    public function final(bool $enable = true): static
    {
        $this->useFinal = $enable;
        return $this;
    }
}
