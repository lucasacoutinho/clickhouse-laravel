<?php

namespace ClickHouse\Laravel\Migrations;

use ClickHouse\Laravel\ClickHouseConnection;
use ClickHouse\Laravel\Schema\ClickHouseBlueprint;
use ClickHouse\Laravel\Schema\ClickHouseSchemaBuilder;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\DatabaseMigrationRepository as BaseRepository;
use InvalidArgumentException;

/**
 * Migration repository that uses ClickHouse-compatible DDL when its source
 * connection is ClickHouse and Laravel's normal repository everywhere else.
 *
 * @api
 *
 * @psalm-suppress PropertyNotSetInConstructor Laravel initializes repository source lazily.
 */
class DatabaseMigrationRepository extends BaseRepository
{
    public function __construct(ConnectionResolverInterface $resolver, string $table)
    {
        if (trim($table) === '') {
            throw new InvalidArgumentException(
                'The Laravel migration repository table must be a non-empty string.'
            );
        }

        parent::__construct($resolver, $table);
    }

    public function createRepository(): void
    {
        $schema = $this->getConnection()->getSchemaBuilder();

        if (! $schema instanceof ClickHouseSchemaBuilder) {
            parent::createRepository();

            return;
        }

        $schema->create($this->migrationTable(), function (ClickHouseBlueprint $table): void {
            $table->string('migration');
            $table->integer('batch');
            $table->engine('MergeTree()');
            $table->orderBy(['batch', 'migration']);
        });
    }

    public function delete($migration): void
    {
        $connection = $this->getConnection();

        if (! $connection instanceof ClickHouseConnection) {
            parent::delete($migration);

            return;
        }

        $name = $migration->migration;

        if ($name === '') {
            throw new InvalidArgumentException(
                'A migration repository record must contain a migration name.'
            );
        }

        $connection
            ->table($this->migrationTable())
            ->where('migration', $name)
            ->mutationsSync()
            ->deleteMutation();
    }

    private function migrationTable(): string
    {
        return $this->table;
    }
}
