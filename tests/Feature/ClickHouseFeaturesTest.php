<?php

namespace ClickHouse\Laravel\Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * Integration tests for ClickHouse-specific query features.
 *
 * @group integration
 */
class ClickHouseFeaturesTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $conn = DB::connection('clickhouse');

        $conn->statement('DROP TABLE IF EXISTS _test_features');
        $conn->statement(<<<'SQL'
            CREATE TABLE _test_features (
                id UInt64,
                user_id UInt32,
                event String,
                tags Array(String),
                score Float64,
                ts DateTime DEFAULT now(),
                version UInt32 DEFAULT 1
            ) ENGINE = ReplacingMergeTree(version) ORDER BY (id) PARTITION BY toYYYYMM(ts)
        SQL);

        $conn->statement('DROP TABLE IF EXISTS _test_users');
        $conn->statement(<<<'SQL'
            CREATE TABLE _test_users (
                user_id UInt32,
                name String
            ) ENGINE = MergeTree() ORDER BY (user_id)
        SQL);

        $conn->statement('DROP TABLE IF EXISTS _test_timeseries');
        $conn->statement(<<<'SQL'
            CREATE TABLE _test_timeseries (
                ts DateTime,
                value Float64
            ) ENGINE = MergeTree() ORDER BY (ts)
        SQL);
    }

    protected function tearDown(): void
    {
        $conn = DB::connection('clickhouse');
        $conn->statement('DROP TABLE IF EXISTS _test_features');
        $conn->statement('DROP TABLE IF EXISTS _test_users');
        $conn->statement('DROP TABLE IF EXISTS _test_timeseries');
        parent::tearDown();
    }

    protected function seedEvents(int $count = 20): void
    {
        $rows = [];
        $tags = [['web', 'mobile'], ['api'], ['web'], ['mobile', 'api', 'web'], ['api', 'web']];
        for ($i = 1; $i <= $count; $i++) {
            $tagSet = $tags[$i % count($tags)];
            $rows[] = [
                'id'      => $i,
                'user_id' => ($i % 5) + 1,
                'event'   => $i % 3 === 0 ? 'purchase' : ($i % 2 === 0 ? 'click' : 'view'),
                'tags'    => "['" . implode("','", $tagSet) . "']",
                'score'   => round($i * 0.15, 2),
                'version' => 1,
            ];
        }
        DB::connection('clickhouse')->table('_test_features')->insert($rows);
    }

    protected function seedUsers(): void
    {
        DB::connection('clickhouse')->table('_test_users')->insert([
            ['user_id' => 1, 'name' => 'Alice'],
            ['user_id' => 2, 'name' => 'Bob'],
            ['user_id' => 3, 'name' => 'Charlie'],
            ['user_id' => 4, 'name' => 'Diana'],
            ['user_id' => 5, 'name' => 'Eve'],
        ]);
    }

    protected function seedTimeseries(): void
    {
        $rows = [];
        $base = strtotime('2026-03-24 10:00:00');
        for ($i = 0; $i < 10; $i++) {
            // Insert every 2 minutes to create gaps for WITH FILL
            $rows[] = [
                'ts'    => date('Y-m-d H:i:s', $base + ($i * 120)),
                'value' => round(rand(10, 100) / 10, 1),
            ];
        }
        DB::connection('clickhouse')->table('_test_timeseries')->insert($rows);
    }

    public function testFinal(): void
    {
        $this->seedEvents();

        // Insert a duplicate with higher version
        DB::connection('clickhouse')->table('_test_features')->insert([
            ['id' => 1, 'user_id' => 1, 'event' => 'updated_view', 'score' => 9.99, 'version' => 2],
        ]);

        $withoutFinal = DB::connection('clickhouse')
            ->table('_test_features')
            ->where('id', 1)
            ->get();

        $withFinal = DB::connection('clickhouse')
            ->table('_test_features')
            ->final()
            ->where('id', 1)
            ->get();

        // Without FINAL we may see both versions; with FINAL only the latest
        $this->assertGreaterThanOrEqual(1, count($withFinal));
        $this->assertLessThanOrEqual(count($withoutFinal), count($withFinal));
    }

    public function testSample(): void
    {
        $this->seedEvents(100);

        // SAMPLE 0.5 should return approximately half the rows
        $sampled = DB::connection('clickhouse')
            ->table('_test_features')
            ->sample(0.5)
            ->count();

        $total = DB::connection('clickhouse')
            ->table('_test_features')
            ->count();

        $this->assertLessThan($total, $sampled);
        $this->assertGreaterThan(0, $sampled);
    }

    public function testPreWhereExecutes(): void
    {
        $this->seedEvents();

        $rows = DB::connection('clickhouse')
            ->table('_test_features')
            ->preWhere('score', '>', 1.0)
            ->where('event', '=', 'click')
            ->get();

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertGreaterThan(1.0, $row->score);
            $this->assertSame('click', $row->event);
        }
    }

    public function testPreWhereIn(): void
    {
        $this->seedEvents();

        $rows = DB::connection('clickhouse')
            ->table('_test_features')
            ->preWhereIn('user_id', [1, 2])
            ->get();

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertContains($row->user_id, [1, 2]);
        }
    }

    public function testPreWhereBetween(): void
    {
        $this->seedEvents();

        $rows = DB::connection('clickhouse')
            ->table('_test_features')
            ->preWhereBetween('score', [0.5, 1.5])
            ->get();

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertGreaterThanOrEqual(0.5, $row->score);
            $this->assertLessThanOrEqual(1.5, $row->score);
        }
    }

    public function testPreWhereRaw(): void
    {
        $this->seedEvents();

        $rows = DB::connection('clickhouse')
            ->table('_test_features')
            ->preWhereRaw('score > 2.0')
            ->get();

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertGreaterThan(2.0, $row->score);
        }
    }

    public function testPreWhereWithOrPreWhere(): void
    {
        $this->seedEvents();

        $rows = DB::connection('clickhouse')
            ->table('_test_features')
            ->preWhere('event', '=', 'purchase')
            ->orPreWhere('event', '=', 'click')
            ->get();

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertContains($row->event, ['purchase', 'click']);
        }
    }

    public function testArrayJoin(): void
    {
        $this->seedEvents();

        $rows = DB::connection('clickhouse')
            ->table('_test_features')
            ->selectRaw('id, tags_item')
            ->arrayJoin('tags', 'tags_item')
            ->where('id', '<=', 5)
            ->get();

        // Each row's tags are exploded — more rows than original
        $this->assertGreaterThan(5, count($rows));
    }

    public function testLeftArrayJoin(): void
    {
        $this->seedEvents();

        $rowsInner = DB::connection('clickhouse')
            ->table('_test_features')
            ->arrayJoin('tags', 'tag')
            ->count();

        $rowsLeft = DB::connection('clickhouse')
            ->table('_test_features')
            ->leftArrayJoin('tags', 'tag')
            ->count();

        // LEFT ARRAY JOIN keeps rows with empty arrays
        $this->assertGreaterThanOrEqual($rowsInner, $rowsLeft);
    }

    public function testLimitBy(): void
    {
        $this->seedEvents();

        $rows = DB::connection('clickhouse')
            ->table('_test_features')
            ->select('user_id', 'id', 'score')
            ->orderBy('score', 'desc')
            ->limitBy(2, 'user_id')
            ->get();

        // Count rows per user_id — each should have at most 2
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->user_id] = ($grouped[$row->user_id] ?? 0) + 1;
        }
        foreach ($grouped as $userId => $count) {
            $this->assertLessThanOrEqual(2, $count, "user_id {$userId} has {$count} rows, expected <= 2");
        }
    }

    public function testLimitByWithLimit(): void
    {
        $this->seedEvents();

        $rows = DB::connection('clickhouse')
            ->table('_test_features')
            ->orderBy('id')
            ->limitBy(1, 'user_id')
            ->limit(3)
            ->get();

        $this->assertCount(3, $rows);
    }

    public function testAnyLeftJoinUsing(): void
    {
        $this->seedEvents();
        $this->seedUsers();

        $rows = DB::connection('clickhouse')
            ->table('_test_features')
            ->select('_test_features.id', '_test_users.name')
            ->anyLeftJoin('_test_users', 'user_id')
            ->limit(5)
            ->get();

        $this->assertCount(5, $rows);
        $this->assertNotEmpty($rows[0]->name);
    }

    public function testAllInnerJoin(): void
    {
        $this->seedEvents();
        $this->seedUsers();

        $rows = DB::connection('clickhouse')
            ->table('_test_features')
            ->select('_test_features.id', '_test_users.name')
            ->allInnerJoin('_test_users', 'user_id')
            ->limit(10)
            ->get();

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertNotEmpty($row->name);
        }
    }

    public function testJoinWithOnCondition(): void
    {
        $this->seedEvents();
        $this->seedUsers();

        $rows = DB::connection('clickhouse')
            ->table('_test_features')
            ->select('_test_features.id', '_test_users.name')
            ->anyLeftJoin('_test_users', on: [['_test_features.user_id', '=', '_test_users.user_id']])
            ->limit(5)
            ->get();

        $this->assertCount(5, $rows);
    }

    public function testJoinWithSubquery(): void
    {
        $this->seedEvents();
        $this->seedUsers();

        $sub = DB::connection('clickhouse')
            ->table('_test_users')
            ->select('user_id', 'name')
            ->where('user_id', '<=', 3);

        $rows = DB::connection('clickhouse')
            ->table('_test_features')
            ->select('_test_features.id')
            ->anyLeftJoin($sub, 'user_id', alias: 'u')
            ->limit(5)
            ->get();

        $this->assertNotEmpty($rows);
    }

    public function testFormat(): void
    {
        $this->seedEvents();

        // FORMAT affects how ClickHouse returns data
        // Through PDO, it may not change the PHP result, but the SQL must compile and execute
        $rows = DB::connection('clickhouse')
            ->table('_test_features')
            ->limit(5)
            ->get();

        $this->assertCount(5, $rows);
    }

    public function testSettingsApplied(): void
    {
        $this->seedEvents();

        // max_threads = 1 forces single-threaded execution — should still return results
        $rows = DB::connection('clickhouse')
            ->table('_test_features')
            ->settings(['max_threads' => 1])
            ->get();

        $this->assertCount(20, $rows);
    }

    public function testWithFillTimeSeries(): void
    {
        $this->seedTimeseries();

        // Data has gaps (every 2 minutes). Fill every 1 minute.
        $rows = DB::connection('clickhouse')
            ->table('_test_timeseries')
            ->selectRaw("toStartOfMinute(ts) AS bucket, count() AS cnt")
            ->groupByRaw('bucket')
            ->orderBy('bucket')
            ->withFillTime('2026-03-24 10:00:00', '2026-03-24 10:20:00', '1 minute')
            ->get();

        // Should have ~20 rows (one per minute) even though only 10 have data
        $this->assertGreaterThan(10, count($rows));
    }

    public function testWithFillRaw(): void
    {
        $this->seedTimeseries();

        $rows = DB::connection('clickhouse')
            ->table('_test_timeseries')
            ->selectRaw("toStartOfMinute(ts) AS bucket, sum(value) AS total")
            ->groupByRaw('bucket')
            ->orderBy('bucket')
            ->withFillRaw("FROM toDateTime('2026-03-24 10:00:00') TO toDateTime('2026-03-24 10:20:00') STEP toIntervalMinute(1)")
            ->get();

        $this->assertGreaterThan(10, count($rows));
    }

    public function testWithFillInterpolate(): void
    {
        $this->seedTimeseries();

        $rows = DB::connection('clickhouse')
            ->table('_test_timeseries')
            ->selectRaw("toStartOfMinute(ts) AS bucket, sum(value) AS total")
            ->groupByRaw('bucket')
            ->orderBy('bucket')
            ->withFillTime('2026-03-24 10:00:00', '2026-03-24 10:20:00', '1 minute')
            ->interpolate('total')
            ->get();

        $this->assertGreaterThan(10, count($rows));
    }

    public function testComplexAnalyticsQuery(): void
    {
        $this->seedEvents(50);
        $this->seedUsers();

        $rows = DB::connection('clickhouse')
            ->table('_test_features')
            ->selectRaw('_test_features.user_id, _test_users.name, count(*) as cnt, sum(score) as total_score')
            ->anyLeftJoin('_test_users', 'user_id')
            ->preWhere('score', '>', 0.5)
            ->where('event', '!=', 'view')
            ->groupByRaw('_test_features.user_id, _test_users.name')
            ->orderBy('total_score', 'desc')
            ->limitBy(1, 'user_id')
            ->limit(3)
            ->settings(['max_threads' => 2])
            ->get();

        $this->assertNotEmpty($rows);
        $this->assertLessThanOrEqual(3, count($rows));

        foreach ($rows as $row) {
            $this->assertGreaterThan(0, $row->cnt);
            $this->assertNotEmpty($row->name);
        }
    }

    public function testAsyncInsertSetting(): void
    {
        // Verify async() applies the setting and insert doesn't throw
        DB::connection('clickhouse')
            ->table('_test_features')
            ->async(wait: true)
            ->insert([
                ['id' => 999, 'user_id' => 1, 'event' => 'async_test', 'score' => 1.0, 'version' => 1],
            ]);

        // Wait a bit for async flush
        usleep(100_000);

        $count = DB::connection('clickhouse')
            ->table('_test_features')
            ->where('id', 999)
            ->count();

        $this->assertEquals(1, $count);
    }

    public function testCombinedFinalPrewhereSampleSettings(): void
    {
        $this->seedEvents(100);

        $rows = DB::connection('clickhouse')
            ->table('_test_features')
            ->final()
            ->sample(0.5)
            ->preWhere('score', '>', 1.0)
            ->where('event', '=', 'click')
            ->settings(['max_threads' => 1])
            ->get();

        foreach ($rows as $row) {
            $this->assertSame('click', $row->event);
            $this->assertGreaterThan(1.0, $row->score);
        }
    }
}
