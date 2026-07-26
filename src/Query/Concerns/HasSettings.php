<?php

namespace ClickHouse\Laravel\Query\Concerns;

use ClickHouse\Laravel\Support\ClickHouseSql;

trait HasSettings
{
    /** @var array<string, bool|float|int|string|null> */
    public array $querySettings = [];

    /**
     * SETTINGS — per-query ClickHouse settings appended to the SQL.
     *
     * Usage:
     *   ->settings(['max_threads' => 4, 'max_memory_usage' => 10000000000])
     *   ->settings('max_threads', 4)
     */
    /**
     * @param  array<array-key, mixed>|string  $settings
     *
     * @psalm-suppress MixedAssignment Setting values are validated in the loop.
     */
    public function settings(array|string $settings, mixed $value = null): static
    {
        if (is_string($settings)) {
            if (func_num_args() < 2) {
                throw new \InvalidArgumentException(
                    'ClickHouse settings requires a value when called with a setting name.'
                );
            }

            $settings = [$settings => $value];
        }

        $normalized = [];

        foreach ($settings as $name => $value) {
            if (! is_string($name)) {
                throw new \InvalidArgumentException('ClickHouse setting names must be strings.');
            }

            if (
                $value !== null
                && ! is_bool($value)
                && ! is_float($value)
                && ! is_int($value)
                && ! is_string($value)
            ) {
                throw new \InvalidArgumentException(
                    "ClickHouse setting {$name} must be null, bool, int, float, or string."
                );
            }

            ClickHouseSql::settingName($name);
            ClickHouseSql::literal($value, "setting {$name}");
            $normalized[$name] = $value;
        }

        $this->querySettings = array_merge($this->querySettings, $normalized);

        return $this;
    }

    /**
     * Enable async insert — ClickHouse buffers inserts and flushes in batches.
     * Dramatically improves throughput for high-frequency small inserts.
     *
     * @param  bool  $wait  Wait for the async insert to be flushed before returning.
     */
    public function async(bool $wait = false): static
    {
        return $this->settings([
            'async_insert' => 1,
            'wait_for_async_insert' => $wait ? 1 : 0,
        ]);
    }

    /**
     * Wait for ALTER UPDATE/DELETE mutations to finish.
     *
     * 0 = asynchronous, 1 = wait on the current replica, 2 = wait on all replicas.
     */
    public function mutationsSync(int $mode = 1): static
    {
        if (! in_array($mode, [0, 1, 2], true)) {
            throw new \InvalidArgumentException('ClickHouse mutations_sync must be 0, 1, or 2.');
        }

        return $this->settings(['mutations_sync' => $mode]);
    }
}
