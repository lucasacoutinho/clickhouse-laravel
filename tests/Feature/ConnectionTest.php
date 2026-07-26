<?php

namespace ClickHouse\Laravel\Tests\Feature;

use ClickHouse\Laravel\ClickHouseConnection;
use ClickHouse\Laravel\Exceptions\TransactionsNotSupportedException;
use ClickHouse\Laravel\Query\ClickHouseQueryBuilder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
class ConnectionTest extends FeatureTestCase
{
    public function test_connection_resolves(): void
    {
        $this->assertInstanceOf(ClickHouseConnection::class, DB::connection('clickhouse'));
    }

    public function test_driver_name(): void
    {
        $this->assertSame('clickhouse', DB::connection('clickhouse')->getDriverName());
    }

    public function test_query_returns_click_house_builder(): void
    {
        $this->assertInstanceOf(ClickHouseQueryBuilder::class, DB::connection('clickhouse')->query());
    }

    public function test_table_returns_click_house_builder(): void
    {
        $this->assertInstanceOf(ClickHouseQueryBuilder::class, DB::connection('clickhouse')->table('system.one'));
    }

    public function test_select_one(): void
    {
        $result = DB::connection('clickhouse')->select('SELECT 1 AS value');
        $this->assertNotEmpty($result);
        $this->assertEquals(1, $result[0]->value);
    }

    public function test_select_expression(): void
    {
        $result = DB::connection('clickhouse')->select('SELECT toTypeName(1) AS type_name');
        $this->assertSame('UInt8', $result[0]->type_name);
    }

    public function test_transactions_fail_loudly_by_default(): void
    {
        $this->expectException(TransactionsNotSupportedException::class);
        DB::connection('clickhouse')->transaction(fn () => 'ok');
    }

    public function test_transaction_level_always_zero(): void
    {
        $this->assertSame(0, DB::connection('clickhouse')->transactionLevel());
    }
}
