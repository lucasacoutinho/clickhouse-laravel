<?php

namespace ClickHouse\Laravel;

use ClickHouse\Laravel\Query\ClickHouseQueryBuilder;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Contracts\Concurrency\Driver;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Execute independent ClickHouse selects through Laravel's concurrency drivers.
 *
 * Only SQL, bindings, and the connection name cross the process boundary. Each
 * worker resolves a fresh native PDO connection inside its own Laravel process.
 *
 * @api
 */
final class Parallel
{
    /**
     * @param  array<array-key, mixed>  $queries
     * @return array<array-key, Collection<array-key, mixed>>
     *
     * @psalm-suppress MixedAssignment Query input and callback results are validated at runtime.
     */
    public static function get(
        array $queries,
        ?string $driver = null,
        ?Driver $executor = null,
    ): array {
        if ($queries === []) {
            return [];
        }

        if ($driver !== null && $executor !== null) {
            throw new InvalidArgumentException(
                'Choose either a Laravel concurrency driver name or a driver instance, not both.'
            );
        }

        /** @var array<array-key, ClickHouseQueryBuilder|EloquentBuilder<Model>> $prepared */
        $prepared = [];
        $tasks = [];

        foreach ($queries as $key => $query) {
            if ($query instanceof EloquentBuilder) {
                $query = $query->applyScopes();
                $baseQuery = $query->toBase();
                $connection = $query->getConnection();
            } elseif ($query instanceof ClickHouseQueryBuilder) {
                $baseQuery = $query;
                $connection = $query->getConnection();
            } else {
                throw new InvalidArgumentException(
                    'Parallel ClickHouse queries must use the ClickHouse query builder or Eloquent.'
                );
            }

            if (! $connection instanceof ClickHouseConnection) {
                throw new InvalidArgumentException(
                    'Parallel queries must use a ClickHouse connection.'
                );
            }

            $sql = $baseQuery->toSql();
            $bindings = $baseQuery->getBindings();
            $connectionName = $connection->getName();

            if (! is_string($connectionName) || ! filled($connectionName)) {
                throw new InvalidArgumentException(
                    'Parallel queries require a named Laravel database connection.'
                );
            }

            $prepared[$key] = $query;
            $tasks[$key] = static fn (): array => DB::connection($connectionName)
                ->select($sql, $bindings);
        }

        $executor ??= self::resolveExecutor($driver);
        $results = $executor->run($tasks);
        /** @var array<array-key, Collection<array-key, mixed>> $collections */
        $collections = [];

        foreach ($results as $key => $rows) {
            if (! array_key_exists($key, $prepared) || ! is_array($rows)) {
                throw new \UnexpectedValueException(
                    'Laravel concurrency returned an invalid ClickHouse query result.'
                );
            }

            $query = $prepared[$key];

            if ($query instanceof EloquentBuilder) {
                $models = $query->hydrate($rows)->all();

                if ($models !== []) {
                    $models = $query->eagerLoadRelations($models);
                }

                $collection = $query->applyAfterQueryCallbacks(
                    $query->getModel()->newCollection($models),
                );

                if (! $collection instanceof Collection) {
                    throw new \UnexpectedValueException(
                        'An Eloquent after-query callback returned an invalid collection.'
                    );
                }

                $collections[$key] = $collection;

                continue;
            }

            $collection = $query->applyAfterQueryCallbacks(
                new Collection($rows),
            );

            if (! $collection instanceof Collection) {
                throw new \UnexpectedValueException(
                    'A query-builder after-query callback returned an invalid collection.'
                );
            }

            $collections[$key] = $collection;
        }

        return $collections;
    }

    private static function resolveExecutor(?string $driver): Driver
    {
        $manager = app(ConcurrencyManager::class);

        $executor = $manager->driver($driver);

        if (! $executor instanceof Driver) {
            throw new \LogicException('Laravel concurrency driver is invalid.');
        }

        return $executor;
    }
}
