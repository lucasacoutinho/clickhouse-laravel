<?php

namespace ClickHouse\Laravel;

use ClickHouse\Laravel\Connectors\ClickHouseConnector;
use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;

class ClickHouseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('db.connector.clickhouse', function () {
            return new ClickHouseConnector();
        });

        Connection::resolverFor('clickhouse', function ($connection, $database, $prefix, $config) {
            return new ClickHouseConnection($connection, $database, $prefix, $config);
        });
    }
}
