<?php

namespace ClickHouse\Laravel\Tests\Feature;

use ClickHouse\Laravel\Migrations\DatabaseMigrationRepository;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
class MigrationRepositoryTest extends FeatureTestCase
{
    private const TABLE = '_test_laravel_migrations';

    protected function setUp(): void
    {
        parent::setUp();

        DB::connection('clickhouse')->statement('DROP TABLE IF EXISTS '.self::TABLE);
    }

    protected function tearDown(): void
    {
        DB::connection('clickhouse')->statement('DROP TABLE IF EXISTS '.self::TABLE);

        parent::tearDown();
    }

    public function test_clickhouse_migration_repository_lifecycle(): void
    {
        $repository = new DatabaseMigrationRepository(
            $this->app->make('db'),
            self::TABLE,
        );
        $repository->setSource('clickhouse');
        $repository->createRepository();

        $this->assertTrue($repository->repositoryExists());

        $repository->log('2026_01_01_000000_create_events', 1);
        $repository->log('2026_01_02_000000_add_event_tags', 2);

        $this->assertSame([
            '2026_01_01_000000_create_events',
            '2026_01_02_000000_add_event_tags',
        ], $repository->getRan());
        $this->assertSame(3, $repository->getNextBatchNumber());

        $repository->delete((object) [
            'migration' => '2026_01_02_000000_add_event_tags',
            'batch' => 2,
        ]);

        $this->assertSame(
            ['2026_01_01_000000_create_events'],
            $repository->getRan(),
        );
    }

    public function test_laravel_migrate_install_uses_clickhouse_repository(): void
    {
        $this->app['config']->set('database.migrations', [
            'table' => self::TABLE,
        ]);

        $this->artisan('migrate:install', [
            '--database' => 'clickhouse',
        ])->assertSuccessful();

        $this->assertTrue(
            DB::connection('clickhouse')->getSchemaBuilder()->hasTable(self::TABLE),
        );
    }
}
