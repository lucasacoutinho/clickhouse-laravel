<?php

namespace ClickHouse\Laravel\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
class SchemaTest extends FeatureTestCase
{
    protected function tearDown(): void
    {
        DB::connection('clickhouse')->statement('DROP TABLE IF EXISTS _test_schema');
        DB::connection('clickhouse')->statement('DROP TABLE IF EXISTS _test_codec');
        DB::connection('clickhouse')->statement('DROP TABLE IF EXISTS _test_rename');
        parent::tearDown();
    }

    public function test_create_and_drop_table(): void
    {
        $schema = DB::connection('clickhouse')->getSchemaBuilder();

        $schema->create('_test_schema', function ($table) {
            $table->uint64('id');
            $table->string('name');
            $table->dateTime('created_at');
            $table->engine('MergeTree()');
            $table->orderBy('id');
        });

        $this->assertTrue($schema->hasTable('_test_schema'));

        $schema->drop('_test_schema');

        $this->assertFalse($schema->hasTable('_test_schema'));
    }

    public function test_create_with_partition_and_settings(): void
    {
        $schema = DB::connection('clickhouse')->getSchemaBuilder();

        $schema->create('_test_schema', function ($table) {
            $table->uint64('id');
            $table->string('name');
            $table->dateTime('ts');
            $table->engine('MergeTree()');
            $table->orderBy('id');
            $table->partitionBy('toYYYYMM(ts)');
            $table->setting('index_granularity', 4096);
        });

        $this->assertTrue($schema->hasTable('_test_schema'));

        $columns = $schema->getColumnListing('_test_schema');
        $this->assertContains('id', $columns);
        $this->assertContains('name', $columns);
        $this->assertContains('ts', $columns);
    }

    public function test_create_with_sampling_ttl_settings_and_comment(): void
    {
        $connection = DB::connection('clickhouse');
        $schema = $connection->getSchemaBuilder();

        $schema->create('_test_schema', function ($table) {
            $table->uint64('id');
            $table->dateTime('expires_at');
            $table->engine('MergeTree()');
            $table->orderBy('id');
            $table->sampleBy('id');
            $table->ttl('expires_at + INTERVAL 1 DAY');
            $table->setting('index_granularity', 4096);
            $table->tableComment("Laravel's ClickHouse table");
        });

        $metadata = $connection
            ->table('system.tables')
            ->where('database', 'default')
            ->where('name', '_test_schema')
            ->first();

        $this->assertNotNull($metadata);
        $this->assertSame('id', $metadata->sampling_key);
        $this->assertSame("Laravel's ClickHouse table", $metadata->comment);
        $this->assertStringContainsString(
            'expires_at + toIntervalDay(1)',
            $metadata->create_table_query,
        );
    }

    public function test_create_with_compression_codecs(): void
    {
        $schema = DB::connection('clickhouse')->getSchemaBuilder();

        $schema->create('_test_codec', function ($table) {
            $table->uint64('id')->codec('ZSTD(3)');
            $table->float64('value')->codec('Delta, ZSTD');
            $table->dateTime('ts')->codec('DoubleDelta, LZ4');
            $table->engine('MergeTree()');
            $table->orderBy('id');
        });

        $this->assertTrue($schema->hasTable('_test_codec'));
    }

    public function test_create_with_all_column_types(): void
    {
        $schema = DB::connection('clickhouse')->getSchemaBuilder();

        $schema->create('_test_schema', function ($table) {
            $table->uint8('u8');
            $table->uint16('u16');
            $table->uint32('u32');
            $table->uint64('u64');
            $table->int8('i8');
            $table->int16('i16');
            $table->int32('i32');
            $table->int64('i64');
            $table->float32('f32');
            $table->float64('f64');
            $table->string('str');
            $table->boolean('flag');
            $table->date('day');
            $table->dateTime('ts');
            $table->uuid('uid');
            $table->ipv4('ip');
            $table->decimal('amount', 18, 4);
            $table->arrayOf('tags', 'String');
            $table->mapOf('meta', 'String', 'String');
            $table->lowCardinality('country');
            $table->engine('MergeTree()');
            $table->orderBy('u64');
        });

        $columns = $schema->getColumnListing('_test_schema');
        $this->assertContains('u64', $columns);
        $this->assertContains('tags', $columns);
        $this->assertContains('meta', $columns);
        $this->assertContains('country', $columns);
    }

