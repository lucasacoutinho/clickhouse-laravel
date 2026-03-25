<?php

namespace ClickHouse\Laravel\Tests\Unit\Schema;

use ClickHouse\Laravel\Schema\ClickHouseBlueprint;
use ClickHouse\Laravel\Schema\ClickHouseSchemaGrammar;
use ClickHouse\Laravel\Tests\TestCase;
use Illuminate\Support\Fluent;

class ClickHouseSchemaGrammarTest extends TestCase
{
    protected function grammar(): ClickHouseSchemaGrammar
    {
        return $this->clickhouse()->getSchemaGrammar();
    }

    protected function blueprint(string $table = 'events'): ClickHouseBlueprint
    {
        $schemaBuilder = $this->clickhouse()->getSchemaBuilder();
        $method = new \ReflectionMethod($schemaBuilder, 'createBlueprint');

        return $method->invoke($schemaBuilder, $table);
    }

    protected function compileCreate(ClickHouseBlueprint $blueprint): string
    {
        return $this->grammar()->compileCreate($blueprint, new Fluent(['name' => 'create']));
    }


    public function testCreateMergeTree(): void
    {
        $bp = $this->blueprint();
        $bp->uint64('id');
        $bp->string('name');
        $bp->engine('MergeTree()');
        $bp->orderBy('id');

        $sql = $this->compileCreate($bp);

        $this->assertStringContainsString('CREATE TABLE `events`', $sql);
        $this->assertStringContainsString('`id` UInt64', $sql);
        $this->assertStringContainsString('`name` String', $sql);
        $this->assertStringContainsString('ENGINE = MergeTree()', $sql);
        $this->assertStringContainsString('ORDER BY (`id`)', $sql);
    }

    public function testCreateDefaultEngine(): void
    {
        $bp = $this->blueprint();
        $bp->string('name');

        $sql = $this->compileCreate($bp);

        $this->assertStringContainsString('ENGINE = MergeTree()', $sql);
        $this->assertStringContainsString('ORDER BY tuple()', $sql);
    }

    public function testCreateReplacingMergeTree(): void
    {
        $bp = $this->blueprint();
        $bp->string('name');
        $bp->engine('ReplacingMergeTree(updated_at)');

        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('ENGINE = ReplacingMergeTree(updated_at)', $sql);
    }

    public function testCreateWithPartitionBy(): void
    {
        $bp = $this->blueprint();
        $bp->string('name');
        $bp->engine('MergeTree()');
        $bp->orderBy('name');
        $bp->partitionBy('toYYYYMM(created_at)');

        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('PARTITION BY toYYYYMM(created_at)', $sql);
    }

    public function testCreateWithPrimaryKey(): void
    {
        $bp = $this->blueprint();
        $bp->string('tenant_id');
        $bp->string('id');
        $bp->engine('MergeTree()');
        $bp->orderBy('tenant_id', 'id');
        $bp->primaryKey('tenant_id');

        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('PRIMARY KEY (`tenant_id`)', $sql);
    }

    public function testCreateWithSettings(): void
    {
        $bp = $this->blueprint();
        $bp->string('name');
        $bp->engine('MergeTree()');
        $bp->orderBy('name');
        $bp->setting('index_granularity', 8192);

        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('SETTINGS index_granularity = 8192', $sql);
    }

    public function testCreateMemoryEngineNoOrderBy(): void
    {
        $bp = $this->blueprint();
        $bp->string('name');
        $bp->engine('Memory');

        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('ENGINE = Memory', $sql);
        $this->assertStringNotContainsString('ORDER BY', $sql);
    }

    public function testCreateFullTable(): void
    {
        $bp = $this->blueprint();
        $bp->uint64('id');
        $bp->string('name');
        $bp->dateTime('created_at');
        $bp->engine('ReplacingMergeTree(created_at)');
        $bp->orderBy('id');
        $bp->partitionBy('toYYYYMM(created_at)');
        $bp->primaryKey('id');
        $bp->setting('index_granularity', 4096);

        $sql = $this->compileCreate($bp);

        $this->assertStringContainsString('ENGINE = ReplacingMergeTree(created_at)', $sql);
        $this->assertStringContainsString('ORDER BY (`id`)', $sql);
        $this->assertStringContainsString('PARTITION BY toYYYYMM(created_at)', $sql);
        $this->assertStringContainsString('PRIMARY KEY (`id`)', $sql);
        $this->assertStringContainsString('SETTINGS index_granularity = 4096', $sql);
    }


    public function testCompileDrop(): void
    {
        $bp = $this->blueprint();
        $sql = $this->grammar()->compileDrop($bp, new Fluent());
        $this->assertSame('DROP TABLE `events`', $sql);
    }

    public function testCompileDropIfExists(): void
    {
        $bp = $this->blueprint();
        $sql = $this->grammar()->compileDropIfExists($bp, new Fluent());
        $this->assertSame('DROP TABLE IF EXISTS `events`', $sql);
    }


