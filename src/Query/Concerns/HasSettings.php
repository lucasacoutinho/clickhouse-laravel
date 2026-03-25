<?php

namespace ClickHouse\Laravel\Query\Concerns;

trait HasSettings
{
    public array $querySettings = [];

    /**
     * SETTINGS — per-query ClickHouse settings appended to the SQL.
     *
     * Usage:
     *   ->settings(['max_threads' => 4, 'max_memory_usage' => 10000000000])
     */
    public function settings(array $settings): static
    {
        $this->querySettings = array_merge($this->querySettings, $settings);
        return $this;
    }

    /**
     * Enable async insert — ClickHouse buffers inserts and flushes in batches.
     * Dramatically improves throughput for high-frequency small inserts.
     *
     * @param bool $wait Wait for the async insert to be flushed before returning.
     */
    public function async(bool $wait = false): static
    {
        return $this->settings([
            'async_insert' => 1,
            'wait_for_async_insert' => $wait ? 1 : 0,
        ]);
    }
}
