<?php

namespace ClickHouse\Laravel\Query\Concerns;

trait HasFormat
{
    public ?string $outputFormat = null;

    /**
     * FORMAT — specify the output format for the query.
     *
     * Usage:
     *   ->format('JSONEachRow')
     *   ->format('CSV')
     *   ->format('TabSeparated')
     */
    public function format(string $format): static
    {
        $this->outputFormat = $format;
        return $this;
    }
}
