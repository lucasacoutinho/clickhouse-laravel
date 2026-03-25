<?php

namespace ClickHouse\Laravel\Query\Grammars;

use Illuminate\Database\Query\Builder;

trait CompilesSettings
{
    protected function compileSettings(Builder $query, array $settings): string
    {
        if (empty($settings)) {
            return '';
        }

        $parts = [];
        foreach ($settings as $key => $value) {
            $formatted = match (true) {
                is_bool($value) => $value ? '1' : '0',
                is_string($value) => "'" . addslashes($value) . "'",
                default => $value,
            };
            $parts[] = "{$key} = {$formatted}";
        }

        return 'SETTINGS ' . implode(', ', $parts);
    }
}
