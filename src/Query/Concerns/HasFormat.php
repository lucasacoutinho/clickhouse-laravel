<?php

namespace ClickHouse\Laravel\Query\Concerns;

use ClickHouse\Laravel\Support\ClickHouseSql;

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
        $this->outputFormat = ClickHouseSql::token($format, 'output format');

        return $this;
    }
}
