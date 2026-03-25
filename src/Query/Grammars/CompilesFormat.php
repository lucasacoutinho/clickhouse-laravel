<?php

namespace ClickHouse\Laravel\Query\Grammars;

use Illuminate\Database\Query\Builder;

trait CompilesFormat
{
    protected function compileFormat(Builder $query, string $format): string
    {
        return "FORMAT {$format}";
    }
}
