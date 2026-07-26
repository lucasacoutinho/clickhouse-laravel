<?php

namespace ClickHouse\Laravel\Tests\Feature;

use ClickHouse\Laravel\Concerns\HasClickHouseTimestamps;
use ClickHouse\Laravel\Eloquent\ClickHouseModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

class TestEvent extends ClickHouseModel
{
    protected $table = '_test_eloquent';

    protected $fillable = ['id', 'name', 'value'];
}

class TimestampedTestEvent extends ClickHouseModel
{
    use HasClickHouseTimestamps;

    protected $table = '_test_eloquent';

    protected $fillable = ['id', 'name', 'value'];
}

#[Group('integration')]
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
                value Float64,
                created_at DateTime64(6) DEFAULT now64(6),
                updated_at DateTime64(6) DEFAULT now64(6)
            ) ENGINE = MergeTree() ORDER BY id
        SQL);
    }

    protected function tearDown(): void
    {
        DB::connection('clickhouse')->statement('DROP TABLE IF EXISTS _test_eloquent');
        parent::tearDown();
    }

    public function test_model_properties(): void
    {
        $model = new TestEvent;

        $this->assertSame('clickhouse', $model->getConnectionName());
        $this->assertFalse($model->getIncrementing());
        $this->assertFalse($model->usesTimestamps());
        $this->assertSame('string', $model->getKeyType());
        $this->assertSame(['*'], $model->getGuarded());
        $this->assertSame(['id', 'name', 'value'], $model->getFillable());
    }

    public function test_model_create(): void
    {
        TestEvent::create(['id' => 1, 'name' => 'test', 'value' => 3.14]);

        $rows = DB::connection('clickhouse')->table('_test_eloquent')->get();
        $this->assertCount(1, $rows);
        $this->assertEquals('test', $rows[0]->name);
    }

    public function test_model_query(): void
    {
        DB::connection('clickhouse')->table('_test_eloquent')->insert([
            ['id' => 1, 'name' => 'Alice', 'value' => 1.0],
            ['id' => 2, 'name' => 'Bob', 'value' => 2.0],
            ['id' => 3, 'name' => 'Charlie', 'value' => 3.0],
        ]);

        $results = TestEvent::where('value', '>', 1.5)->get();
        $this->assertCount(2, $results);
    }

    public function test_model_count(): void
    {
        DB::connection('clickhouse')->table('_test_eloquent')->insert([
            ['id' => 1, 'name' => 'a', 'value' => 0],
            ['id' => 2, 'name' => 'b', 'value' => 0],
        ]);

        $this->assertEquals(2, TestEvent::count());
    }

    public function test_model_first(): void
    {
        DB::connection('clickhouse')->table('_test_eloquent')->insert([
            ['id' => 1, 'name' => 'first', 'value' => 0],
        ]);

        $event = TestEvent::first();
        $this->assertNotNull($event);
        $this->assertSame('first', $event->name);
    }

    public function test_model_order_and_limit(): void
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

    public function test_timestamp_trait_updates_timestamp_on_every_save(): void
    {
        Carbon::setTestNow('2026-07-26 10:00:00');

        try {
            $event = TimestampedTestEvent::create([
                'id' => 10,
                'name' => 'created',
                'value' => 1.0,
            ]);

            $this->assertSame(
                '2026-07-26 10:00:00',
                $event->created_at->format('Y-m-d H:i:s'),
            );
            $this->assertSame(
                '2026-07-26 10:00:00',
                $event->updated_at->format('Y-m-d H:i:s'),
            );

            Carbon::setTestNow('2026-07-26 11:00:00');
            $event->name = 'updated';
            $event->save();

            $this->assertSame(
                '2026-07-26 11:00:00',
                $event->updated_at->format('Y-m-d H:i:s'),
            );
        } finally {
            Carbon::setTestNow();
        }
    }
}
