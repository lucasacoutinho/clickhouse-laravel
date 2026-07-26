<?php

namespace ClickHouse\Laravel\Tests\Feature;

use ClickHouse\Laravel\Parallel;
use ClickHouse\Laravel\Support\ClickHouseValue;
use Illuminate\Concurrency\SyncDriver;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
class AdvancedQueryTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = DB::connection('clickhouse');
        $connection->statement('DROP TABLE IF EXISTS _test_advanced_events');
        $connection->statement(<<<'SQL'
            CREATE TABLE _test_advanced_events (
                id UInt64,
                user_id UInt32,
                event String,
                tags Array(String),
                day Date
            ) ENGINE = MergeTree()
            PARTITION BY day
            ORDER BY id
        SQL);
        $connection->table('_test_advanced_events')->insert([
            [
                'id' => 1,
                'user_id' => 10,
                'event' => 'view',
                'tags' => ClickHouseValue::array([]),
                'day' => '2026-01-01',
            ],
            [
                'id' => 2,
                'user_id' => 20,
                'event' => 'click',
                'tags' => ClickHouseValue::array(['paid']),
                'day' => '2026-01-01',
            ],
            [
                'id' => 3,
                'user_id' => 20,
                'event' => 'click',
                'tags' => ClickHouseValue::array(['organic']),
                'day' => '2026-01-02',
            ],
            [
                'id' => 4,
                'user_id' => 30,
                'event' => 'purchase',
                'tags' => ClickHouseValue::array([]),
                'day' => '2026-01-02',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('clickhouse')->statement('DROP TABLE IF EXISTS _test_advanced_events');

        parent::tearDown();
    }

    public function test_scalar_and_subquery_ctes_execute(): void
    {
        $connection = DB::connection('clickhouse');
        $scalar = $connection->table('_test_advanced_events')
            ->withQuery(2, 'minimum_id')
            ->whereRaw('id >= minimum_id')
            ->count();
        $clicks = $connection->table('_test_advanced_events')
            ->select('id')
            ->where('event', 'click');
        $rows = $connection->query()
            ->withQuerySub($clicks, 'clicks')
            ->from('clicks')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame(3, $scalar);
        $this->assertSame([2, 3], $rows);
    }

    public function test_global_and_empty_predicates_execute(): void
    {
        $connection = DB::connection('clickhouse');
        $allowedUsers = $connection->table('_test_advanced_events')
            ->select('user_id')
            ->where('event', 'click');

        $this->assertSame(
            2,
            $connection->table('_test_advanced_events')
                ->whereGlobalIn('user_id', $allowedUsers)
                ->count(),
        );
        $this->assertSame(
            [1, 4],
            $connection->table('_test_advanced_events')
                ->whereEmpty('tags')
                ->orderBy('id')
                ->pluck('id')
                ->all(),
        );
        $this->assertSame(
            [2, 3],
            $connection->table('_test_advanced_events')
                ->whereNotEmpty('tags')
                ->orderBy('id')
                ->pluck('id')
                ->all(),
        );
    }

    public function test_intersect_except_and_union_distinct_execute(): void
    {
        $connection = DB::connection('clickhouse');
        $all = $connection->table('_test_advanced_events')->select('user_id');
        $clicks = $connection->table('_test_advanced_events')
            ->select('user_id')
            ->where('event', 'click');

        $this->assertSame(
            [20],
            $all->clone()->intersect($clicks)->orderBy('user_id')->pluck('user_id')->all(),
        );
        $this->assertSame(
            [10, 30],
            $all->clone()->except($clicks)->orderBy('user_id')->pluck('user_id')->all(),
        );
        $this->assertSame(
            [10, 20],
            $all->clone()
                ->unionDistinct($clicks)
                ->orderBy('user_id')
                ->limit(2)
                ->settings(['max_threads' => 1])
                ->pluck('user_id')
                ->all(),
        );
    }

    public function test_aggregate_over_a_set_query_executes(): void
    {
        $connection = DB::connection('clickhouse');
        $clicks = $connection->table('_test_advanced_events')
            ->select('user_id')
            ->where('event', 'click');

        $this->assertSame(
            3,
            $connection->table('_test_advanced_events')
                ->select('user_id')
                ->unionDistinct($clicks)
                ->settings(['max_threads' => 1])
                ->count(),
        );
    }

    public function test_cte_is_visible_to_every_set_operand(): void
    {
        $connection = DB::connection('clickhouse');
        $clickIds = $connection->table('_test_advanced_events')
            ->select('id')
            ->where('event', 'click');
        $right = $connection->query()
            ->from('click_ids')
            ->select('id')
            ->where('id', 3);
        $ids = $connection->query()
            ->withQuerySub($clickIds, 'click_ids')
            ->from('click_ids')
            ->select('id')
            ->unionDistinct($right)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([2, 3], $ids);
    }

    public function test_lightweight_and_partition_scoped_deletes_execute(): void
    {
        $connection = DB::connection('clickhouse');

        $connection->table('_test_advanced_events')
            ->where('id', 2)
            ->mutationsSync()
            ->deleteLightweight('2026-01-01');
        $connection->table('_test_advanced_events')
            ->where('id', 4)
            ->mutationsSync()
            ->deleteMutation('2026-01-02');

        $this->assertSame(
            [1, 3],
            $connection->table('_test_advanced_events')
                ->orderBy('id')
                ->pluck('id')
                ->all(),
        );
    }

    public function test_parallel_get_executes_compiled_queries_through_laravel_driver(): void
    {
        $connection = DB::connection('clickhouse');
        $results = Parallel::get([
            'clicks' => $connection->table('_test_advanced_events')->where('event', 'click'),
            'purchases' => $connection->table('_test_advanced_events')->where('event', 'purchase'),
        ], executor: new SyncDriver);

        $this->assertCount(2, $results['clicks']);
        $this->assertCount(1, $results['purchases']);
    }

    public function test_delete_can_default_to_lightweight_mode_from_connection_config(): void
    {
        $this->app['config']->set(
            'database.connections.clickhouse.use_lightweight_delete',
            true,
        );
        DB::purge('clickhouse');
        $connection = DB::connection('clickhouse');

        $connection->table('_test_advanced_events')
            ->where('id', 1)
            ->mutationsSync()
            ->delete();

        $this->assertSame(
            0,
            $connection->table('_test_advanced_events')->where('id', 1)->count(),
        );
    }
}
