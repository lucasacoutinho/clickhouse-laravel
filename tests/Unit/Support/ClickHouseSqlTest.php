<?php

namespace ClickHouse\Laravel\Tests\Unit\Support;

use ClickHouse\Laravel\Support\ClickHouseSql;
use ClickHouse\Laravel\Tests\TestCase;

class ClickHouseSqlTest extends TestCase
{
    public function test_quote_identifier_escapes_backslashes_and_backticks(): void
    {
        $this->assertSame('`events`', ClickHouseSql::quoteIdentifier('events'));
        $this->assertSame('`a\\`b`', ClickHouseSql::quoteIdentifier('a`b'));
        $this->assertSame('`c\\\\d`', ClickHouseSql::quoteIdentifier('c\\d'));
        $this->assertSame('`name\\\\`', ClickHouseSql::quoteIdentifier('name\\'));
        $this->assertSame('`x\\\\\\`y`', ClickHouseSql::quoteIdentifier('x\\`y'));
        $this->assertSame(
            '`x\\\\\\` FROM (SELECT secret "x\\\\x60" FROM poc_secrets) --`',
            ClickHouseSql::wrapIdentifier(
                'x\\` FROM (SELECT secret "x\\x60" FROM poc_secrets) --',
            ),
        );
    }

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
