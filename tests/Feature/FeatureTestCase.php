<?php

namespace ClickHouse\Laravel\Tests\Feature;

use ClickHouse\Laravel\ClickHouseServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * Base class for feature tests that boot a full Laravel application.
 *
 * Requires a running ClickHouse instance (see docker-compose.yml).
 * Skip with: phpunit --testsuite Unit
 */
abstract class FeatureTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ClickHouseServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.connections.clickhouse', [
            'driver'   => 'clickhouse',
            'host'     => env('CLICKHOUSE_HOST', 'localhost'),
            'port'     => env('CLICKHOUSE_PORT', 9000),
            'database' => env('CLICKHOUSE_DATABASE', 'default'),
            'username' => env('CLICKHOUSE_USERNAME', 'default'),
            'password' => env('CLICKHOUSE_PASSWORD', ''),
            'timeout'  => 5,
            'retries'  => 0,
            'settings' => [],
            'options'  => ['final' => false],
        ]);
    }
}
