<?php

namespace ClickHouse\Laravel\Tests\Unit\Schema;

use ClickHouse\Laravel\Schema\ClickHouseBlueprint;
use ClickHouse\Laravel\Schema\ClickHouseSchemaGrammar;
use ClickHouse\Laravel\Tests\TestCase;
use Illuminate\Database\Query\Expression;
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

    public function test_create_merge_tree(): void
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

    public function test_create_default_engine(): void
    {
        $bp = $this->blueprint();
        $bp->string('name');

        $sql = $this->compileCreate($bp);

        $this->assertStringContainsString('ENGINE = MergeTree()', $sql);
        $this->assertStringContainsString('ORDER BY tuple()', $sql);
    }

    public function test_create_replacing_merge_tree(): void
    {
        $bp = $this->blueprint();
        $bp->string('name');
        $bp->engine('ReplacingMergeTree(updated_at)');

        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('ENGINE = ReplacingMergeTree(updated_at)', $sql);
    }

    public function test_create_with_partition_by(): void
    {
        $bp = $this->blueprint();
        $bp->string('name');
        $bp->engine('MergeTree()');
        $bp->orderBy('name');
        $bp->partitionBy('toYYYYMM(created_at)');

        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('PARTITION BY toYYYYMM(created_at)', $sql);
    }

    public function test_create_with_primary_key(): void
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

    public function test_create_with_settings(): void
    {
        $bp = $this->blueprint();
        $bp->string('name');
        $bp->engine('MergeTree()');
        $bp->orderBy('name');
        $bp->setting('index_granularity', 8192);

        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('SETTINGS index_granularity = 8192', $sql);
    }

    public function test_create_memory_engine_no_order_by(): void
    {
        $bp = $this->blueprint();
        $bp->string('name');
        $bp->engine('Memory');

        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('ENGINE = Memory', $sql);
        $this->assertStringNotContainsString('ORDER BY', $sql);
    }

    public function test_create_full_table(): void
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

    public function test_compile_drop(): void
    {
        $bp = $this->blueprint();
        $sql = $this->grammar()->compileDrop($bp, new Fluent);
        $this->assertSame('DROP TABLE `events`', $sql);
    }

    public function test_compile_drop_if_exists(): void
    {
        $bp = $this->blueprint();
        $sql = $this->grammar()->compileDropIfExists($bp, new Fluent);
        $this->assertSame('DROP TABLE IF EXISTS `events`', $sql);
    }

    public function test_compile_add(): void
    {
        $bp = $this->blueprint();
        $email = $bp->string('email');
        $bp->integer('age');

        $sql = $this->grammar()->compileAdd(
            $bp,
            new Fluent(['column' => $email]),
        );

        $this->assertSame('ALTER TABLE `events` ADD COLUMN `email` String', $sql);
        $this->assertStringNotContainsString('age', $sql);
    }

    public function test_compile_drop_column(): void
    {
        $bp = $this->blueprint();
        $command = new Fluent(['columns' => ['email', 'age']]);
        $sql = $this->grammar()->compileDropColumn($bp, $command);

        $this->assertStringContainsString('ALTER TABLE `events`', $sql);
        $this->assertStringContainsString('DROP COLUMN `email`', $sql);
        $this->assertStringContainsString('DROP COLUMN `age`', $sql);
    }

    public function test_compile_rename(): void
    {
        $bp = $this->blueprint('old_table');
        $command = new Fluent(['to' => 'new_table']);
        $sql = $this->grammar()->compileRename($bp, $command);

        $this->assertSame('RENAME TABLE `old_table` TO `new_table`', $sql);
    }

    public function test_type_string(): void
    {
        $bp = $this->blueprint();
        $bp->string('name');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`name` String', $sql);
    }

    public function test_type_integer(): void
    {
        $bp = $this->blueprint();
        $bp->integer('count');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`count` Int32', $sql);
    }

    public function test_type_big_integer(): void
    {
        $bp = $this->blueprint();
        $bp->bigInteger('big');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`big` Int64', $sql);
    }

    public function test_type_small_integer(): void
    {
        $bp = $this->blueprint();
        $bp->smallInteger('small');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`small` Int16', $sql);
    }

    public function test_type_tiny_integer(): void
    {
        $bp = $this->blueprint();
        $bp->tinyInteger('tiny');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`tiny` Int8', $sql);
    }

    public function test_type_medium_integer(): void
    {
        $bp = $this->blueprint();
        $bp->mediumInteger('med');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`med` Int32', $sql);
    }

    public function test_type_float(): void
    {
        $bp = $this->blueprint();
        $bp->float('val');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`val` Float32', $sql);
    }

    public function test_type_double(): void
    {
        $bp = $this->blueprint();
        $bp->double('val');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`val` Float64', $sql);
    }

    public function test_type_decimal_defaults(): void
    {
        $bp = $this->blueprint();
        $bp->decimal('amount');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('Decimal(', $sql);
    }

    public function test_type_decimal_custom(): void
    {
        $bp = $this->blueprint();
        $bp->decimal('amount', 18, 4);
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('Decimal(18, 4)', $sql);
    }

    public function test_type_boolean(): void
    {
        $bp = $this->blueprint();
        $bp->boolean('active');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`active` Bool', $sql);
    }

    public function test_type_date(): void
    {
        $bp = $this->blueprint();
        $bp->date('day');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`day` Date', $sql);
    }

    public function test_type_date32(): void
    {
        $bp = $this->blueprint();
        $bp->date32('day');

        $this->assertStringContainsString('`day` Date32', $this->compileCreate($bp));
    }

    public function test_type_date_time(): void
    {
        $bp = $this->blueprint();
        $bp->dateTime('ts');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`ts` DateTime', $sql);
    }

    public function test_type_date_time_with_precision(): void
    {
        $bp = $this->blueprint();
        $bp->dateTime('ts', 3);
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('DateTime64(3)', $sql);
    }

    public function test_type_date_time_tz(): void
    {
        $bp = $this->blueprint();
        $bp->dateTimeTz('ts');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString("DateTime('UTC')", $sql);
    }

    public function test_type_timestamp(): void
    {
        $bp = $this->blueprint();
        $bp->timestamp('ts');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('DateTime', $sql);
    }

    public function test_type_time(): void
    {
        $bp = $this->blueprint();
        $bp->time('time');

        $this->assertStringContainsString('`time` Time', $this->compileCreate($bp));
    }

    public function test_type_time64(): void
    {
        $bp = $this->blueprint();
        $bp->time64('time', 6);

        $this->assertStringContainsString('`time` Time64(6)', $this->compileCreate($bp));
    }

    public function test_type_text(): void
    {
        $bp = $this->blueprint();
        $bp->text('body');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`body` String', $sql);
    }

    public function test_type_medium_text(): void
    {
        $bp = $this->blueprint();
        $bp->mediumText('body');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`body` String', $sql);
    }

    public function test_type_long_text(): void
    {
        $bp = $this->blueprint();
        $bp->longText('body');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`body` String', $sql);
    }

    public function test_type_json(): void
    {
        $bp = $this->blueprint();
        $bp->json('data');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`data` String', $sql);
    }

    public function test_type_native_json(): void
    {
        $bp = $this->blueprint();
        $bp->nativeJson('data');

        $this->assertStringContainsString('`data` JSON', $this->compileCreate($bp));
    }

    public function test_type_binary(): void
    {
        $bp = $this->blueprint();
        $bp->binary('blob');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`blob` String', $sql);
    }

    public function test_type_uuid(): void
    {
        $bp = $this->blueprint();
        $bp->uuid('uid');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`uid` UUID', $sql);
    }

    public function test_type_ip_address(): void
    {
        $bp = $this->blueprint();
        $bp->ipAddress('ip');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`ip` IPv4', $sql);
    }

    public function test_type_char(): void
    {
        $bp = $this->blueprint();
        $bp->char('code', 2);
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('FixedString(2)', $sql);
    }

    public function test_type_enum(): void
    {
        $bp = $this->blueprint();
        $bp->enum('status', ['active', 'inactive']);
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString("Enum8('active' = 1, 'inactive' = 2)", $sql);
    }

    public function test_empty_enum_is_rejected(): void
    {
        $bp = $this->blueprint();
        $bp->enum('status', []);

        $this->expectException(\InvalidArgumentException::class);
        $this->compileCreate($bp);
    }

    public function test_duplicate_enum_values_are_rejected(): void
    {
        $bp = $this->blueprint();
        $bp->enum('status', ['active', 'active']);

        $this->expectException(\InvalidArgumentException::class);
        $this->compileCreate($bp);
    }

    public function test_type_unsigned_tiny_integer(): void
    {
        $bp = $this->blueprint();
        $bp->unsignedTinyInteger('val');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`val` UInt8', $sql);
    }

    public function test_type_unsigned_small_integer(): void
    {
        $bp = $this->blueprint();
        $bp->unsignedSmallInteger('val');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`val` UInt16', $sql);
    }

    public function test_type_unsigned_integer(): void
    {
        $bp = $this->blueprint();
        $bp->unsignedInteger('val');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`val` UInt32', $sql);
    }

    public function test_type_unsigned_big_integer(): void
    {
        $bp = $this->blueprint();
        $bp->unsignedBigInteger('val');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`val` UInt64', $sql);
    }

    public function test_type_clickhouse_raw(): void
    {
        $bp = $this->blueprint();
        $bp->clickhouseType('tags', 'Array(String)');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`tags` Array(String)', $sql);
    }

    public function test_nullable_wraps_type(): void
    {
        $bp = $this->blueprint();
        $bp->string('name')->nullable();
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`name` Nullable(String)', $sql);
    }

    public function test_nullable_uuid(): void
    {
        $bp = $this->blueprint();
        $bp->uuid('uid')->nullable();
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('Nullable(UUID)', $sql);
    }

    public function test_low_cardinality_modifier_wraps_the_native_type(): void
    {
        $bp = $this->blueprint();
        $bp->string('country')->lowCardinality();

        $this->assertStringContainsString(
            '`country` LowCardinality(String)',
            $this->compileCreate($bp),
        );
    }

    public function test_nullable_low_cardinality_uses_valid_wrapper_order(): void
    {
        $bp = $this->blueprint();
        $bp->string('country')->nullable()->lowCardinality();
        $bp->lowCardinality('status')->nullable();

        $sql = $this->compileCreate($bp);

        $this->assertStringContainsString(
            '`country` LowCardinality(Nullable(String))',
            $sql,
        );
        $this->assertStringContainsString(
            '`status` LowCardinality(Nullable(String))',
            $sql,
        );
    }

    public function test_default_string_value(): void
    {
        $bp = $this->blueprint();
        $bp->string('status')->default('active');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString("DEFAULT 'active'", $sql);
    }

    public function test_default_string_escapes_click_house_quotes_and_backslashes(): void
    {
        $bp = $this->blueprint();
        $bp->string('path')->default("Lucas' C:\\data");

        $this->assertStringContainsString("DEFAULT 'Lucas\\' C:\\\\data'", $this->compileCreate($bp));
    }

    public function test_default_expression_remains_raw_when_explicit(): void
    {
        $bp = $this->blueprint();
        $bp->dateTime('created_at')->default(new Expression('now()'));

        $this->assertStringContainsString('DEFAULT now()', $this->compileCreate($bp));
    }

    public function test_default_integer_value(): void
    {
        $bp = $this->blueprint();
        $bp->integer('count')->default(0);
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('DEFAULT 0', $sql);
    }

    public function test_default_boolean_true(): void
    {
        $bp = $this->blueprint();
        $bp->boolean('active')->default(true);
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('DEFAULT 1', $sql);
    }

    public function test_default_boolean_false(): void
    {
        $bp = $this->blueprint();
        $bp->boolean('active')->default(false);
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('DEFAULT 0', $sql);
    }

    public function test_wrap_value_backticks(): void
    {
        $bp = $this->blueprint();
        $bp->string('user_name');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('`user_name`', $sql);
    }

    public function test_create_on_cluster(): void
    {
        $bp = $this->blueprint();
        $bp->uint64('id');
        $bp->engine('ReplicatedMergeTree()');
        $bp->orderBy('id');
        $bp->onCluster('my_cluster');

        $sql = $this->compileCreate($bp);

        $this->assertStringContainsString('ON CLUSTER `my_cluster`', $sql);
        $this->assertLessThan(strpos($sql, '('), strpos($sql, 'ON CLUSTER'));
    }

    public function test_create_without_on_cluster(): void
    {
        $bp = $this->blueprint();
        $bp->string('name');
        $sql = $this->compileCreate($bp);
        $this->assertStringNotContainsString('ON CLUSTER', $sql);
    }

    public function test_create_with_sample_ttl_settings_and_comment(): void
    {
        $bp = $this->blueprint();
        $bp->uint64('id');
        $bp->dateTime('created_at');
        $bp->engine('MergeTree()');
        $bp->orderBy('id');
        $bp->sampleBy('intHash32(id)');
        $bp->ttl('created_at + INTERVAL 30 DAY');
        $bp->setting('storage_policy', "hot'cold");
        $bp->tableComment("Lucas' events");

        $sql = $this->compileCreate($bp);

        $this->assertStringContainsString('SAMPLE BY intHash32(id)', $sql);
        $this->assertStringContainsString('TTL created_at + INTERVAL 30 DAY', $sql);
        $this->assertStringContainsString("SETTINGS storage_policy = 'hot\\'cold'", $sql);
        $this->assertStringContainsString("COMMENT 'Lucas\\' events'", $sql);
    }

    public function test_column_materialized_alias_ttl_and_comment_modifiers(): void
    {
        $bp = $this->blueprint();
        $bp->string('normalized')
            ->materialized(new Expression('lower(source)'))
            ->codec('ZSTD')
            ->ttl(new Expression('created_at + INTERVAL 7 DAY'))
            ->comment("Normalized user's source");

        $sql = $this->compileCreate($bp);

        $this->assertStringContainsString('MATERIALIZED lower(source)', $sql);
        $this->assertStringContainsString('CODEC(ZSTD)', $sql);
        $this->assertStringContainsString('TTL created_at + INTERVAL 7 DAY', $sql);
        $this->assertStringContainsString("COMMENT 'Normalized user\\'s source'", $sql);
    }

    public function test_column_ephemeral_without_expression(): void
    {
        $bp = $this->blueprint();
        $bp->string('scratch')->ephemeral();

        $this->assertStringContainsString(
            '`scratch` String EPHEMERAL',
            $this->compileCreate($bp),
        );
    }

    public function test_compile_change_column(): void
    {
        $bp = $this->blueprint();
        $column = $bp->lowCardinalityString('status')->change();
        $command = new Fluent(['column' => $column]);

        $sql = $this->grammar()->compileChange($bp, $command);

        $this->assertSame(
            'ALTER TABLE `events` MODIFY COLUMN `status` LowCardinality(String)',
            $sql,
        );
    }

    public function test_compile_rename_column(): void
    {
        $blueprint = $this->blueprint();
        $sql = $this->grammar()->compileRenameColumn(
            $blueprint,
            new Fluent(['from' => 'old_name', 'to' => 'new_name']),
        );

        $this->assertSame(
            'ALTER TABLE `events` RENAME COLUMN `old_name` TO `new_name`',
            $sql,
        );
    }

    public function test_compile_skip_index_and_projection(): void
    {
        $bp = $this->blueprint();
        $index = $bp->skipIndex('idx_status', 'status', 'set(100)', 2);
        $projection = $bp->projection('by_user', 'SELECT user_id, count() GROUP BY user_id');

        $this->assertSame(
            'ALTER TABLE `events` ADD INDEX `idx_status` status TYPE set(100) GRANULARITY 2',
            $this->grammar()->compileAddSkipIndex($bp, $index),
        );
        $this->assertSame(
            'ALTER TABLE `events` ADD PROJECTION `by_user` (SELECT user_id, count() GROUP BY user_id)',
            $this->grammar()->compileAddProjection($bp, $projection),
        );
    }

    public function test_column_compression_codec(): void
    {
        $bp = $this->blueprint();
        $bp->uint64('id')->codec('ZSTD(3)');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('CODEC(ZSTD(3))', $sql);
    }

    public function test_column_compression_codec_delta(): void
    {
        $bp = $this->blueprint();
        $bp->float64('value')->codec('Delta, ZSTD');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('CODEC(Delta, ZSTD)', $sql);
    }

    public function test_column_compression_codec_double_delta(): void
    {
        $bp = $this->blueprint();
        $bp->dateTime('ts')->codec('DoubleDelta, LZ4');
        $sql = $this->compileCreate($bp);
        $this->assertStringContainsString('CODEC(DoubleDelta, LZ4)', $sql);
    }

    public function test_column_without_codec(): void
    {
        $bp = $this->blueprint();
        $bp->string('name');
        $sql = $this->compileCreate($bp);
        $this->assertStringNotContainsString('CODEC', $sql);
    }

    public function test_full_distributed_table(): void
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

        $this->assertStringContainsString('CREATE TABLE `events` ON CLUSTER `production`', $sql);
        $this->assertStringContainsString('CODEC(ZSTD(3))', $sql);
        $this->assertStringContainsString('CODEC(DoubleDelta, LZ4)', $sql);
        $this->assertStringContainsString('ENGINE = ReplicatedMergeTree()', $sql);
        $this->assertStringContainsString('ORDER BY (`id`)', $sql);
        $this->assertStringContainsString('PARTITION BY toYYYYMM(ts)', $sql);
        $this->assertStringContainsString('SETTINGS index_granularity = 8192', $sql);
    }
}
