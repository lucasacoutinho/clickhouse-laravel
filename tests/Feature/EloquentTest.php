<?php

namespace ClickHouse\Laravel\Tests\Feature;

use ClickHouse\Laravel\Eloquent\ClickHouseModel;
use Illuminate\Support\Facades\DB;

class TestEvent extends ClickHouseModel
{
    protected $table = '_test_eloquent';
}

/**
 * @group integration
 */
class EloquentTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::connection('clickhouse')->statement('DROP TABLE IF EXISTS _test_eloquent');
        DB::connection('clickhouse')->statement(<<<'SQL'
            CREATE TABLE _test_eloquent (
                id UInt64,
                name String,
                value Float64
            ) ENGINE = MergeTree() ORDER BY id
        SQL);
    }

    protected function tearDown(): void
    {
        DB::connection('clickhouse')->statement('DROP TABLE IF EXISTS _test_eloquent');
        parent::tearDown();
    }

    public function testModelProperties(): void
    {
        $model = new TestEvent();

        $this->assertSame('clickhouse', $model->getConnectionName());
        $this->assertFalse($model->getIncrementing());
        $this->assertFalse($model->usesTimestamps());
        $this->assertSame('string', $model->getKeyType());
        $this->assertSame([], $model->getGuarded());
    }

    public function testModelCreate(): void
    {
        TestEvent::create(['id' => 1, 'name' => 'test', 'value' => 3.14]);

        $rows = DB::connection('clickhouse')->table('_test_eloquent')->get();
        $this->assertCount(1, $rows);
        $this->assertEquals('test', $rows[0]->name);
    }

    public function testModelQuery(): void
    {
        DB::connection('clickhouse')->table('_test_eloquent')->insert([
            ['id' => 1, 'name' => 'Alice', 'value' => 1.0],
            ['id' => 2, 'name' => 'Bob', 'value' => 2.0],
            ['id' => 3, 'name' => 'Charlie', 'value' => 3.0],
        ]);

        $results = TestEvent::where('value', '>', 1.5)->get();
        $this->assertCount(2, $results);
    }

    public function testModelCount(): void
    {
        DB::connection('clickhouse')->table('_test_eloquent')->insert([
            ['id' => 1, 'name' => 'a', 'value' => 0],
            ['id' => 2, 'name' => 'b', 'value' => 0],
        ]);

        $this->assertEquals(2, TestEvent::count());
    }

    public function testModelFirst(): void
    {
        DB::connection('clickhouse')->table('_test_eloquent')->insert([
            ['id' => 1, 'name' => 'first', 'value' => 0],
        ]);

        $event = TestEvent::first();
        $this->assertNotNull($event);
        $this->assertSame('first', $event->name);
    }

    public function testModelOrderAndLimit(): void
    {
        DB::connection('clickhouse')->table('_test_eloquent')->insert([
            ['id' => 1, 'name' => 'a', 'value' => 1.0],
            ['id' => 2, 'name' => 'b', 'value' => 2.0],
            ['id' => 3, 'name' => 'c', 'value' => 3.0],
        ]);

        $results = TestEvent::orderBy('value', 'desc')->limit(2)->get();
        $this->assertCount(2, $results);
        $this->assertSame('c', $results[0]->name);
    }
}
