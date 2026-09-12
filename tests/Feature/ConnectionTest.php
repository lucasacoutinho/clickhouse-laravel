<?php

namespace ClickHouse\Laravel\Tests\Feature;

use ClickHouse\Laravel\ClickHouseConnection;
use ClickHouse\Laravel\Exceptions\TransactionsNotSupportedException;
use ClickHouse\Laravel\Query\ClickHouseQueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
class ConnectionTest extends FeatureTestCase
{
    /** @var array<string, mixed> */
    private array $defaultConnectionConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->app['config']->get('database.connections.clickhouse');
        if (! is_array($config)) {
            throw new \LogicException('The ClickHouse test connection must be configured as an array.');
        }

        /** @var array<string, mixed> $config */
        $this->defaultConnectionConfig = $config;
    }

    protected function tearDown(): void
    {
        DB::purge('clickhouse');
        $this->app['config']->set(
            'database.connections.clickhouse',
            $this->defaultConnectionConfig,
        );

        parent::tearDown();
    }

    /** @param array<string, mixed> $overrides */
    protected function connectionWithConfig(array $overrides): ClickHouseConnection
    {
        DB::purge('clickhouse');
        $this->app['config']->set(
            'database.connections.clickhouse',
            array_merge($this->defaultConnectionConfig, $overrides),
        );

        return DB::connection('clickhouse');
    }

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

    public function test_row_buffer_limit_rejects_large_results_and_recovers(): void
    {
        $connection = $this->connectionWithConfig(['max_buffered_rows' => 2]);

        try {
            $connection->select('SELECT number FROM numbers(3)');
            $this->fail('A result over max_buffered_rows should be rejected.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('max_buffered_rows', $exception->getMessage());
        }

        $first = $connection->selectOne('SELECT 42 AS expected');
        $second = $connection->selectOne('SELECT 43 AS expected');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertEquals(42, $first->expected);
        $this->assertEquals(43, $second->expected);
    }

    public function test_byte_buffer_limit_rejects_large_results_and_recovers(): void
    {
        $connection = $this->connectionWithConfig(['max_buffered_bytes' => 256]);

        try {
            $connection->select("SELECT repeat('x', 1000) AS payload");
            $this->fail('A result over max_buffered_bytes should be rejected.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('max_buffered_bytes', $exception->getMessage());
        }

        $result = $connection->selectOne('SELECT 42 AS expected');

        $this->assertNotNull($result);
        $this->assertEquals(42, $result->expected);
    }

    public function test_small_results_fit_configured_buffer_limits(): void
    {
        $connection = $this->connectionWithConfig([
            'max_buffered_rows' => 2,
            'max_buffered_bytes' => 4096,
        ]);

        $results = $connection->select('SELECT number FROM numbers(2)');

        $this->assertCount(2, $results);
        $this->assertEquals(0, $results[0]->number);
        $this->assertEquals(1, $results[1]->number);
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
