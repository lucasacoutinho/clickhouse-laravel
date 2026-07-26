<?php

namespace ClickHouse\Laravel\Tests;

use ClickHouse\Laravel\ClickHouseConnection;
use ClickHouse\Laravel\ClickHouseServiceProvider;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [ClickHouseServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.connections.clickhouse', [
            'driver' => 'clickhouse',
            'host' => env('CLICKHOUSE_HOST', 'localhost'),
            'port' => env('CLICKHOUSE_PORT', 9000),
            'database' => env('CLICKHOUSE_DATABASE', 'default'),
            'username' => env('CLICKHOUSE_USERNAME', 'default'),
            'password' => env('CLICKHOUSE_PASSWORD', ''),
            'timeout' => 5,
            'retries' => 0,
            'settings' => [],
            'options' => ['final' => false],
        ]);
    }

    protected function clickhouse(): ClickHouseConnection
    {
        return DB::connection('clickhouse');
    }
}