    public function test_native_bool_json_date32_time_and_time64_types(): void
    {
        $connection = DB::connection('clickhouse');
        $schema = $connection->getSchemaBuilder();

        $schema->create('_test_schema', function ($table) {
            $table->uint64('id');
            $table->boolean('flag');
            $table->json('payload');
            $table->nativeJson('native_payload');
            $table->date32('historical_day');
            $table->time('clock_time');
            $table->time64('precise_time', 6);
            $table->string('country')->nullable()->lowCardinality();
            $table->engine('MergeTree()');
            $table->orderBy('id');
        });

        $types = $connection
            ->table('system.columns')
            ->where('database', 'default')
            ->where('table', '_test_schema')
            ->pluck('type', 'name')
            ->all();

        $this->assertSame('Bool', $types['flag']);
        $this->assertSame('String', $types['payload']);
        $this->assertSame('JSON', $types['native_payload']);
        $this->assertSame('Date32', $types['historical_day']);
        $this->assertSame('Time', $types['clock_time']);
        $this->assertSame('Time64(6)', $types['precise_time']);
        $this->assertSame('LowCardinality(Nullable(String))', $types['country']);
    }

    public function test_add_column(): void
    {
        $conn = DB::connection('clickhouse');
        $schema = $conn->getSchemaBuilder();

        $conn->statement('CREATE TABLE _test_schema (id UInt64) ENGINE = MergeTree() ORDER BY id');

        $schema->table('_test_schema', function ($table) {
            $table->string('email');
        });

        $columns = $schema->getColumnListing('_test_schema');
        $this->assertContains('email', $columns);
    }

    public function test_drop_column(): void
    {
        $conn = DB::connection('clickhouse');
        $schema = $conn->getSchemaBuilder();

        $conn->statement('CREATE TABLE _test_schema (id UInt64, name String) ENGINE = MergeTree() ORDER BY id');

        $schema->table('_test_schema', function ($table) {
            $table->dropColumn('name');
        });

        $columns = $schema->getColumnListing('_test_schema');
        $this->assertNotContains('name', $columns);
    }

    public function test_rename_change_skip_index_and_projection(): void
    {
        $connection = DB::connection('clickhouse');
        $schema = $connection->getSchemaBuilder();

        $connection->statement(
            'CREATE TABLE _test_schema (id UInt64, name String, score Float64) '
            .'ENGINE = MergeTree() ORDER BY id'
        );

        $schema->table('_test_schema', function ($table) {
            $table->renameColumn('name', 'label');
        });
        $schema->table('_test_schema', function ($table) {
            $table->lowCardinalityString('label')->change();
        });
        $schema->table('_test_schema', function ($table) {
            $table->skipIndex('idx_score', 'score', 'minmax');
            $table->projection('by_id', 'SELECT id, sum(score) GROUP BY id');
        });

        $types = $connection
            ->table('system.columns')
            ->where('database', 'default')
            ->where('table', '_test_schema')
            ->pluck('type', 'name')
            ->all();

        $this->assertArrayNotHasKey('name', $types);
        $this->assertSame('LowCardinality(String)', $types['label']);
        $this->assertSame(
            1,
            $connection->table('system.data_skipping_indices')
                ->where('database', 'default')
                ->where('table', '_test_schema')
                ->where('name', 'idx_score')
                ->count(),
        );
        $this->assertSame(
            1,
            $connection->table('system.projections')
                ->where('database', 'default')
                ->where('table', '_test_schema')
                ->where('name', 'by_id')
                ->count(),
        );

        $schema->table('_test_schema', function ($table) {
            $table->dropSkipIndex('idx_score');
            $table->dropProjection('by_id');
        });

        $this->assertSame(
            0,
            $connection->table('system.data_skipping_indices')
                ->where('database', 'default')
                ->where('table', '_test_schema')
                ->where('name', 'idx_score')
                ->count(),
        );
        $this->assertSame(
            0,
            $connection->table('system.projections')
                ->where('database', 'default')
                ->where('table', '_test_schema')
                ->where('name', 'by_id')
                ->count(),
        );
    }

    public function test_get_tables(): void
    {
        $conn = DB::connection('clickhouse');
        $conn->statement('CREATE TABLE _test_schema (id UInt64) ENGINE = MergeTree() ORDER BY id');

        $tables = $conn->getSchemaBuilder()->getTables();
        $this->assertIsArray($tables);
        $this->assertNotEmpty($tables);

        $table = collect($tables)->firstWhere('name', '_test_schema');

        $this->assertNotNull($table);
        $this->assertSame('default', $table['schema']);
        $this->assertSame('default._test_schema', $table['schema_qualified_name']);
        $this->assertNull($table['size']);
        $this->assertNull($table['comment']);
        $this->assertNull($table['collation']);
        $this->assertNull($table['engine']);
    }

    public function test_foreign_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        DB::connection('clickhouse')->getSchemaBuilder()->create('_test_schema', function ($table) {
            $table->uint64('id');
            $table->foreign('id');
        });
    }

    public function test_unique_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        DB::connection('clickhouse')->getSchemaBuilder()->create('_test_schema', function ($table) {
            $table->uint64('id');
            $table->unique('id');
        });
    }
}
