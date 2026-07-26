<?php

namespace ClickHouse\Laravel\Tests\Unit\Schema;

use ClickHouse\Laravel\Schema\ClickHouseBlueprint;
use ClickHouse\Laravel\Schema\ClickHouseColumnDefinition;
use ClickHouse\Laravel\Tests\TestCase;

class ClickHouseBlueprintTest extends TestCase
{
    protected function blueprint(string $table = 'events'): ClickHouseBlueprint
    {
        $schemaBuilder = $this->clickhouse()->getSchemaBuilder();
        $method = new \ReflectionMethod($schemaBuilder, 'createBlueprint');

        return $method->invoke($schemaBuilder, $table);
    }

    public function test_order_by_sets_columns(): void
    {
        $bp = $this->blueprint();
        $bp->orderBy('created_at', 'user_id');
        $this->assertSame(['created_at', 'user_id'], $bp->orderByColumns);
    }

    public function test_order_by_accepts_an_array(): void
    {
        $bp = $this->blueprint()->orderBy(['created_at', 'user_id']);

        $this->assertSame(['created_at', 'user_id'], $bp->orderByColumns);
    }

    public function test_order_by_requires_a_column(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->blueprint()->orderBy();
    }

    public function test_partition_by_sets_expression(): void
    {
        $bp = $this->blueprint();
        $bp->partitionBy('toYYYYMM(created_at)');
        $this->assertSame('toYYYYMM(created_at)', $bp->partitionByExpression);
    }

    public function test_primary_key_sets_columns(): void
    {
        $bp = $this->blueprint();
        $bp->primaryKey('tenant_id', 'id');
        $this->assertSame(['tenant_id', 'id'], $bp->primaryKeyColumns);
    }

    public function test_primary_key_accepts_an_array(): void
    {
        $bp = $this->blueprint()->primaryKey(['tenant_id', 'id']);

        $this->assertSame(['tenant_id', 'id'], $bp->primaryKeyColumns);
    }

    public function test_primary_key_requires_a_column(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->blueprint()->primaryKey();
    }

    public function test_setting_adds_to_array(): void
    {
        $bp = $this->blueprint();
        $bp->setting('index_granularity', 8192);
        $this->assertSame(['index_granularity' => 8192], $bp->tableSettings);
    }

    public function test_multiple_settings(): void
    {
        $bp = $this->blueprint();
        $bp->setting('index_granularity', 8192);
        $bp->setting('enable_mixed_granularity_parts', 1);
        $this->assertCount(2, $bp->tableSettings);
    }

    public function test_engine_is_fluent(): void
    {
        $bp = $this->blueprint();
        $result = $bp->engine('MergeTree()');
        $this->assertSame($bp, $result);
    }

    public function test_order_by_is_fluent(): void
    {
        $bp = $this->blueprint();
        $result = $bp->orderBy('id');
        $this->assertSame($bp, $result);
    }

    public function test_uint8(): void
    {
        $bp = $this->blueprint();
        $col = $bp->uint8('val');
        $this->assertSame('unsignedTinyInteger', $col->get('type'));
    }

    public function test_uint16(): void
    {
        $bp = $this->blueprint();
        $col = $bp->uint16('val');
        $this->assertSame('unsignedSmallInteger', $col->get('type'));
    }

    public function test_uint32(): void
    {
        $bp = $this->blueprint();
        $col = $bp->uint32('val');
        $this->assertSame('unsignedInteger', $col->get('type'));
    }

    public function test_uint64(): void
    {
        $bp = $this->blueprint();
        $col = $bp->uint64('val');
        $this->assertSame('unsignedBigInteger', $col->get('type'));
    }

    public function test_int8(): void
    {
        $bp = $this->blueprint();
        $col = $bp->int8('val');
        $this->assertSame('tinyInteger', $col->get('type'));
    }

    public function test_int16(): void
    {
        $bp = $this->blueprint();
        $col = $bp->int16('val');
        $this->assertSame('smallInteger', $col->get('type'));
    }

    public function test_int32(): void
    {
        $bp = $this->blueprint();
        $col = $bp->int32('val');
        $this->assertSame('integer', $col->get('type'));
    }

    public function test_int64(): void
    {
        $bp = $this->blueprint();
        $col = $bp->int64('val');
        $this->assertSame('bigInteger', $col->get('type'));
    }

    public function test_float32(): void
    {
        $bp = $this->blueprint();
        $col = $bp->float32('val');
        $this->assertSame('float', $col->get('type'));
    }

    public function test_float64(): void
    {
        $bp = $this->blueprint();
        $col = $bp->float64('val');
        $this->assertSame('double', $col->get('type'));
    }

    public function test_fixed_string(): void
    {
        $bp = $this->blueprint();
        $col = $bp->fixedString('code', 10);
        $this->assertSame('char', $col->get('type'));
        $this->assertSame(10, $col->get('length'));
    }

    public function test_ipv4(): void
    {
        $bp = $this->blueprint();
        $col = $bp->ipv4('ip');
        $this->assertSame('ipAddress', $col->get('type'));
    }

    public function test_clickhouse_type_raw(): void
    {
        $bp = $this->blueprint();
        $col = $bp->clickhouseType('tags', 'Array(String)');
        $this->assertSame('clickhouseRaw', $col->get('type'));
        $this->assertSame('Array(String)', $col->get('clickhouse_type'));
    }

