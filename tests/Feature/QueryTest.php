<?php

namespace ClickHouse\Laravel\Tests\Feature;

use ClickHouse\Laravel\Exceptions\ClickHouseGrammarException;
use ClickHouse\Laravel\Support\ClickHouseValue;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
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
                'tags' => ClickHouseValue::array(['tag'.($i % 5)]),
                'score' => $i * 0.1,
            ];
        }
        DB::connection('clickhouse')->table('_test_events')->insert($rows);
    }

    public function test_insert_and_select(): void
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

    public function test_insert_chunked(): void
    {
        $rows = array_map(fn ($i) => ['id' => $i, 'user_id' => 1, 'event' => 'test', 'score' => 0.0], range(1, 100));

        DB::connection('clickhouse')->table('_test_events')->insertChunked($rows, 25);

        $this->assertEquals(100, DB::connection('clickhouse')->table('_test_events')->count());
    }

    public function test_insert_complex_native_values(): void
    {
        $connection = DB::connection('clickhouse');
        $connection->statement('DROP TABLE IF EXISTS _test_values');

        try {
            $connection->statement(<<<'SQL'
                CREATE TABLE _test_values (
                    id UInt64,
                    tags Array(String),
                    attributes Map(String, String),
                    coordinates Tuple(Float64, Float64),
                    payload String
                ) ENGINE = MergeTree() ORDER BY id
            SQL);

            $connection->table('_test_values')->insert([
                [
                    'id' => 1,
                    'tags' => ClickHouseValue::array(['web', 'paid']),
                    'attributes' => ClickHouseValue::map(['country' => 'BR']),
                    'coordinates' => ClickHouseValue::tuple(-23.55, -46.63),
                    'payload' => ClickHouseValue::json(['source' => 'landing-page']),
                ],
            ]);

            $row = $connection->selectOne(<<<'SQL'
                SELECT
                    tags[1] AS first_tag,
                    attributes['country'] AS country,
                    coordinates.1 AS latitude,
                    JSONExtractString(payload, 'source') AS source
                FROM _test_values
                WHERE id = 1
            SQL);

            $this->assertNotNull($row);
            $this->assertSame('web', $row->first_tag);
            $this->assertSame('BR', $row->country);
            $this->assertEqualsWithDelta(-23.55, $row->latitude, 0.00001);
            $this->assertSame('landing-page', $row->source);
        } finally {
            $connection->statement('DROP TABLE IF EXISTS _test_values');
        }
    }

    public function test_where_and_count(): void
    {
        $this->seedRows(10);

        $count = DB::connection('clickhouse')
            ->table('_test_events')
            ->where('event', '=', 'click')
            ->count();

        $this->assertEquals(5, $count);
    }

    public function test_where_in(): void
    {
        $this->seedRows(10);

        $rows = DB::connection('clickhouse')
            ->table('_test_events')
            ->whereIn('id', [1, 2, 3])
            ->get();

        $this->assertCount(3, $rows);
    }

    public function test_where_between(): void
    {
        $this->seedRows(10);

        $rows = DB::connection('clickhouse')
            ->table('_test_events')
            ->whereBetween('score', [0.3, 0.7])
            ->get();

        $this->assertNotEmpty($rows);
    }

    public function test_group_by_and_having(): void
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

    public function test_order_by_and_limit(): void
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

    public function test_limit_and_offset(): void
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

    public function test_select_raw_with_aggregates(): void
    {
        $this->seedRows(10);

        $result = DB::connection('clickhouse')
            ->table('_test_events')
            ->selectRaw('min(score) as min_s, max(score) as max_s, avg(score) as avg_s')
            ->first();

        $this->assertNotNull($result);
        $this->assertLessThan($result->max_s, $result->min_s);
    }

    public function test_union_all(): void
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

    public function test_delete_requires_where(): void
    {
        $this->expectException(ClickHouseGrammarException::class);

        DB::connection('clickhouse')->table('_test_events')->delete();
    }

    public function test_delete_with_where(): void
    {
        $this->seedRows(10);

        DB::connection('clickhouse')
            ->table('_test_events')
            ->where('event', '=', 'click')
            ->mutationsSync()
            ->delete();

        $this->assertSame(
            0,
            DB::connection('clickhouse')->table('_test_events')->where('event', 'click')->count(),
        );
    }

    public function test_truncate(): void
    {
        $this->seedRows(10);

        DB::connection('clickhouse')->table('_test_events')->truncate();

        $this->assertEquals(0, DB::connection('clickhouse')->table('_test_events')->count());
    }

    public function test_update(): void
    {
        $this->seedRows(10);

        DB::connection('clickhouse')
            ->table('_test_events')
            ->where('id', '=', 1)
            ->mutationsSync()
            ->update(['event' => 'updated']);

        $this->assertSame(
            'updated',
            DB::connection('clickhouse')->table('_test_events')->where('id', 1)->value('event'),
        );
    }

    public function test_grouped_or_where(): void
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

    public function test_subquery_in_where_in(): void
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

    public function test_select_subquery(): void
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

    public function test_settings_applied(): void
    {
        $this->seedRows(10);

        $rows = DB::connection('clickhouse')
            ->table('_test_events')
            ->settings(['max_threads' => 1])
            ->get();

        $this->assertCount(10, $rows);
    }

    public function test_async_insert_settings_are_applied_to_insert(): void
    {
        DB::connection('clickhouse')
            ->table('_test_events')
            ->async(wait: true)
            ->insert([
                ['id' => 100, 'user_id' => 1, 'event' => 'async', 'score' => 1.0],
            ]);

        $this->assertSame(
            1,
            DB::connection('clickhouse')->table('_test_events')->where('id', 100)->count(),
        );
    }

    public function test_insert_using_applies_settings_before_select(): void
    {
        $connection = DB::connection('clickhouse');
        $source = $connection->query()
            ->fromRaw('numbers(3)')
            ->selectRaw("number + 300 AS id, 1 AS user_id, 'copied' AS event, 1.5 AS score");

        $inserted = $connection->table('_test_events')
            ->settings(['max_threads' => 1])
            ->insertUsing(['id', 'user_id', 'event', 'score'], $source);

        $this->assertSame(3, $inserted);
        $this->assertSame(
            3,
            $connection->table('_test_events')->where('event', 'copied')->count(),
        );
    }

    public function test_pre_where_accepts_a_trusted_expression_without_binding_it(): void
    {
        $this->seedRows(4);

        $this->assertSame(
            4,
            DB::connection('clickhouse')
                ->table('_test_events')
                ->preWhere('ts', '<=', DB::raw('now()'))
                ->count(),
        );
    }

    public function test_affecting_statement_reports_inserted_rows(): void
    {
        $count = DB::connection('clickhouse')->affectingStatement(
            'INSERT INTO _test_events (id, user_id, event, score) VALUES (?, ?, ?, ?), (?, ?, ?, ?)',
            [201, 1, 'one', 1.0, 202, 2, 'two', 2.0],
        );

        $this->assertSame(2, $count);
    }
}