    public function testCompileAdd(): void
    {
        $bp = $this->blueprint();
        $bp->string('email');
        $bp->integer('age');

        $sql = $this->grammar()->compileAdd($bp, new Fluent());

        $this->assertStringContainsString('ALTER TABLE `events` ADD COLUMN `email` String', $sql);
        $this->assertStringContainsString('ALTER TABLE `events` ADD COLUMN `age` Int32', $sql);
    }


    public function testCompileDropColumn(): void
    {
        $bp = $this->blueprint();
        $command = new Fluent(['columns' => ['email', 'age']]);
        $sql = $this->grammar()->compileDropColumn($bp, $command);

        $this->assertStringContainsString('ALTER TABLE `events`', $sql);
        $this->assertStringContainsString('DROP COLUMN `email`', $sql);
        $this->assertStringContainsString('DROP COLUMN `age`', $sql);
    }


    public function testCompileRename(): void
    {
        $bp = $this->blueprint('old_table');
        $command = new Fluent(['to' => 'new_table']);
        $sql = $this->grammar()->compileRename($bp, $command);

        $this->assertSame('RENAME TABLE `old_table` TO `new_table`', $sql);
    }


    public function testTypeString(): void
    {
        $bp = $this->blueprint();
        $bp->string('name');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`name` String', $sql);
    }

    public function testTypeInteger(): void
    {
        $bp = $this->blueprint();
        $bp->integer('count');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`count` Int32', $sql);
    }

    public function testTypeBigInteger(): void
    {
        $bp = $this->blueprint();
        $bp->bigInteger('big');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`big` Int64', $sql);
    }

    public function testTypeSmallInteger(): void
    {
        $bp = $this->blueprint();
        $bp->smallInteger('small');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`small` Int16', $sql);
    }

    public function testTypeTinyInteger(): void
    {
        $bp = $this->blueprint();
        $bp->tinyInteger('tiny');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`tiny` Int8', $sql);
    }

    public function testTypeMediumInteger(): void
    {
        $bp = $this->blueprint();
        $bp->mediumInteger('med');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`med` Int32', $sql);
    }

    public function testTypeFloat(): void
    {
        $bp = $this->blueprint();
        $bp->float('val');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`val` Float32', $sql);
    }

    public function testTypeDouble(): void
    {
        $bp = $this->blueprint();
        $bp->double('val');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`val` Float64', $sql);
    }

    public function testTypeDecimalDefaults(): void
    {
        $bp = $this->blueprint();
        $bp->decimal('amount');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('Decimal(', $sql);
    }

    public function testTypeDecimalCustom(): void
    {
        $bp = $this->blueprint();
        $bp->decimal('amount', 18, 4);
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('Decimal(18, 4)', $sql);
    }

    public function testTypeBoolean(): void
    {
        $bp = $this->blueprint();
        $bp->boolean('active');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`active` UInt8', $sql);
    }

    public function testTypeDate(): void
    {
        $bp = $this->blueprint();
        $bp->date('day');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`day` Date', $sql);
    }

    public function testTypeDateTime(): void
    {
        $bp = $this->blueprint();
        $bp->dateTime('ts');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`ts` DateTime', $sql);
    }

    public function testTypeDateTimeWithPrecision(): void
    {
        $bp = $this->blueprint();
        $bp->dateTime('ts', 3);
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('DateTime64(3)', $sql);
    }

    public function testTypeDateTimeTz(): void
    {
        $bp = $this->blueprint();
        $bp->dateTimeTz('ts');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString("DateTime('UTC')", $sql);
    }

    public function testTypeTimestamp(): void
    {
        $bp = $this->blueprint();
        $bp->timestamp('ts');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('DateTime', $sql);
    }

    public function testTypeText(): void
    {
        $bp = $this->blueprint();
        $bp->text('body');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`body` String', $sql);
    }

    public function testTypeMediumText(): void
    {
        $bp = $this->blueprint();
        $bp->mediumText('body');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`body` String', $sql);
    }

    public function testTypeLongText(): void
    {
        $bp = $this->blueprint();
        $bp->longText('body');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`body` String', $sql);
    }

    public function testTypeJson(): void
    {
        $bp = $this->blueprint();
        $bp->json('data');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`data` String', $sql);
    }

    public function testTypeBinary(): void
    {
        $bp = $this->blueprint();
        $bp->binary('blob');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`blob` String', $sql);
    }

    public function testTypeUuid(): void
    {
        $bp = $this->blueprint();
        $bp->uuid('uid');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`uid` UUID', $sql);
    }

    public function testTypeIpAddress(): void
    {
        $bp = $this->blueprint();
        $bp->ipAddress('ip');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`ip` IPv4', $sql);
    }

    public function testTypeChar(): void
    {
        $bp = $this->blueprint();
        $bp->char('code', 2);
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('FixedString(2)', $sql);
    }

    public function testTypeEnum(): void
    {
        $bp = $this->blueprint();
        $bp->enum('status', ['active', 'inactive']);
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString("Enum8('active' = 1, 'inactive' = 2)", $sql);
    }