    public function test_array_of(): void
    {
        $bp = $this->blueprint();
        $col = $bp->arrayOf('tags', 'String');
        $this->assertSame('Array(String)', $col->get('clickhouse_type'));
    }

    public function test_array_is_an_alias_for_array_of(): void
    {
        $col = $this->blueprint()->array('tags', 'String');

        $this->assertSame('Array(String)', $col->get('clickhouse_type'));
    }

    public function test_columns_use_clickhouse_aware_definitions(): void
    {
        $column = $this->blueprint()
            ->string('country')
            ->lowCardinality()
            ->codec('ZSTD(3)')
            ->ttl('created_at + INTERVAL 30 DAY');

        $this->assertInstanceOf(ClickHouseColumnDefinition::class, $column);
        $this->assertTrue((bool) $column->get('low_cardinality'));
        $this->assertSame('ZSTD(3)', $column->get('codec'));
        $this->assertSame('created_at + INTERVAL 30 DAY', $column->get('ttl'));
    }

    public function test_map_of(): void
    {
        $bp = $this->blueprint();
        $col = $bp->mapOf('meta', 'String', 'String');
        $this->assertSame('Map(String, String)', $col->get('clickhouse_type'));
    }

    public function test_tuple_of(): void
    {
        $bp = $this->blueprint();
        $col = $bp->tupleOf('coords', 'Float64', 'Float64');
        $this->assertSame('Tuple(Float64, Float64)', $col->get('clickhouse_type'));
    }

    public function test_low_cardinality(): void
    {
        $bp = $this->blueprint();
        $col = $bp->lowCardinality('country');
        $this->assertSame('LowCardinality(String)', $col->get('clickhouse_type'));
    }

    public function test_low_cardinality_custom_inner_type(): void
    {
        $bp = $this->blueprint();
        $col = $bp->lowCardinality('status', 'FixedString(2)');
        $this->assertSame('LowCardinality(FixedString(2))', $col->get('clickhouse_type'));
    }

    public function test_low_cardinality_string_uses_native_wrapper(): void
    {
        $col = $this->blueprint()->lowCardinalityString('country');

        $this->assertSame('clickhouseRaw', $col->get('type'));
        $this->assertSame('LowCardinality(String)', $col->get('clickhouse_type'));
        $this->assertFalse((bool) $col->get('change'));
    }

    public function test_date32(): void
    {
        $this->assertSame('date32', $this->blueprint()->date32('day')->get('type'));
    }

    public function test_native_json(): void
    {
        $column = $this->blueprint()->nativeJson('payload');

        $this->assertSame('JSON', $column->get('clickhouse_type'));
    }

    public function test_time64(): void
    {
        $column = $this->blueprint()->time64('time', 6);

        $this->assertSame('time64', $column->get('type'));
        $this->assertSame(6, $column->get('precision'));
    }

    public function test_sample_by_and_ttl(): void
    {
        $blueprint = $this->blueprint()
            ->sampleBy('intHash32(id)')
            ->ttl('created_at + INTERVAL 30 DAY')
            ->tableComment("Events for Lucas' app");

        $this->assertSame('intHash32(id)', $blueprint->sampleByExpression);
        $this->assertSame('created_at + INTERVAL 30 DAY', $blueprint->ttlExpression);
        $this->assertSame("Events for Lucas' app", $blueprint->tableComment);
    }

    public function test_unsafe_engine_expression_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->blueprint()->engine('MergeTree(); DROP TABLE users');
    }

    public function test_hash_comment_in_engine_expression_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->blueprint()->engine("MergeTree() # ignored\nDROP TABLE users");
    }

    public function test_unsafe_setting_name_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->blueprint()->setting('index_granularity; DROP TABLE users', 8192);
    }

    public function test_invalid_temporal_precision_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->blueprint()->dateTime64('created_at', 10);
    }

    public function test_skip_index_command(): void
    {
        $command = $this->blueprint()->skipIndex('idx_status', 'status', 'set(100)', 2);

        $this->assertSame('addSkipIndex', $command->name);
        $this->assertSame('idx_status', $command->index);
        $this->assertSame(2, $command->granularity);
    }

    public function test_projection_command(): void
    {
        $command = $this->blueprint()->projection('by_user', 'SELECT user_id, count() GROUP BY user_id');

        $this->assertSame('addProjection', $command->name);
        $this->assertSame('by_user', $command->projection);
    }

    public function test_ipv6(): void
    {
        $bp = $this->blueprint();
        $col = $bp->ipv6('ip');
        $this->assertSame('IPv6', $col->get('clickhouse_type'));
    }

    public function test_int128(): void
    {
        $bp = $this->blueprint();
        $col = $bp->int128('big');
        $this->assertSame('Int128', $col->get('clickhouse_type'));
    }

    public function test_uint128(): void
    {
        $bp = $this->blueprint();
        $col = $bp->uint128('big');
        $this->assertSame('UInt128', $col->get('clickhouse_type'));
    }

    public function test_id_returns_uint64(): void
    {
        $bp = $this->blueprint();
        $col = $bp->id();
        $this->assertSame('unsignedBigInteger', $col->get('type'));
    }

    public function test_foreign_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->blueprint()->foreign('user_id');
    }

    public function test_index_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->blueprint()->index('email');
    }

    public function test_unique_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->blueprint()->unique('email');
    }
}
