<?php

namespace ClickHouse\Laravel\Tests\Unit\Support;

use ClickHouse\Laravel\Support\ClickHouseValue;
use ClickHouse\Laravel\Tests\TestCase;

class ClickHouseValueTest extends TestCase
{
    public function test_array_literal_supports_nested_values_and_escaping(): void
    {
        $this->assertSame(
            "['Lucas\\' value', 42, 1, NULL, ['nested']]",
            ClickHouseValue::array(["Lucas' value", 42, true, null, ['nested']]),
        );
    }

    public function test_map_literal_supports_associative_values(): void
    {
        $this->assertSame(
            "{'name': 'Lucas', 'active': 1}",
            ClickHouseValue::map(['name' => 'Lucas', 'active' => true]),
        );
    }

    public function test_tuple_literal(): void
    {
        $this->assertSame(
            "(1, 'click', 3.5)",
            ClickHouseValue::tuple(1, 'click', 3.5),
        );
    }

    public function test_tuple_requires_at_least_one_value(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClickHouseValue::tuple();
    }

    public function test_json_uses_native_json_encoding(): void
    {
        $this->assertSame(
            '{"name":"Lucas","roles":["admin"]}',
            ClickHouseValue::json(['name' => 'Lucas', 'roles' => ['admin']]),
        );
    }

    public function test_array_rejects_associative_top_level_values(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClickHouseValue::array(['name' => 'Lucas']);
    }
}
