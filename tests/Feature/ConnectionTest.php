<?php

namespace ClickHouse\Laravel\Tests\Feature;

use ClickHouse\Laravel\ClickHouseConnection;
use ClickHouse\Laravel\Query\ClickHouseQueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * @group integration
 */
class ConnectionTest extends FeatureTestCase
{
    public function testConnectionResolves(): void
    {
        $this->assertInstanceOf(ClickHouseConnection::class, DB::connection('clickhouse'));
    }

    public function testDriverName(): void
    {
        $this->assertSame('clickhouse', DB::connection('clickhouse')->getDriverName());
    }

    public function testQueryReturnsClickHouseBuilder(): void
    {
        $this->assertInstanceOf(ClickHouseQueryBuilder::class, DB::connection('clickhouse')->query());
    }

    public function testTableReturnsClickHouseBuilder(): void
    {
        $this->assertInstanceOf(ClickHouseQueryBuilder::class, DB::connection('clickhouse')->table('system.one'));
    }

    public function testSelectOne(): void
    {
        $result = DB::connection('clickhouse')->select('SELECT 1 AS value');
        $this->assertNotEmpty($result);
        $this->assertEquals(1, $result[0]->value);
    }

    public function testSelectExpression(): void
    {
        $result = DB::connection('clickhouse')->select("SELECT toTypeName(1) AS type_name");
        $this->assertSame('UInt8', $result[0]->type_name);
    }

    public function testTransactionRunsCallback(): void
    {
        $result = DB::connection('clickhouse')->transaction(fn() => 'ok');
        $this->assertSame('ok', $result);
    }

    public function testTransactionLevelAlwaysZero(): void
    {
        $this->assertSame(0, DB::connection('clickhouse')->transactionLevel());
    }
}
