<?php

namespace ClickHouse\Laravel\Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * @group integration
 */
class QueryTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $conn = DB::connection('clickhouse');
        $conn->statement('DROP TABLE IF EXISTS _test_events');
        $conn->statement(<<<'SQL'
            CREATE TABLE _test_events (
                id UInt64,
                user_id UInt32,
                event String,
                tags Array(String),
                score Float64,
                ts DateTime DEFAULT now()
            ) ENGINE = MergeTree() ORDER BY (id)
        SQL);
    }

    protected function tearDown(): void
    {
        DB::connection('clickhouse')->statement('DROP TABLE IF EXISTS _test_events');
        parent::tearDown();
    }

    protected function seedRows(int $count = 10): void
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'id' => $i,
                'user_id' => ($i % 3) + 1,
                'event' => $i % 2 === 0 ? 'click' : 'view',
                'tags' => "['tag" . ($i % 5) . "']",
                'score' => $i * 0.1,
            ];
        }
        DB::connection('clickhouse')->table('_test_events')->insert($rows);
    }

    public function testInsertAndSelect(): void
    {
        $conn = DB::connection('clickhouse');

        $conn->table('_test_events')->insert([
            ['id' => 1, 'user_id' => 1, 'event' => 'click', 'score' => 0.5],
            ['id' => 2, 'user_id' => 2, 'event' => 'view', 'score' => 0.8],
        ]);

        $rows = $conn->table('_test_events')->orderBy('id')->get();

        $this->assertCount(2, $rows);
        $this->assertEquals('click', $rows[0]->event);
        $this->assertEquals('view', $rows[1]->event);
    }

    public function testInsertChunked(): void
    {
        $rows = array_map(fn($i) => ['id' => $i, 'user_id' => 1, 'event' => 'test', 'score' => 0.0], range(1, 100));

        DB::connection('clickhouse')->table('_test_events')->insertChunked($rows, 25);

        $this->assertEquals(100, DB::connection('clickhouse')->table('_test_events')->count());
    }

    public function testWhereAndCount(): void
    {
        $this->seedRows(10);

        $count = DB::connection('clickhouse')
            ->table('_test_events')
            ->where('event', '=', 'click')
            ->count();

        $this->assertEquals(5, $count);
    }

    public function testWhereIn(): void
    {
        $this->seedRows(10);

        $rows = DB::connection('clickhouse')
            ->table('_test_events')
            ->whereIn('id', [1, 2, 3])
            ->get();

        $this->assertCount(3, $rows);
    }

    public function testWhereBetween(): void
    {
        $this->seedRows(10);

        $rows = DB::connection('clickhouse')
            ->table('_test_events')
            ->whereBetween('score', [0.3, 0.7])
            ->get();

        $this->assertNotEmpty($rows);
    }

    public function testGroupByAndHaving(): void
    {
        $this->seedRows(10);

        $rows = DB::connection('clickhouse')
            ->table('_test_events')
            ->selectRaw('event, count(*) as cnt')
            ->groupBy('event')
            ->havingRaw('cnt > 0')
            ->get();

        $this->assertCount(2, $rows);
    }

    public function testOrderByAndLimit(): void
    {
        $this->seedRows(10);

        $rows = DB::connection('clickhouse')
            ->table('_test_events')
            ->orderBy('id', 'desc')
            ->limit(3)
            ->get();

        $this->assertCount(3, $rows);
        $this->assertEquals(10, $rows[0]->id);
    }

    public function testLimitAndOffset(): void
    {
        $this->seedRows(10);

        $rows = DB::connection('clickhouse')
            ->table('_test_events')
            ->orderBy('id')
            ->offset(5)
            ->limit(3)
            ->get();

        $this->assertCount(3, $rows);
        $this->assertEquals(6, $rows[0]->id);
    }

    public function testSelectRawWithAggregates(): void
    {
        $this->seedRows(10);

        $result = DB::connection('clickhouse')
            ->table('_test_events')
            ->selectRaw('min(score) as min_s, max(score) as max_s, avg(score) as avg_s')
            ->first();

        $this->assertNotNull($result);
        $this->assertLessThan($result->max_s, $result->min_s);
    }

    public function testUnionAll(): void
    {
        $this->seedRows(10);

        $conn = DB::connection('clickhouse');

        $rows = $conn->table('_test_events')
            ->select('id')
            ->where('event', 'click')
            ->unionAll(
                $conn->table('_test_events')->select('id')->where('event', 'view')
            )
            ->get();

        $this->assertCount(10, $rows);
    }

    public function testDeleteRequiresWhere(): void
    {
        $this->expectException(\ClickHouse\Laravel\Exceptions\ClickHouseGrammarException::class);

        DB::connection('clickhouse')->table('_test_events')->delete();
    }

    public function testDeleteWithWhere(): void
    {
        $this->seedRows(10);

        DB::connection('clickhouse')
            ->table('_test_events')
            ->where('event', '=', 'click')
            ->delete();

        // ClickHouse mutations are async — the delete is queued but may not be applied instantly
        // Just verify it doesn't throw
        $this->assertTrue(true);
    }

    public function testTruncate(): void
    {
        $this->seedRows(10);

        DB::connection('clickhouse')->table('_test_events')->truncate();

        $this->assertEquals(0, DB::connection('clickhouse')->table('_test_events')->count());
    }

    public function testUpdate(): void
    {
        $this->seedRows(10);

        DB::connection('clickhouse')
            ->table('_test_events')
            ->where('id', '=', 1)
            ->update(['event' => 'updated']);

        // Mutations are async — just verify no exception
        $this->assertTrue(true);
    }

    public function testGroupedOrWhere(): void
    {
        $this->seedRows(10);

        $rows = DB::connection('clickhouse')
            ->table('_test_events')
            ->where(function ($q) {
                $q->where('id', '=', 1)->orWhere('id', '=', 2);
            })
            ->get();

        $this->assertCount(2, $rows);
    }

    public function testSubqueryInWhereIn(): void
    {
        $this->seedRows(10);

        $sub = DB::connection('clickhouse')
            ->table('_test_events')
            ->select('user_id')
            ->where('event', '=', 'click');

        $rows = DB::connection('clickhouse')
            ->table('_test_events')
            ->whereIn('user_id', $sub)
            ->get();

        $this->assertNotEmpty($rows);
    }

    public function testSelectSubquery(): void
    {
        $this->seedRows(10);

        $rows = DB::connection('clickhouse')
            ->table('_test_events')
            ->selectRaw('id')
            ->selectSub(
                DB::connection('clickhouse')->table('_test_events')->selectRaw('count(*)'),
                'total'
            )
            ->limit(1)
            ->get();

        $this->assertEquals(10, $rows[0]->total);
    }

    public function testSettingsApplied(): void
    {
        $this->seedRows(10);

        $rows = DB::connection('clickhouse')
            ->table('_test_events')
            ->settings(['max_threads' => 1])
            ->get();

        $this->assertCount(10, $rows);
    }
}
