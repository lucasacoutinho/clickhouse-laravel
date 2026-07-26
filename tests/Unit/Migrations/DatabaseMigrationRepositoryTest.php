<?php

namespace ClickHouse\Laravel\Tests\Unit\Migrations;

use ClickHouse\Laravel\Migrations\DatabaseMigrationRepository;
use ClickHouse\Laravel\Tests\TestCase;
use InvalidArgumentException;

class DatabaseMigrationRepositoryTest extends TestCase
{
    public function test_service_provider_replaces_laravels_repository(): void
    {
        $this->assertInstanceOf(
            DatabaseMigrationRepository::class,
            $this->app->make('migration.repository'),
        );
    }

    public function test_repository_table_must_be_a_non_empty_string(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DatabaseMigrationRepository($this->app->make('db'), ' ');
    }
}
