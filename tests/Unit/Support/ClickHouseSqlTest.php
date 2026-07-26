<?php

namespace ClickHouse\Laravel\Tests\Unit\Support;

use ClickHouse\Laravel\Support\ClickHouseSql;
use ClickHouse\Laravel\Tests\TestCase;

class ClickHouseSqlTest extends TestCase
{
    public function test_quote_string_escapes_quotes_backslashes_and_control_bytes(): void
    {
        $this->assertSame(
            "'quote\\' slash\\\\ bell\\x07 delete\\x7F'",
            ClickHouseSql::quoteString("quote' slash\\ bell\x07 delete\x7F"),
        );
    }

    public function test_literal_preserves_float_precision(): void
    {
        $value = 0.12345678901234566;

        $this->assertSame(
            json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            ClickHouseSql::literal($value),
        );
    }
}
