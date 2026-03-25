<?php

namespace ClickHouse\Laravel\Tests\Unit\Schema;

use ClickHouse\Laravel\Schema\ClickHouseBlueprint;
use ClickHouse\Laravel\Tests\TestCase;

class ClickHouseBlueprintTest extends TestCase
{
    protected function blueprint(string $table = 'events'): ClickHouseBlueprint
    {
        return new ClickHouseBlueprint($table);
    }

 Table properties ---

    public function testOrderBySetsColumns(): void
    {
        $bp = $this->blueprint();
        $bp->orderBy('created_at', 'user_id');
        $this->assertSame(['created_at', 'user_id'], $bp->orderByColumns);
    }

    public function testPartitionBySetsExpression(): void
    {
        $bp = $this->blueprint();
        $bp->partitionBy('toYYYYMM(created_at)');
        $this->assertSame('toYYYYMM(created_at)', $bp->partitionByExpression);
    }

    public function testPrimaryKeySetsColumns(): void
    {
        $bp = $this->blueprint();
        $bp->primaryKey('tenant_id', 'id');
        $this->assertSame(['tenant_id', 'id'], $bp->primaryKeyColumns);
    }

    public function testSettingAddsToArray(): void
    {
        $bp = $this->blueprint();
        $bp->setting('index_granularity', 8192);
        $this->assertSame(['index_granularity' => 8192], $bp->tableSettings);
    }

    public function testMultipleSettings(): void
    {
        $bp = $this->blueprint();
        $bp->setting('index_granularity', 8192);
        $bp->setting('enable_mixed_granularity_parts', 1);
        $this->assertCount(2, $bp->tableSettings);
    }

    public function testEngineIsFluent(): void
    {
        $bp = $this->blueprint();
        $result = $bp->engine('MergeTree()');
        $this->assertSame($bp, $result);
    }

    public function testOrderByIsFluent(): void
    {
        $bp = $this->blueprint();
        $result = $bp->orderBy('id');
        $this->assertSame($bp, $result);
    }

 Column types map to correct internal types ---

    public function testUint8(): void
    {
        $bp = $this->blueprint();
        $col = $bp->uint8('val');
        $this->assertSame('unsignedTinyInteger', $col->get('type'));
    }

    public function testUint16(): void
    {
        $bp = $this->blueprint();
        $col = $bp->uint16('val');
        $this->assertSame('unsignedSmallInteger', $col->get('type'));
    }

    public function testUint32(): void
    {
        $bp = $this->blueprint();
        $col = $bp->uint32('val');
        $this->assertSame('unsignedInteger', $col->get('type'));
    }

    public function testUint64(): void
    {
        $bp = $this->blueprint();
        $col = $bp->uint64('val');
        $this->assertSame('unsignedBigInteger', $col->get('type'));
    }

    public function testInt8(): void
    {
        $bp = $this->blueprint();
        $col = $bp->int8('val');
        $this->assertSame('tinyInteger', $col->get('type'));
    }

    public function testInt16(): void
    {
        $bp = $this->blueprint();
        $col = $bp->int16('val');
        $this->assertSame('smallInteger', $col->get('type'));
    }

    public function testInt32(): void
    {
        $bp = $this->blueprint();
        $col = $bp->int32('val');
        $this->assertSame('integer', $col->get('type'));
    }

    public function testInt64(): void
    {
        $bp = $this->blueprint();
        $col = $bp->int64('val');
        $this->assertSame('bigInteger', $col->get('type'));
    }

    public function testFloat32(): void
    {
        $bp = $this->blueprint();
        $col = $bp->float32('val');
        $this->assertSame('float', $col->get('type'));
    }

    public function testFloat64(): void
    {
        $bp = $this->blueprint();
        $col = $bp->float64('val');
        $this->assertSame('double', $col->get('type'));
    }

    public function testFixedString(): void
    {
        $bp = $this->blueprint();
        $col = $bp->fixedString('code', 10);
        $this->assertSame('char', $col->get('type'));
        $this->assertSame(10, $col->get('length'));
    }

    public function testIpv4(): void
    {
        $bp = $this->blueprint();
        $col = $bp->ipv4('ip');
        $this->assertSame('ipAddress', $col->get('type'));
    }

 Compound types via clickhouseType ---

    public function testClickhouseTypeRaw(): void
    {
        $bp = $this->blueprint();
        $col = $bp->clickhouseType('tags', 'Array(String)');
        $this->assertSame('clickhouseRaw', $col->get('type'));
        $this->assertSame('Array(String)', $col->get('clickhouse_type'));
    }

    public function testArrayOf(): void
    {
        $bp = $this->blueprint();
        $col = $bp->arrayOf('tags', 'String');
        $this->assertSame('Array(String)', $col->get('clickhouse_type'));
    }

    public function testMapOf(): void
    {
        $bp = $this->blueprint();
        $col = $bp->mapOf('meta', 'String', 'String');
        $this->assertSame('Map(String, String)', $col->get('clickhouse_type'));
    }

    public function testTupleOf(): void
    {
        $bp = $this->blueprint();
        $col = $bp->tupleOf('coords', 'Float64', 'Float64');
        $this->assertSame('Tuple(Float64, Float64)', $col->get('clickhouse_type'));
    }

    public function testLowCardinality(): void
    {
        $bp = $this->blueprint();
        $col = $bp->lowCardinality('country');
        $this->assertSame('LowCardinality(String)', $col->get('clickhouse_type'));
    }

    public function testLowCardinalityCustomInnerType(): void
    {
        $bp = $this->blueprint();
        $col = $bp->lowCardinality('status', 'FixedString(2)');
        $this->assertSame('LowCardinality(FixedString(2))', $col->get('clickhouse_type'));
    }

    public function testIpv6(): void
    {
        $bp = $this->blueprint();
        $col = $bp->ipv6('ip');
        $this->assertSame('IPv6', $col->get('clickhouse_type'));
    }

    public function testInt128(): void
    {
        $bp = $this->blueprint();
        $col = $bp->int128('big');
        $this->assertSame('Int128', $col->get('clickhouse_type'));
    }

    public function testUint128(): void
    {
        $bp = $this->blueprint();
        $col = $bp->uint128('big');
        $this->assertSame('UInt128', $col->get('clickhouse_type'));
    }

 ID override ---

    public function testIdReturnsUint64(): void
    {
        $bp = $this->blueprint();
        $col = $bp->id();
        $this->assertSame('unsignedBigInteger', $col->get('type'));
    }

 Unsupported operations ---

    public function testForeignThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->blueprint()->foreign('user_id');
    }

    public function testIndexThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->blueprint()->index('email');
    }

    public function testUniqueThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->blueprint()->unique('email');
    }
}
