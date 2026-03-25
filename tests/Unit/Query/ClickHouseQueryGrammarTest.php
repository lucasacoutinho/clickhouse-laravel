<?php

namespace ClickHouse\Laravel\Tests\Unit\Query;

use ClickHouse\Laravel\Query\ClickHouseQueryBuilder;
use ClickHouse\Laravel\Query\ClickHouseQueryGrammar;
use ClickHouse\Laravel\Tests\Stubs\FakeConnection;
use ClickHouse\Laravel\Tests\TestCase;
use Illuminate\Database\Query\Processors\Processor;

class ClickHouseQueryGrammarTest extends TestCase
{
    protected ClickHouseQueryGrammar $grammar;
    protected FakeConnection $fakeConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grammar = $this->createQueryGrammar();
        $this->fakeConnection = new FakeConnection($this->grammar);
    }

    protected function builder(string $table = 'events'): ClickHouseQueryBuilder
    {
        $builder = new ClickHouseQueryBuilder($this->fakeConnection, $this->grammar, new Processor());
        $builder->from($table);

        return $builder;
    }

    protected function toSql(ClickHouseQueryBuilder $builder): string
    {
        return $builder->toSql();
    }

    public function testSelectBasic(): void
    {
        $sql = $this->toSql($this->builder());
        $this->assertSame('select * from `events`', $sql);
    }

    public function testSelectColumns(): void
    {
        $sql = $this->toSql($this->builder()->select('id', 'name'));
        $this->assertSame('select `id`, `name` from `events`', $sql);
    }

    public function testSelectWithFinal(): void
    {
        $sql = $this->toSql($this->builder()->final());
        $this->assertStringContainsString('from `events` FINAL', $sql);
    }

    public function testFinalCanBeDisabled(): void
    {
        $sql = $this->toSql($this->builder()->final()->final(false));
        $this->assertStringNotContainsString('FINAL', $sql);
    }

    public function testSelectWithSample(): void
    {
        $sql = $this->toSql($this->builder()->sample(0.1));
        $this->assertStringContainsString('SAMPLE 0.1', $sql);
    }

    public function testSelectWithIntegerSample(): void
    {
        $sql = $this->toSql($this->builder()->sample(10000));
        $this->assertStringContainsString('SAMPLE 10000', $sql);
    }

    public function testSelectWithFinalAndSample(): void
    {
        $sql = $this->toSql($this->builder()->final()->sample(0.5));
        $this->assertStringContainsString('from `events` FINAL SAMPLE 0.5', $sql);
    }

    public function testArrayJoin(): void
    {
        $sql = $this->toSql($this->builder()->arrayJoin('tags'));
        $this->assertStringContainsString('ARRAY JOIN `tags`', $sql);
    }

    public function testArrayJoinWithAlias(): void
    {
        $sql = $this->toSql($this->builder()->arrayJoin('tags', 'tag'));
        $this->assertStringContainsString('ARRAY JOIN `tags` AS `tag`', $sql);
    }

    public function testLeftArrayJoin(): void
    {
        $sql = $this->toSql($this->builder()->leftArrayJoin('items', 'item'));
        $this->assertStringContainsString('LEFT ARRAY JOIN `items` AS `item`', $sql);
    }

    public function testMultipleArrayJoins(): void
    {
        $sql = $this->toSql(
            $this->builder()->arrayJoin('tags', 'tag')->leftArrayJoin('items', 'item')
        );
        $this->assertStringContainsString('ARRAY JOIN `tags` AS `tag`', $sql);
        $this->assertStringContainsString('LEFT ARRAY JOIN `items` AS `item`', $sql);
    }

    public function testPreWhere(): void
    {
        $sql = $this->toSql($this->builder()->preWhere('date', '>=', '2026-01-01'));
        $this->assertStringContainsString('prewhere `date` >= ?', $sql);
    }

    public function testPreWhereAppearsBeforeWhere(): void
    {
        $sql = $this->toSql(
            $this->builder()->preWhere('date', '>=', '2026-01-01')->where('user_id', '=', 1)
        );
        $prewherePos = strpos($sql, 'prewhere');
        $wherePos = strpos($sql, 'where `user_id`');

        $this->assertNotFalse($prewherePos);
        $this->assertGreaterThan($prewherePos, $wherePos);
    }

    public function testPreWhereWithOr(): void
    {
        $sql = $this->toSql(
            $this->builder()->preWhere('a', '=', 1)->orPreWhere('b', '=', 2)
        );
        $this->assertStringContainsString('prewhere `a` = ? OR `b` = ?', $sql);
    }

    public function testPreWhereRaw(): void
    {
        $sql = $this->toSql($this->builder()->preWhereRaw('date >= today()'));
        $this->assertStringContainsString('prewhere date >= today()', $sql);
    }

    public function testPreWhereIn(): void
    {
        $sql = $this->toSql($this->builder()->preWhereIn('status', [1, 2, 3]));
        $this->assertStringContainsString('prewhere `status` in (?, ?, ?)', $sql);
    }

    public function testPreWhereNotIn(): void
    {
        $sql = $this->toSql($this->builder()->preWhereNotIn('status', [1, 2]));
        $this->assertStringContainsString('prewhere `status` not in (?, ?)', $sql);
    }

    public function testPreWhereBetween(): void
    {
        $sql = $this->toSql($this->builder()->preWhereBetween('age', [18, 65]));
        $this->assertStringContainsString('prewhere `age` between ? and ?', $sql);
    }

    public function testLimitBy(): void
    {
        $sql = $this->toSql($this->builder()->limitBy(1, 'user_id'));
        $this->assertStringContainsString('LIMIT 1 BY `user_id`', $sql);
    }

    public function testLimitByMultipleColumns(): void
    {
        $sql = $this->toSql($this->builder()->limitBy(5, 'category', 'status'));
        $this->assertStringContainsString('LIMIT 5 BY `category`, `status`', $sql);
    }

    public function testLimitByAppearsBeforeLimit(): void
    {
        $sql = $this->toSql($this->builder()->limitBy(1, 'user_id')->limit(100));
        $limitByPos = strpos($sql, 'LIMIT 1 BY');
        $limitPos = strpos($sql, 'LIMIT 100');

        $this->assertGreaterThan($limitByPos, $limitPos);
    }

    public function testAnyLeftJoin(): void
    {
        $sql = $this->toSql($this->builder()->anyLeftJoin('users', 'user_id'));
        $this->assertStringContainsString('ANY LEFT JOIN `users` USING (`user_id`)', $sql);
    }

    public function testAllInnerJoin(): void
    {
        $sql = $this->toSql($this->builder()->allInnerJoin('dim', ['key1', 'key2']));
        $this->assertStringContainsString('ALL INNER JOIN `dim` USING (`key1`, `key2`)', $sql);
    }

    public function testGlobalJoin(): void
    {
        $sql = $this->toSql($this->builder()->anyLeftJoin('users', 'user_id', global: true));
        $this->assertStringContainsString('GLOBAL ANY LEFT JOIN `users` USING (`user_id`)', $sql);
    }

    public function testJoinWithAlias(): void
    {
        $sql = $this->toSql($this->builder()->anyLeftJoin('users', 'user_id', alias: 'u'));
        $this->assertStringContainsString('ANY LEFT JOIN `users` AS `u` USING (`user_id`)', $sql);
    }

    public function testFormat(): void
    {
        $sql = $this->toSql($this->builder()->format('JSONEachRow'));
        $this->assertStringContainsString('FORMAT JSONEachRow', $sql);
    }

    public function testFormatCsv(): void
    {
        $sql = $this->toSql($this->builder()->format('CSV'));
        $this->assertStringContainsString('FORMAT CSV', $sql);
    }

    public function testNoFormatByDefault(): void
    {
        $sql = $this->toSql($this->builder());
        $this->assertStringNotContainsString('FORMAT', $sql);
    }

    public function testSelectWithSettings(): void
    {
        $sql = $this->toSql($this->builder()->settings(['max_threads' => 4]));
        $this->assertStringContainsString('SETTINGS max_threads = 4', $sql);
    }

    public function testMultipleSettings(): void
    {
        $sql = $this->toSql($this->builder()->settings([
            'max_threads' => 4,
            'max_memory_usage' => 10000000000,
        ]));
        $this->assertStringContainsString('SETTINGS max_threads = 4, max_memory_usage = 10000000000', $sql);
    }

    public function testSettingsWithStringValue(): void
    {
        $sql = $this->toSql($this->builder()->settings(['join_algorithm' => 'hash']));
        $this->assertStringContainsString("SETTINGS join_algorithm = 'hash'", $sql);
    }

    public function testNoSettingsWhenEmpty(): void
    {
        $sql = $this->toSql($this->builder());
        $this->assertStringNotContainsString('SETTINGS', $sql);
    }

    public function testCompileLimit(): void
    {
        $sql = $this->toSql($this->builder()->limit(10));
        $this->assertStringContainsString('LIMIT 10', $sql);
    }

    public function testCompileOffset(): void
    {
        $sql = $this->toSql($this->builder()->offset(5));
        $this->assertStringContainsString('OFFSET 5', $sql);
    }

    public function testCompileUpdate(): void
    {
        $builder = $this->builder()->where('id', '=', 1);
        $sql = $this->grammar->compileUpdate($builder, ['status' => 'active']);

        $this->assertStringStartsWith('ALTER TABLE `events` UPDATE', $sql);
        $this->assertStringContainsString('`status` = ?', $sql);
        $this->assertStringContainsString('where `id` = ?', $sql);
    }

    public function testCompileDelete(): void
    {
        $builder = $this->builder()->where('id', '=', 1);
        $sql = $this->grammar->compileDelete($builder);

        $this->assertStringStartsWith('ALTER TABLE `events` DELETE', $sql);
        $this->assertStringContainsString('where `id` = ?', $sql);
    }

    public function testDeleteWithoutWhereThrows(): void
    {
        $this->expectException(\ClickHouse\Laravel\Exceptions\ClickHouseGrammarException::class);
        $this->grammar->compileDelete($this->builder());
    }

    public function testCompileTruncate(): void
    {
        $result = $this->grammar->compileTruncate($this->builder());
        $this->assertSame(['TRUNCATE TABLE `events`' => []], $result);
    }

    public function testCompileInsertUsing(): void
    {
        $sql = $this->grammar->compileInsertUsing(
            $this->builder(),
            ['id', 'name'],
            'select `id`, `name` from `other`'
        );

        $this->assertSame(
            'INSERT INTO `events` (`id`, `name`) select `id`, `name` from `other`',
            $sql
        );
    }

    public function testWrapValueBackticks(): void
    {
        $sql = $this->toSql($this->builder()->select('user_id'));
        $this->assertStringContainsString('`user_id`', $sql);
    }

    public function testWrapValueStar(): void
    {
        $sql = $this->toSql($this->builder()->select('*'));
        $this->assertStringContainsString('select *', $sql);
        $this->assertStringNotContainsString('`*`', $sql);
    }

    public function testComplexAnalyticsQuery(): void
    {
        $sql = $this->toSql(
            $this->builder('page_views')
                ->select('user_id', 'path', 'count(*) as views')
                ->final()
                ->preWhere('date', '>=', '2026-01-01')
                ->where('site_id', '=', 42)
                ->groupBy('user_id', 'path')
                ->limitBy(3, 'user_id')
                ->orderBy('views', 'desc')
                ->limit(1000)
                ->settings(['max_threads' => 8])
        );

        $this->assertStringContainsString('from `page_views` FINAL', $sql);
        $this->assertStringContainsString('prewhere `date` >= ?', $sql);
        $this->assertStringContainsString('where `site_id` = ?', $sql);
        $this->assertStringContainsString('group by `user_id`, `path`', $sql);
        $this->assertStringContainsString('LIMIT 3 BY `user_id`', $sql);
        $this->assertStringContainsString('order by `views` desc', $sql);
        $this->assertStringContainsString('LIMIT 1000', $sql);
        $this->assertStringContainsString('SETTINGS max_threads = 8', $sql);

        // Verify clause ordering
        $positions = [
            'FINAL'    => strpos($sql, 'FINAL'),
            'prewhere' => strpos($sql, 'prewhere'),
            'where'    => strpos($sql, 'where `site_id`'),
            'group by' => strpos($sql, 'group by'),
            'LIMIT BY' => strpos($sql, 'LIMIT 3 BY'),
            'LIMIT'    => strpos($sql, 'LIMIT 1000'),
            'SETTINGS' => strpos($sql, 'SETTINGS'),
        ];

        $prev = 0;
        foreach ($positions as $clause => $pos) {
            $this->assertGreaterThan($prev, $pos, "Clause '{$clause}' is in wrong position");
            $prev = $pos;
        }
    }

    public function testComplexArrayJoinWithFilters(): void
    {
        $sql = $this->toSql(
            $this->builder('user_events')
                ->select('user_id', 'tag', 'count(*) as cnt')
                ->arrayJoin('tags', 'tag')
                ->preWhere('date', '>=', '2026-03-01')
                ->where('event_type', '=', 'click')
                ->groupBy('user_id', 'tag')
                ->orderBy('cnt', 'desc')
                ->limit(100)
                ->format('JSONEachRow')
        );

        $this->assertStringContainsString('ARRAY JOIN `tags` AS `tag`', $sql);
        $this->assertStringContainsString('prewhere', $sql);
        $this->assertStringContainsString('where', $sql);
        $this->assertStringContainsString('FORMAT JSONEachRow', $sql);

        // ARRAY JOIN after FROM, prewhere after ARRAY JOIN
        $arrayPos = strpos($sql, 'ARRAY JOIN');
        $prewherePos = strpos($sql, 'prewhere');
        $formatPos = strpos($sql, 'FORMAT');
        $this->assertGreaterThan($arrayPos, $prewherePos);
        $this->assertGreaterThan($prewherePos, $formatPos);
    }

    public function testComplexJoinWithClusterSettings(): void
    {
        $sql = $this->toSql(
            $this->builder('orders')
                ->select('orders.id', 'users.name', 'orders.amount')
                ->anyLeftJoin('users', 'user_id', global: true)
                ->preWhere('orders.date', '>=', '2026-01-01')
                ->where('orders.amount', '>', 100)
                ->orderBy('orders.amount', 'desc')
                ->limit(50)
                ->settings(['max_threads' => 4, 'join_algorithm' => 'hash'])
        );

        $this->assertStringContainsString('GLOBAL ANY LEFT JOIN `users` USING (`user_id`)', $sql);
        $this->assertStringContainsString('prewhere', $sql);
        $this->assertStringContainsString("SETTINGS max_threads = 4, join_algorithm = 'hash'", $sql);
    }

    public function testSampleWithPrewhereAndLimitBy(): void
    {
        $sql = $this->toSql(
            $this->builder('metrics')
                ->sample(0.1)
                ->preWhere('date', '=', '2026-03-24')
                ->where('metric_name', '=', 'cpu_usage')
                ->limitBy(1, 'host')
                ->limit(100)
        );

        $this->assertStringContainsString('SAMPLE 0.1', $sql);
        $this->assertStringContainsString('prewhere', $sql);
        $this->assertStringContainsString('LIMIT 1 BY `host`', $sql);
        $this->assertStringContainsString('LIMIT 100', $sql);
    }

    public function testAsyncInsertSettings(): void
    {
        $sql = $this->toSql(
            $this->builder()->settings([
                'async_insert' => 1,
                'wait_for_async_insert' => 0,
            ])
        );

        $this->assertStringContainsString('SETTINGS async_insert = 1, wait_for_async_insert = 0', $sql);
    }

    public function testWithFillBasic(): void
    {
        $sql = $this->toSql(
            $this->builder()->orderBy('bucket')->withFill()
        );
        $this->assertStringContainsString('order by `bucket` asc WITH FILL', $sql);
    }

    public function testWithFillFromToStep(): void
    {
        $sql = $this->toSql(
            $this->builder()
                ->orderBy('bucket')
                ->withFill(
                    from: "toDateTime64('2026-01-01', 3)",
                    to: "toDateTime64('2026-01-02', 3)",
                    step: "toIntervalMinute(5)",
                )
        );
        $this->assertStringContainsString("WITH FILL FROM toDateTime64('2026-01-01', 3)", $sql);
        $this->assertStringContainsString("TO toDateTime64('2026-01-02', 3)", $sql);
        $this->assertStringContainsString('STEP toIntervalMinute(5)', $sql);
    }

    public function testWithFillStepOnly(): void
    {
        $sql = $this->toSql(
            $this->builder()->orderBy('id')->withFill(step: '1')
        );
        $this->assertStringContainsString('order by `id` asc WITH FILL STEP 1', $sql);
    }

    public function testWithFillInterpolate(): void
    {
        $sql = $this->toSql(
            $this->builder()
                ->orderBy('bucket')
                ->withFill(step: "toIntervalMinute(5)")
                ->interpolate('cumulative')
        );
        $this->assertStringContainsString('WITH FILL STEP toIntervalMinute(5)', $sql);
        $this->assertStringContainsString('INTERPOLATE (cumulative)', $sql);
    }

    public function testWithFillInterpolateMultipleColumns(): void
    {
        $sql = $this->toSql(
            $this->builder()
                ->orderBy('bucket')
                ->withFill(step: '1')
                ->interpolate('cumulative', 'value AS 0')
        );
        $this->assertStringContainsString('INTERPOLATE (cumulative, value AS 0)', $sql);
    }

    public function testWithFillPerColumn(): void
    {
        $sql = $this->toSql(
            $this->builder()
                ->orderBy('date')
                ->withFill(from: "'2026-01-01'", to: "'2026-12-31'", step: '1')
                ->orderBy('hour')
                ->withFill(from: '0', to: '23', step: '1')
        );
        $this->assertStringContainsString("`date` asc WITH FILL FROM '2026-01-01' TO '2026-12-31' STEP 1", $sql);
        $this->assertStringContainsString('`hour` asc WITH FILL FROM 0 TO 23 STEP 1', $sql);
    }

    public function testOrderWithoutFillUnchanged(): void
    {
        $sql = $this->toSql(
            $this->builder()->orderBy('name', 'desc')
        );
        $this->assertStringContainsString('order by `name` desc', $sql);
        $this->assertStringNotContainsString('WITH FILL', $sql);
    }

    public function testWithFillRaw(): void
    {
        $sql = $this->toSql(
            $this->builder()
                ->orderBy('bucket')
                ->withFillRaw("FROM toDateTime64('2026-01-01', 3) STEP toIntervalMinute(5)")
        );
        $this->assertStringContainsString("WITH FILL FROM toDateTime64('2026-01-01', 3) STEP toIntervalMinute(5)", $sql);
    }

    public function testWithFillTime(): void
    {
        $sql = $this->toSql(
            $this->builder()
                ->orderBy('bucket')
                ->withFillTime('2026-01-01', '2026-01-02', '5 minute', precision: 3)
        );
        $this->assertStringContainsString("WITH FILL FROM toDateTime64('2026-01-01', 3)", $sql);
        $this->assertStringContainsString("TO toDateTime64('2026-01-02', 3)", $sql);
        $this->assertStringContainsString('STEP toIntervalMinute(5)', $sql);
    }

    public function testWithFillTimeHourly(): void
    {
        $sql = $this->toSql(
            $this->builder()
                ->orderBy('bucket')
                ->withFillTime('2026-01-01', '2026-01-02', '1 hour')
        );
        $this->assertStringContainsString("WITH FILL FROM toDateTime('2026-01-01')", $sql);
        $this->assertStringContainsString('STEP toIntervalHour(1)', $sql);
    }

    public function testWithFillTimeMillisecond(): void
    {
        $sql = $this->toSql(
            $this->builder()
                ->orderBy('bucket')
                ->withFillTime('2026-03-24 10:00:00', '2026-03-24 10:01:00', '100 millisecond', precision: 3)
        );
        $this->assertStringContainsString('STEP toIntervalMillisecond(100)', $sql);
    }

    public function testComplexTimeSeriesQuery(): void
    {
        $sql = $this->toSql(
            $this->builder('metrics')
                ->selectRaw("toStartOfInterval(ts, toIntervalMillisecond(100)) AS bucket")
                ->selectRaw("count() AS cnt")
                ->selectRaw("sum(cnt) OVER (ORDER BY bucket) AS cumulative")
                ->preWhere('ts', '>=', '2026-03-24 00:00:00')
                ->preWhere('ts', '<=', '2026-03-24 23:59:59')
                ->groupByRaw('ALL')
                ->orderBy('bucket')
                ->withFill(
                    from: "toDateTime64('2026-03-24', 3)",
                    to: "toDateTime64('2026-03-25', 3)",
                    step: "toIntervalMillisecond(100)",
                )
                ->interpolate('cumulative')
                ->settings(['max_threads' => 4])
        );

        $this->assertStringContainsString('toStartOfInterval(ts, toIntervalMillisecond(100)) AS bucket', $sql);
        $this->assertStringContainsString('sum(cnt) OVER (ORDER BY bucket) AS cumulative', $sql);
        $this->assertStringContainsString('prewhere `ts` >= ?', $sql);
        $this->assertStringContainsString('group by ALL', $sql);
        $this->assertStringContainsString('WITH FILL', $sql);
        $this->assertStringContainsString('INTERPOLATE (cumulative)', $sql);
        $this->assertStringContainsString('SETTINGS max_threads = 4', $sql);

        $withFillPos = strpos($sql, 'WITH FILL');
        $interpolatePos = strpos($sql, 'INTERPOLATE');
        $settingsPos = strpos($sql, 'SETTINGS');
        $this->assertGreaterThan($withFillPos, $interpolatePos);
        $this->assertGreaterThan($interpolatePos, $settingsPos);
    }

    public function testUnionAll(): void
    {
        $sql = $this->toSql(
            $this->builder('events')
                ->select('id')
                ->unionAll($this->builder('events_archive')->select('id'))
        );

        $this->assertStringContainsString('union all', strtolower($sql));
        $this->assertStringContainsString('`events`', $sql);
        $this->assertStringContainsString('`events_archive`', $sql);
    }

    public function testUnionAllChained(): void
    {
        $sql = $this->toSql(
            $this->builder('t1')
                ->unionAll($this->builder('t2'))
                ->unionAll($this->builder('t3'))
        );

        $this->assertEquals(2, substr_count(strtolower($sql), 'union all'));
    }

    public function testHaving(): void
    {
        $sql = $this->toSql(
            $this->builder()->select('user_id')
                ->selectRaw('count(*) as cnt')
                ->groupBy('user_id')
                ->having('cnt', '>', 10)
        );
        $this->assertStringContainsString('having', strtolower($sql));
    }

    public function testOrHaving(): void
    {
        $sql = $this->toSql(
            $this->builder()
                ->groupBy('user_id')
                ->having('a', '=', 1)
                ->orHaving('b', '=', 2)
        );
        $this->assertStringContainsString('having', strtolower($sql));
        $this->assertStringContainsString('or', strtolower($sql));
    }

    public function testGroupedOrWhere(): void
    {
        $sql = $this->toSql(
            $this->builder()->where(function ($q) {
                $q->where('status', '=', 1)->orWhere('status', '=', 2);
            })
        );
        $this->assertMatchesRegularExpression('/\(.*or.*\)/i', $sql);
    }

    public function testOrWhereRaw(): void
    {
        $sql = $this->toSql(
            $this->builder()->whereRaw('a = 1')->orWhereRaw('b = 2')
        );
        $this->assertStringContainsString('a = 1 or b = 2', strtolower($sql));
    }

    public function testWhereInWithSubquery(): void
    {
        $sub = $this->builder('allowed_users')->select('user_id');
        $sql = $this->toSql(
            $this->builder('events')->whereIn('user_id', $sub)
        );
        $this->assertStringContainsString('where `user_id` in (select', strtolower($sql));
    }

    public function testSelectSubquery(): void
    {
        $sub = $this->builder('config')->select('value')->where('key', '=', 'max_score');
        $sql = $this->toSql(
            $this->builder('events')->selectSub($sub, 'threshold')
        );
        $this->assertStringContainsString('(select', strtolower($sql));
        $this->assertStringContainsString('as `threshold`', strtolower($sql));
    }

    public function testWhereNotBetween(): void
    {
        $sql = $this->toSql($this->builder()->whereNotBetween('age', [18, 65]));
        $this->assertStringContainsString('not between', strtolower($sql));
    }

    public function testAllLeftJoin(): void
    {
        $sql = $this->toSql($this->builder()->allLeftJoin('users', 'user_id'));
        $this->assertStringContainsString('ALL LEFT JOIN `users` USING (`user_id`)', $sql);
    }

    public function testAnyInnerJoin(): void
    {
        $sql = $this->toSql($this->builder()->anyInnerJoin('users', 'user_id'));
        $this->assertStringContainsString('ANY INNER JOIN `users` USING (`user_id`)', $sql);
    }

    public function testComplexSubqueryJoinAnalytics(): void
    {
        $daily = $this->builder('events')
            ->selectRaw('user_id, toDate(ts) as day, count(*) as cnt')
            ->preWhere('ts', '>=', '2026-01-01')
            ->groupBy('user_id', 'day');

        $sql = $this->toSql(
            $this->builder('users')
                ->select('users.name', 'stats.day', 'stats.cnt')
                ->anyLeftJoin('users', 'user_id')
                ->where('stats.cnt', '>', 100)
                ->orderBy('stats.cnt', 'desc')
                ->limitBy(3, 'user_id')
                ->limit(1000)
                ->format('JSONEachRow')
                ->settings(['max_threads' => 8])
        );

        $this->assertStringContainsString('ANY LEFT JOIN', $sql);
        $this->assertStringContainsString('LIMIT 3 BY `user_id`', $sql);
        $this->assertStringContainsString('FORMAT JSONEachRow', $sql);
        $this->assertStringContainsString('SETTINGS', $sql);
    }

    public function testDeleteOnCluster(): void
    {
        $builder = $this->builder()->onCluster('my_cluster')->where('id', '=', 1);
        $sql = $this->grammar->compileDelete($builder);

        $this->assertStringContainsString('ON CLUSTER my_cluster', $sql);
        $this->assertStringContainsString('DELETE', $sql);
        $this->assertLessThan(strpos($sql, 'DELETE'), strpos($sql, 'ON CLUSTER'));
    }

    public function testUpdateOnCluster(): void
    {
        $builder = $this->builder()->onCluster('my_cluster')->where('id', '=', 1);
        $sql = $this->grammar->compileUpdate($builder, ['status' => 'done']);

        $this->assertStringContainsString('ON CLUSTER my_cluster', $sql);
        $this->assertStringContainsString('UPDATE', $sql);
        $this->assertLessThan(strpos($sql, 'UPDATE'), strpos($sql, 'ON CLUSTER'));
    }

    public function testJoinWithOnCondition(): void
    {
        $sql = $this->toSql(
            $this->builder('orders')
                ->anyLeftJoin('users', on: [['orders.user_id', '=', 'users.id']])
        );
        $this->assertStringContainsString('ANY LEFT JOIN `users` ON', $sql);
        $this->assertStringContainsString('`orders`.`user_id` = `users`.`id`', $sql);
    }

    public function testJoinWithMultipleOnConditions(): void
    {
        $sql = $this->toSql(
            $this->builder('orders')
                ->allInnerJoin('users', on: [
                    ['orders.user_id', '=', 'users.id'],
                    ['orders.tenant_id', '=', 'users.tenant_id'],
                ])
        );
        $this->assertStringContainsString('ON `orders`.`user_id` = `users`.`id` AND `orders`.`tenant_id` = `users`.`tenant_id`', $sql);
    }

    public function testJoinWithSubquery(): void
    {
        $sub = $this->builder('users')->select('id', 'name')->where('active', '=', 1);
        $sql = $this->toSql(
            $this->builder('orders')
                ->anyLeftJoin($sub, 'user_id', alias: 'u')
        );
        $lower = strtolower($sql);
        $this->assertStringContainsString('any left join (select', $lower);
        $this->assertStringContainsString('as `u`', $lower);
        $this->assertStringContainsString('using (`user_id`)', $lower);
    }

    public function testJoinSubqueryWithOnCondition(): void
    {
        $sub = $this->builder('users')->select('id', 'name');
        $sql = $this->toSql(
            $this->builder('orders')
                ->allInnerJoin($sub, alias: 'u', on: [['orders.user_id', '=', 'u.id']])
        );
        $lower = strtolower($sql);
        $this->assertStringContainsString('all inner join (select', $lower);
        $this->assertStringContainsString('as `u`', $lower);
        $this->assertStringContainsString('on `orders`.`user_id` = `u`.`id`', $lower);
    }

    public function testInsertFormat(): void
    {
        $sql = $this->grammar->compileInsertFormat($this->builder(), 'JSONEachRow');
        $this->assertSame('INSERT INTO `events` FORMAT JSONEachRow', $sql);
    }

    public function testInsertFormatWithColumns(): void
    {
        $sql = $this->grammar->compileInsertFormat($this->builder(), 'CSV', ['id', 'name']);
        $this->assertSame('INSERT INTO `events` (`id`, `name`) FORMAT CSV', $sql);
    }

    public function testSettingsConstants(): void
    {
        $sql = $this->toSql(
            $this->builder()->settings([
                \ClickHouse\Laravel\Query\Settings::MAX_THREADS => 8,
                \ClickHouse\Laravel\Query\Settings::JOIN_ALGORITHM => 'hash',
            ])
        );
        $this->assertStringContainsString("SETTINGS max_threads = 8, join_algorithm = 'hash'", $sql);
    }

    public function testFromRemote(): void
    {
        $sql = $this->toSql(
            $this->builder()->fromRemote('ch-replica:9000', 'analytics', 'events')
        );
        $this->assertStringContainsString("remote('ch-replica:9000', 'analytics', 'events'", $sql);
    }

    public function testFromMerge(): void
    {
        $sql = $this->toSql(
            $this->builder()->fromMerge('analytics', '^events_.*')
        );
        $this->assertStringContainsString("merge('analytics', '^events_.*')", $sql);
    }
}
