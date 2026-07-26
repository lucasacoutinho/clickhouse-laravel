<?php

namespace ClickHouse\Laravel;

use ClickHouse\Laravel\Connectors\ClickHouseConnector;
use ClickHouse\Laravel\Migrations\DatabaseMigrationRepository;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use PDO;

/** @api */
class ClickHouseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/clickhouse.php', 'clickhouse');

        $this->app->singleton('db.connector.clickhouse', function () {
            return new ClickHouseConnector;
        });

        Connection::resolverFor('clickhouse', function (
            Closure|PDO $connection,
            string $database,
            string $prefix,
            array $config,
        ): ClickHouseConnection {
            /** @var array<string, mixed> $config */
            /** @psalm-suppress MixedArgumentTypeCoercion Laravel's resolver supplies a PDO factory. */
            return new ClickHouseConnection($connection, $database, $prefix, $config);
        });

        $config = $this->app->make('config');
        if (! $config instanceof Repository) {
            throw new \LogicException('Laravel config repository is not available.');
        }

        $connectionName = $config->get('clickhouse.connection', 'clickhouse');
        if (! is_string($connectionName) || blank($connectionName)) {
            throw new InvalidArgumentException(
                'ClickHouse connection name must be a non-empty string.'
            );
        }

        if (
            $config->get('clickhouse.register_connection', true)
            && ! $config->has("database.connections.{$connectionName}")
        ) {
            $connection = $config->get('clickhouse');
            if (! is_array($connection)) {
                throw new InvalidArgumentException(
                    'ClickHouse configuration must be an array.'
                );
            }

            unset($connection['connection'], $connection['register_connection']);

            if (blank($connection['cluster'] ?? null)) {
                unset($connection['cluster']);
            }

            $config->set("database.connections.{$connectionName}", $connection);
        }

        $this->app->beforeResolving('migration.repository', function (): void {
            $this->app->singleton(
                'migration.repository',
                function (): DatabaseMigrationRepository {
                    $config = $this->app->make('config');
                    $resolver = $this->app->make('db');

                    if (! $config instanceof Repository) {
                        throw new \LogicException(
                            'Laravel config repository is not available.'
                        );
                    }

                    if (! $resolver instanceof ConnectionResolverInterface) {
                        throw new \LogicException(
                            'Laravel database connection resolver is not available.'
                        );
                    }

                    /** @psalm-suppress MixedAssignment Laravel configuration values are validated below. */
                    $migrations = $config->get('database.migrations', 'migrations');
                    $table = is_array($migrations)
                        ? ($migrations['table'] ?? 'migrations')
                        : $migrations;

                    if (! is_string($table) || blank($table)) {
                        throw new InvalidArgumentException(
                            'Laravel database.migrations must define a non-empty table name.'
                        );
                    }

                    return new DatabaseMigrationRepository($resolver, $table);
                },
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/clickhouse.php' => config_path('clickhouse.php'),
        ], 'clickhouse-config');
    }
}
