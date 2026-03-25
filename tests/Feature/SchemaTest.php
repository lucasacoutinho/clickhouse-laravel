<?php

namespace ClickHouse\Laravel\Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * @group integration
 */
class SchemaTest extends FeatureTestCase
{
    protected function tearDown(): void
    {
        DB::connection('clickhouse')->statement('DROP TABLE IF EXISTS _test_schema');
        DB::connection('clickhouse')->statement('DROP TABLE IF EXISTS _test_codec');
        DB::connection('clickhouse')->statement('DROP TABLE IF EXISTS _test_rename');
        parent::tearDown();
    }

    public function testCreateAndDropTable(): void
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

    public function testCreateWithPartitionAndSettings(): void
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

    public function testCreateWithCompressionCodecs(): void
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

    public function testCreateWithAllColumnTypes(): void
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

    public function testAddColumn(): void
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

    public function testDropColumn(): void
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

    public function testGetTables(): void
    {
        $conn = DB::connection('clickhouse');
        $conn->statement('CREATE TABLE _test_schema (id UInt64) ENGINE = MergeTree() ORDER BY id');

        $tables = $conn->getSchemaBuilder()->getTables();
        $this->assertIsArray($tables);
        $this->assertNotEmpty($tables);
    }

    public function testForeignThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        DB::connection('clickhouse')->getSchemaBuilder()->create('_test_schema', function ($table) {
            $table->uint64('id');
            $table->foreign('id');
        });
    }

    public function testUniqueThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        DB::connection('clickhouse')->getSchemaBuilder()->create('_test_schema', function ($table) {
            $table->uint64('id');
            $table->unique('id');
        });
    }
}