    public function testTypeUnsignedTinyInteger(): void
    {
        $bp = $this->blueprint();
        $bp->unsignedTinyInteger('val');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`val` UInt8', $sql);
    }

    public function testTypeUnsignedSmallInteger(): void
    {
        $bp = $this->blueprint();
        $bp->unsignedSmallInteger('val');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`val` UInt16', $sql);
    }

    public function testTypeUnsignedInteger(): void
    {
        $bp = $this->blueprint();
        $bp->unsignedInteger('val');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`val` UInt32', $sql);
    }

    public function testTypeUnsignedBigInteger(): void
    {
        $bp = $this->blueprint();
        $bp->unsignedBigInteger('val');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`val` UInt64', $sql);
    }

    public function testTypeClickhouseRaw(): void
    {
        $bp = $this->blueprint();
        $bp->clickhouseType('tags', 'Array(String)');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`tags` Array(String)', $sql);
    }


    public function testNullableWrapsType(): void
    {
        $bp = $this->blueprint();
        $bp->string('name')->nullable();
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`name` Nullable(String)', $sql);
    }

    public function testNullableUuid(): void
    {
        $bp = $this->blueprint();
        $bp->uuid('uid')->nullable();
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('Nullable(UUID)', $sql);
    }


    public function testDefaultStringValue(): void
    {
        $bp = $this->blueprint();
        $bp->string('status')->default('active');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString("DEFAULT 'active'", $sql);
    }

    public function testDefaultIntegerValue(): void
    {
        $bp = $this->blueprint();
        $bp->integer('count')->default(0);
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('DEFAULT 0', $sql);
    }

    public function testDefaultBooleanTrue(): void
    {
        $bp = $this->blueprint();
        $bp->boolean('active')->default(true);
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('DEFAULT 1', $sql);
    }

    public function testDefaultBooleanFalse(): void
    {
        $bp = $this->blueprint();
        $bp->boolean('active')->default(false);
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('DEFAULT 0', $sql);
    }


    public function testWrapValueBackticks(): void
    {
        $bp = $this->blueprint();
        $bp->string('user_name');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`user_name`', $sql);
    }

    public function testCreateOnCluster(): void
    {
        $bp = $this->blueprint();
        $bp->uint64('id');
        $bp->engine('ReplicatedMergeTree()');
        $bp->orderBy('id');
        $bp->onCluster('my_cluster');

        $sql = $this->compileCreate($bp);

        $this->assertStringContainsString('ON CLUSTER my_cluster', $sql);
        $this->assertLessThan(strpos($sql, '('), strpos($sql, 'ON CLUSTER'));
    }

    public function testCreateWithoutOnCluster(): void
    {
        $bp = $this->blueprint();
        $bp->string('name');
        $sql = $this->compileCreate($bp);
        $this->assertStringNotContainsString('ON CLUSTER', $sql);
    }

    public function testColumnCompressionCodec(): void
    {
        $bp = $this->blueprint();
        $bp->uint64('id')->codec('ZSTD(3)');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('CODEC(ZSTD(3))', $sql);
    }

    public function testColumnCompressionCodecDelta(): void
    {
        $bp = $this->blueprint();
        $bp->float64('value')->codec('Delta, ZSTD');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('CODEC(Delta, ZSTD)', $sql);
    }

    public function testColumnCompressionCodecDoubleDelta(): void
    {
        $bp = $this->blueprint();
        $bp->dateTime('ts')->codec('DoubleDelta, LZ4');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('CODEC(DoubleDelta, LZ4)', $sql);
    }

    public function testColumnWithoutCodec(): void
    {
        $bp = $this->blueprint();
        $bp->string('name');
        $sql = $this->compileCreate($bp);
        $this->assertStringNotContainsString('CODEC', $sql);
    }

    public function testFullDistributedTable(): void
    {
        $bp = $this->blueprint('events');
        $bp->uint64('id')->codec('ZSTD(3)');
        $bp->string('name');
        $bp->dateTime('ts')->codec('DoubleDelta, LZ4');
        $bp->engine('ReplicatedMergeTree()');
        $bp->orderBy('id');
        $bp->partitionBy('toYYYYMM(ts)');
        $bp->onCluster('production');
        $bp->setting('index_granularity', 8192);

        $sql = $this->compileCreate($bp);

        $this->assertStringContainsString('CREATE TABLE `events` ON CLUSTER production', $sql);
        $this->assertStringContainsString('CODEC(ZSTD(3))', $sql);
        $this->assertStringContainsString('CODEC(DoubleDelta, LZ4)', $sql);
        $this->assertStringContainsString('ENGINE = ReplicatedMergeTree()', $sql);
        $this->assertStringContainsString('ORDER BY (`id`)', $sql);
        $this->assertStringContainsString('PARTITION BY toYYYYMM(ts)', $sql);
        $this->assertStringContainsString('SETTINGS index_granularity = 8192', $sql);
    }
}
