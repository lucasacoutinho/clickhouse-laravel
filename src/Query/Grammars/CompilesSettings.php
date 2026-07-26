<?php

namespace ClickHouse\Laravel\Query\Grammars;

use ClickHouse\Laravel\Support\ClickHouseSql;
use Illuminate\Database\Query\Builder;

trait CompilesSettings
{
    /** @param array<string, bool|float|int|string|null> $settings */
    protected function compileSettings(Builder $query, array $settings): string
    {
        if (empty($settings)) {
            return '';
        }

        $parts = [];
        foreach ($settings as $key => $value) {
            $name = ClickHouseSql::settingName($key);
            $parts[] = $name.' = '.ClickHouseSql::literal($value, "setting {$name}");
        }

        return 'SETTINGS '.implode(', ', $parts);
    }
}
