<?php

namespace ClickHouse\Laravel\Tests\Unit\Query;

use ClickHouse\Laravel\Exceptions\ClickHouseGrammarException;
use ClickHouse\Laravel\Query\ClickHouseQueryBuilder;
use ClickHouse\Laravel\Query\Settings;
use ClickHouse\Laravel\Tests\TestCase;
use Illuminate\Database\Query\Expression;

class ClickHouseQueryGrammarTest extends TestCase
{
    protected function builder(string $table = 'events'): ClickHouseQueryBuilder
    {
        return $this->clickhouse()->table($table);
    }

    protected function toSql(ClickHouseQueryBuilder $builder): string
    {
        return $builder->toSql();
    }

    public function test_select_basic(): void
    {
        $sql = $this->toSql($this->builder());
        $this->assertSame('select * from `events`', $sql);
    }

    public function test_select_columns(): void
    {
        $sql = $this->toSql($this->builder()->select('id', 'name'));
        $this->assertSame('select `id`, `name` from `events`', $sql);
    }

    public function test_select_with_final(): void
    {
        $sql = $this->toSql($this->builder()->final());
        $this->assertStringContainsString('from `events` FINAL', $sql);
    }

    public function test_final_can_be_disabled(): void
    {
        $sql = $this->toSql($this->builder()->final()->final(false));
        $this->assertStringNotContainsString('FINAL', $sql);
    }

    public function test_select_with_sample(): void
    {
        $sql = $this->toSql($this->builder()->sample(0.1));
        $this->assertStringContainsString('SAMPLE 0.1', $sql);
    }

    public function test_select_with_integer_sample(): void
    {
        $sql = $this->toSql($this->builder()->sample(10000));
        $this->assertStringContainsString('SAMPLE 10000', $sql);
    }

    public function test_select_with_sample_offset(): void
    {
        $sql = $this->toSql($this->builder()->sample(0.1, 0.5));

        $this->assertStringContainsString('SAMPLE 0.1 OFFSET 0.5', $sql);
    }

    public function test_select_with_final_and_sample(): void
    {
        $sql = $this->toSql($this->builder()->final()->sample(0.5));
        $this->assertStringContainsString('from `events` FINAL SAMPLE 0.5', $sql);
    }

    public function test_array_join(): void
    {
        $sql = $this->toSql($this->builder()->arrayJoin('tags'));
        $this->assertStringContainsString('ARRAY JOIN `tags`', $sql);
    }

    public function test_array_join_with_alias(): void
    {
        $sql = $this->toSql($this->builder()->arrayJoin('tags', 'tag'));
        $this->assertStringContainsString('ARRAY JOIN `tags` AS `tag`', $sql);
    }

    public function test_left_array_join(): void
    {
        $sql = $this->toSql($this->builder()->leftArrayJoin('items', 'item'));
        $this->assertStringContainsString('LEFT ARRAY JOIN `items` AS `item`', $sql);
    }

    public function test_multiple_array_joins(): void
    {
        $sql = $this->toSql(
            $this->builder()->arrayJoin('tags', 'tag')->leftArrayJoin('items', 'item')
        );
        $this->assertStringContainsString('ARRAY JOIN `tags` AS `tag`', $sql);
        $this->assertStringContainsString('LEFT ARRAY JOIN `items` AS `item`', $sql);
    }

    public function test_pre_where(): void
    {
        $sql = $this->toSql($this->builder()->preWhere('date', '>=', '2026-01-01'));
        $this->assertStringContainsString('prewhere `date` >= ?', $sql);
    }

    public function test_pre_where_appears_before_where(): void
    {
        $sql = $this->toSql(
            $this->builder()->preWhere('date', '>=', '2026-01-01')->where('user_id', '=', 1)
        );
        $prewherePos = strpos($sql, 'prewhere');
        $wherePos = strpos($sql, 'where `user_id`');

        $this->assertNotFalse($prewherePos);
        $this->assertGreaterThan($prewherePos, $wherePos);
    }

    public function test_pre_where_with_or(): void
    {
        $sql = $this->toSql(
            $this->builder()->preWhere('a', '=', 1)->orPreWhere('b', '=', 2)
        );
        $this->assertStringContainsString('prewhere `a` = ? OR `b` = ?', $sql);
    }

    public function test_pre_where_raw(): void
    {
        $sql = $this->toSql($this->builder()->preWhereRaw('date >= today()'));
        $this->assertStringContainsString('prewhere date >= today()', $sql);
    }

    public function test_or_pre_where_raw(): void
    {
        $sql = $this->toSql(
            $this->builder()
                ->preWhere('active', true)
                ->orPreWhereRaw('date >= today()'),
        );

        $this->assertStringContainsString(
            'prewhere `active` = ? OR date >= today()',
            $sql,
        );
    }

    public function test_pre_where_in(): void
    {
        $sql = $this->toSql($this->builder()->preWhereIn('status', [1, 2, 3]));
        $this->assertStringContainsString('prewhere `status` in (?, ?, ?)', $sql);
    }

    public function test_pre_where_not_in(): void
    {
        $sql = $this->toSql($this->builder()->preWhereNotIn('status', [1, 2]));
        $this->assertStringContainsString('prewhere `status` not in (?, ?)', $sql);
    }

    public function test_pre_where_between(): void
    {
        $sql = $this->toSql($this->builder()->preWhereBetween('age', [18, 65]));
        $this->assertStringContainsString('prewhere `age` between ? and ?', $sql);
    }

    public function test_pre_where_not_between(): void
    {
        $sql = $this->toSql($this->builder()->preWhereNotBetween('age', [18, 65]));

        $this->assertStringContainsString('prewhere `age` not between ? and ?', $sql);
    }

    public function test_explicit_pre_where_null_helpers(): void
    {
        $sql = $this->toSql(
            $this->builder()
                ->preWhereNull(['deleted_at', 'archived_at'])
                ->preWhereNotNull('created_at'),
        );

        $this->assertStringContainsString(
            'prewhere `deleted_at` is null AND `archived_at` is null AND `created_at` is not null',
            $sql,
        );
    }

    public function test_pre_where_null_uses_is_null_without_a_binding(): void
    {
        $builder = $this->builder()->preWhere('deleted_at', null);

        $this->assertStringContainsString(
            'prewhere `deleted_at` is null',
            $this->toSql($builder),
        );
        $this->assertSame([], $builder->getBindings());
    }

    public function test_pre_where_expression_is_not_bound(): void
    {
        $builder = $this->builder()->preWhere(
            'occurred_on',
            '>=',
            new Expression('today()'),
        );

        $this->assertStringContainsString(
            'prewhere `occurred_on` >= today()',
            $this->toSql($builder),
        );
        $this->assertSame([], $builder->getBindings());
    }

    public function test_pre_where_in_preserves_expression_placeholders(): void
    {
        $builder = $this->builder()->preWhereIn(
            'occurred_on',
            [new Expression('today()'), '2026-07-26'],
        );

        $this->assertStringContainsString(
            'prewhere `occurred_on` in (today(), ?)',
            $this->toSql($builder),
        );
        $this->assertSame(['2026-07-26'], $builder->getBindings());
    }

    public function test_empty_pre_where_in_compiles_to_false(): void
    {
        $builder = $this->builder()->preWhereIn('id', []);

        $this->assertStringContainsString('prewhere 0 = 1', $this->toSql($builder));
        $this->assertSame([], $builder->getBindings());
    }

    public function test_empty_pre_where_not_in_compiles_to_true(): void
    {
        $builder = $this->builder()->preWhereNotIn('id', []);

        $this->assertStringContainsString('prewhere 1 = 1', $this->toSql($builder));
        $this->assertSame([], $builder->getBindings());
    }

    public function test_limit_by(): void
    {
        $sql = $this->toSql($this->builder()->limitBy(1, 'user_id'));
        $this->assertStringContainsString('LIMIT 1 BY `user_id`', $sql);
    }

    public function test_limit_by_multiple_columns(): void
    {
        $sql = $this->toSql($this->builder()->limitBy(5, 'category', 'status'));
        $this->assertStringContainsString('LIMIT 5 BY `category`, `status`', $sql);
    }

    public function test_limit_by_appears_before_limit(): void
    {
        $sql = $this->toSql($this->builder()->limitBy(1, 'user_id')->limit(100));
        $limitByPos = strpos($sql, 'LIMIT 1 BY');
        $limitPos = strpos($sql, 'LIMIT 100');

        $this->assertGreaterThan($limitByPos, $limitPos);
    }

    public function test_any_left_join(): void
    {
        $sql = $this->toSql($this->builder()->anyLeftJoin('users', 'user_id'));
        $this->assertStringContainsString('ANY LEFT JOIN `users` USING (`user_id`)', $sql);
    }

    public function test_all_inner_join(): void
    {
        $sql = $this->toSql($this->builder()->allInnerJoin('dim', ['key1', 'key2']));
        $this->assertStringContainsString('ALL INNER JOIN `dim` USING (`key1`, `key2`)', $sql);
    }

    public function test_global_join(): void
    {
        $sql = $this->toSql($this->builder()->anyLeftJoin('users', 'user_id', global: true));
        $this->assertStringContainsString('GLOBAL ANY LEFT JOIN `users` USING (`user_id`)', $sql);
    }

    public function test_join_with_alias(): void
    {
        $sql = $this->toSql($this->builder()->anyLeftJoin('users', 'user_id', alias: 'u'));
        $this->assertStringContainsString('ANY LEFT JOIN `users` AS `u` USING (`user_id`)', $sql);
    }

    public function test_format(): void
    {
        $sql = $this->toSql($this->builder()->format('JSONEachRow'));
        $this->assertStringContainsString('FORMAT JSONEachRow', $sql);
    }

    public function test_format_csv(): void
    {
        $sql = $this->toSql($this->builder()->format('CSV'));
        $this->assertStringContainsString('FORMAT CSV', $sql);
    }

    public function test_no_format_by_default(): void
    {
        $sql = $this->toSql($this->builder());
        $this->assertStringNotContainsString('FORMAT', $sql);
    }

    public function test_select_with_settings(): void
    {
        $sql = $this->toSql($this->builder()->settings(['max_threads' => 4]));
        $this->assertStringContainsString('SETTINGS max_threads = 4', $sql);
    }

    public function test_multiple_settings(): void
    {
        $sql = $this->toSql($this->builder()->settings([
            'max_threads' => 4,
            'max_memory_usage' => 10000000000,
        ]));
        $this->assertStringContainsString('SETTINGS max_threads = 4, max_memory_usage = 10000000000', $sql);
    }

    public function test_settings_with_string_value(): void
    {
        $sql = $this->toSql($this->builder()->settings(['join_algorithm' => 'hash']));
        $this->assertStringContainsString("SETTINGS join_algorithm = 'hash'", $sql);
    }

    public function test_no_settings_when_empty(): void
    {
        $sql = $this->toSql($this->builder());
        $this->assertStringNotContainsString('SETTINGS', $sql);
    }

    public function test_compile_limit(): void
    {
        $sql = $this->toSql($this->builder()->limit(10));
        $this->assertStringContainsString('LIMIT 10', $sql);
    }

    public function test_compile_offset(): void
    {
        $sql = $this->toSql($this->builder()->offset(5));
        $this->assertStringContainsString('OFFSET 5', $sql);
    }

    public function test_compile_update(): void
    {
        $builder = $this->builder()->where('id', '=', 1);
        $grammar = $this->clickhouse()->getQueryGrammar();
        $sql = $grammar->compileUpdate($builder, ['status' => 'active']);

        $this->assertStringStartsWith('ALTER TABLE `events` UPDATE', $sql);
        $this->assertStringContainsString('`status` = ?', $sql);
        $this->assertStringContainsString('where `id` = ?', $sql);
    }

    public function test_compile_update_with_synchronous_mutation_setting(): void
    {
        $builder = $this->builder()->where('id', 1)->mutationsSync();
        $sql = $this->clickhouse()->getQueryGrammar()->compileUpdate(
            $builder,
            ['status' => 'active'],
        );

        $this->assertStringEndsWith('SETTINGS mutations_sync = 1', $sql);
    }

    public function test_update_without_where_throws(): void
    {
        $this->expectException(ClickHouseGrammarException::class);
        $this->clickhouse()->getQueryGrammar()->compileUpdate(
            $this->builder(),
            ['status' => 'active'],
        );
    }

    public function test_mutation_rejects_unsupported_order_and_limit_clauses(): void
    {
        $this->expectException(ClickHouseGrammarException::class);
        $this->expectExceptionMessage('ORDER BY');

        $this->clickhouse()->getQueryGrammar()->compileUpdate(
            $this->builder()->where('id', 1)->orderBy('id')->limit(1),
            ['status' => 'active'],
        );
    }

    public function test_compile_delete(): void
    {
        $builder = $this->builder()->where('id', '=', 1);
        $grammar = $this->clickhouse()->getQueryGrammar();
        $sql = $grammar->compileDelete($builder);

        $this->assertStringStartsWith('ALTER TABLE `events` DELETE', $sql);
        $this->assertStringContainsString('where `id` = ?', $sql);
    }

    public function test_compile_insert_includes_async_settings(): void
    {
        $builder = $this->builder()->async(wait: true);
        $sql = $this->clickhouse()->getQueryGrammar()->compileInsert($builder, [
            ['id' => 1, 'name' => 'Lucas'],
        ]);

        $this->assertStringContainsString(
            'SETTINGS async_insert = 1, wait_for_async_insert = 1 values',
            $sql,
        );
    }

    public function test_insert_on_cluster_is_rejected(): void
    {
        $this->expectException(ClickHouseGrammarException::class);
        $this->expectExceptionMessage('does not support ON CLUSTER');

        $this->clickhouse()->getQueryGrammar()->compileInsert(
            $this->builder()->onCluster('production'),
            [['id' => 1]],
        );
    }

    public function test_delete_without_where_throws(): void
    {
        $this->expectException(ClickHouseGrammarException::class);
        $grammar = $this->clickhouse()->getQueryGrammar();
        $grammar->compileDelete($this->builder());
    }

    public function test_compile_truncate(): void
    {
        $grammar = $this->clickhouse()->getQueryGrammar();
        $result = $grammar->compileTruncate($this->builder());
        $this->assertSame(['TRUNCATE TABLE `events`' => []], $result);
    }

    public function test_compile_truncate_on_cluster(): void
    {
        $grammar = $this->clickhouse()->getQueryGrammar();
        $result = $grammar->compileTruncate(
            $this->builder()->onCluster('production'),
        );

        $this->assertSame(
            ['TRUNCATE TABLE `events` ON CLUSTER `production`' => []],
            $result,
        );
    }

    public function test_compile_insert_using(): void
    {
        $grammar = $this->clickhouse()->getQueryGrammar();
        $sql = $grammar->compileInsertUsing(
            $this->builder(),
            ['id', 'name'],
            'select `id`, `name` from `other`'
        );

        $this->assertSame(
            'INSERT INTO `events` (`id`, `name`) select `id`, `name` from `other`',
            $sql
        );
    }

    public function test_compile_insert_using_without_a_column_list(): void
    {
        $grammar = $this->clickhouse()->getQueryGrammar();
        $sql = $grammar->compileInsertUsing(
            $this->builder()->settings(['max_threads' => 2]),
            [],
            'select * from `other`',
        );

        $this->assertSame(
            'INSERT INTO `events` SETTINGS max_threads = 2 select * from `other`',
            $sql,
        );
    }

    public function test_compile_insert_using_star_omits_the_column_list(): void
    {
        $sql = $this->clickhouse()->getQueryGrammar()->compileInsertUsing(
            $this->builder(),
            ['*'],
            'select * from `other`',
        );

        $this->assertSame('INSERT INTO `events` select * from `other`', $sql);
    }

    public function test_wrap_value_backticks(): void
    {
        $sql = $this->toSql($this->builder()->select('user_id'));
        $this->assertStringContainsString('`user_id`', $sql);
    }

    public function test_wrap_value_star(): void
    {
        $sql = $this->toSql($this->builder()->select('*'));
        $this->assertStringContainsString('select *', $sql);
        $this->assertStringNotContainsString('`*`', $sql);
    }

    public function test_complex_analytics_query(): void
    {
        $sql = $this->toSql(
            $this->clickhouse()->table('page_views')
                ->select('user_id', 'path')
                ->selectRaw('count(*) as views')
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
    }

    public function test_complex_array_join_with_filters(): void
    {
        $sql = $this->toSql(
            $this->clickhouse()->table('user_events')
                ->select('user_id', 'tag')
                ->selectRaw('count(*) as cnt')
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
    }

    public function test_complex_join_with_cluster_settings(): void
    {
        $sql = $this->toSql(
            $this->clickhouse()->table('orders')
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

    public function test_sample_with_prewhere_and_limit_by(): void
    {
        $sql = $this->toSql(
            $this->clickhouse()->table('metrics')
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

    public function test_async_insert_settings(): void
    {
        $sql = $this->toSql(
            $this->builder()->settings([
                'async_insert' => 1,
                'wait_for_async_insert' => 0,
            ])
        );

        $this->assertStringContainsString('SETTINGS async_insert = 1, wait_for_async_insert = 0', $sql);
    }

    public function test_with_fill_basic(): void
    {
        $sql = $this->toSql(
            $this->builder()->orderBy('bucket')->withFill()
        );
        $this->assertStringContainsString('order by `bucket` asc WITH FILL', $sql);
    }

    public function test_with_fill_from_to_step(): void
    {
        $sql = $this->toSql(
            $this->builder()
                ->orderBy('bucket')
                ->withFill(
                    from: new Expression("toDateTime64('2026-01-01', 3)"),
                    to: new Expression("toDateTime64('2026-01-02', 3)"),
                    step: new Expression('toIntervalMinute(5)'),
                )
        );
        $this->assertStringContainsString("WITH FILL FROM toDateTime64('2026-01-01', 3)", $sql);
        $this->assertStringContainsString("TO toDateTime64('2026-01-02', 3)", $sql);
        $this->assertStringContainsString('STEP toIntervalMinute(5)', $sql);
    }

    public function test_with_fill_step_only(): void
    {
        $sql = $this->toSql(
            $this->builder()->orderBy('id')->withFill(step: 1)
        );
        $this->assertStringContainsString('order by `id` asc WITH FILL STEP 1', $sql);
    }

    public function test_with_fill_preserves_float_precision(): void
    {
        $value = 0.12345678901234566;
        $sql = $this->toSql(
            $this->builder()->orderBy('score')->withFill(step: $value)
        );

        $this->assertStringContainsString(
            'STEP '.json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            $sql,
        );
    }

    public function test_with_fill_interpolate(): void
    {
        $sql = $this->toSql(
            $this->builder()
                ->orderBy('bucket')
                ->withFill(step: new Expression('toIntervalMinute(5)'))
                ->interpolate('cumulative')
        );
        $this->assertStringContainsString('WITH FILL STEP toIntervalMinute(5)', $sql);
        $this->assertStringContainsString('INTERPOLATE (`cumulative`)', $sql);
    }

    public function test_with_fill_interpolate_multiple_columns(): void
    {
        $sql = $this->toSql(
            $this->builder()
                ->orderBy('bucket')
                ->withFill(step: 1)
                ->interpolate('cumulative', new Expression('value AS 0'))
        );
        $this->assertStringContainsString('INTERPOLATE (`cumulative`, value AS 0)', $sql);
    }

    public function test_with_fill_per_column(): void
    {
        $sql = $this->toSql(
            $this->builder()
                ->orderBy('date')
                ->withFill(
                    from: new Expression("'2026-01-01'"),
                    to: new Expression("'2026-12-31'"),
                    step: 1,
                )
                ->orderBy('hour')
                ->withFill(from: 0, to: 23, step: 1)
        );
        $this->assertStringContainsString("`date` asc WITH FILL FROM '2026-01-01' TO '2026-12-31' STEP 1", $sql);
        $this->assertStringContainsString('`hour` asc WITH FILL FROM 0 TO 23 STEP 1', $sql);
    }

    public function test_order_without_fill_unchanged(): void
    {
        $sql = $this->toSql($this->builder()->orderBy('name', 'desc'));
        $this->assertStringContainsString('order by `name` desc', $sql);
        $this->assertStringNotContainsString('WITH FILL', $sql);
    }

    public function test_with_fill_raw(): void
    {
        $sql = $this->toSql(
            $this->builder()
                ->orderBy('bucket')
                ->withFillRaw("FROM toDateTime64('2026-01-01', 3) STEP toIntervalMinute(5)")
        );
        $this->assertStringContainsString("WITH FILL FROM toDateTime64('2026-01-01', 3) STEP toIntervalMinute(5)", $sql);
    }

    public function test_with_fill_time(): void
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

    public function test_with_fill_time_hourly(): void
    {
        $sql = $this->toSql(
            $this->builder()
                ->orderBy('bucket')
                ->withFillTime('2026-01-01', '2026-01-02', '1 hour')
        );
        $this->assertStringContainsString("WITH FILL FROM toDateTime('2026-01-01')", $sql);
        $this->assertStringContainsString('STEP toIntervalHour(1)', $sql);
    }

    public function test_with_fill_time_millisecond(): void
    {
        $sql = $this->toSql(
            $this->builder()
                ->orderBy('bucket')
                ->withFillTime('2026-03-24 10:00:00', '2026-03-24 10:01:00', '100 millisecond', precision: 3)
        );
        $this->assertStringContainsString('STEP toIntervalMillisecond(100)', $sql);
    }

    public function test_complex_time_series_query(): void
    {
        $sql = $this->toSql(
            $this->clickhouse()->table('metrics')
                ->selectRaw('toStartOfInterval(ts, toIntervalMillisecond(100)) AS bucket')
                ->selectRaw('count() AS cnt')
                ->selectRaw('sum(cnt) OVER (ORDER BY bucket) AS cumulative')
                ->preWhere('ts', '>=', '2026-03-24 00:00:00')
                ->preWhere('ts', '<=', '2026-03-24 23:59:59')
                ->groupByRaw('ALL')
                ->orderBy('bucket')
                ->withFill(
                    from: new Expression("toDateTime64('2026-03-24', 3)"),
                    to: new Expression("toDateTime64('2026-03-25', 3)"),
                    step: new Expression('toIntervalMillisecond(100)'),
                )
                ->interpolate('cumulative')
                ->settings(['max_threads' => 4])
        );

        $this->assertStringContainsString('WITH FILL', $sql);
        $this->assertStringContainsString('INTERPOLATE (`cumulative`)', $sql);
        $this->assertStringContainsString('SETTINGS max_threads = 4', $sql);
    }

    public function test_union_all(): void
    {
        $sql = $this->toSql(
            $this->builder('events')
                ->select('id')
                ->unionAll($this->builder('events_archive')->select('id'))
        );

        $this->assertStringContainsString('union all', strtolower($sql));
    }

    public function test_having(): void
    {
        $sql = $this->toSql(
            $this->builder()->select('user_id')
                ->selectRaw('count(*) as cnt')
                ->groupBy('user_id')
                ->having('cnt', '>', 10)
        );
        $this->assertStringContainsString('having', strtolower($sql));
    }

    public function test_grouped_or_where(): void
    {
        $sql = $this->toSql(
            $this->builder()->where(function ($q) {
                $q->where('status', '=', 1)->orWhere('status', '=', 2);
            })
        );
        $this->assertMatchesRegularExpression('/\(.*or.*\)/i', $sql);
    }

    public function test_where_in_with_subquery(): void
    {
        $sub = $this->builder('allowed_users')->select('user_id');
        $sql = $this->toSql(
            $this->builder('events')->whereIn('user_id', $sub)
        );
        $this->assertStringContainsString('where `user_id` in (select', strtolower($sql));
    }

    public function test_select_subquery(): void
    {
        $sub = $this->builder('config')->select('value')->where('key', '=', 'max_score');
        $sql = $this->toSql(
            $this->builder('events')->selectSub($sub, 'threshold')
        );
        $this->assertStringContainsString('(select', strtolower($sql));
        $this->assertStringContainsString('as `threshold`', strtolower($sql));
    }

    public function test_all_left_join(): void
    {
        $sql = $this->toSql($this->builder()->allLeftJoin('users', 'user_id'));
        $this->assertStringContainsString('ALL LEFT JOIN `users` USING (`user_id`)', $sql);
    }

    public function test_any_inner_join(): void
    {
        $sql = $this->toSql($this->builder()->anyInnerJoin('users', 'user_id'));
        $this->assertStringContainsString('ANY INNER JOIN `users` USING (`user_id`)', $sql);
    }

    public function test_delete_on_cluster(): void
    {
        $builder = $this->builder()->onCluster('my_cluster')->where('id', '=', 1);
        $grammar = $this->clickhouse()->getQueryGrammar();
        $sql = $grammar->compileDelete($builder);

        $this->assertStringContainsString('ON CLUSTER `my_cluster`', $sql);
        $this->assertStringContainsString('DELETE', $sql);
    }

    public function test_update_on_cluster(): void
    {
        $builder = $this->builder()->onCluster('my_cluster')->where('id', '=', 1);
        $grammar = $this->clickhouse()->getQueryGrammar();
        $sql = $grammar->compileUpdate($builder, ['status' => 'done']);

        $this->assertStringContainsString('ON CLUSTER `my_cluster`', $sql);
        $this->assertStringContainsString('UPDATE', $sql);
    }

    public function test_join_with_on_condition(): void
    {
        $sql = $this->toSql(
            $this->builder('orders')
                ->anyLeftJoin('users', on: [['orders.user_id', '=', 'users.id']])
        );
        $lower = strtolower($sql);
        $this->assertStringContainsString('any left join', $lower);
        $this->assertStringContainsString('on', $lower);
    }

    public function test_join_with_subquery(): void
    {
        $sub = $this->builder('users')->select('id', 'name')->where('active', '=', 1);
        $sql = $this->toSql(
            $this->builder('orders')->anyLeftJoin($sub, 'user_id', alias: 'u')
        );
        $lower = strtolower($sql);
        $this->assertStringContainsString('any left join (select', $lower);
        $this->assertStringContainsString('as `u`', $lower);
    }

    public function test_join_compilation_does_not_duplicate_bindings(): void
    {
        $sub = $this->builder('users')->select('id')->where('active', 1);
        $builder = $this->builder('orders')->anyLeftJoin($sub, 'user_id', alias: 'u');

        $builder->toSql();
        $firstBindings = $builder->getBindings();
        $builder->toSql();

        $this->assertSame([1], $firstBindings);
        $this->assertSame($firstBindings, $builder->getBindings());
    }

    public function test_click_house_join_bindings_precede_regular_join_and_pre_where_bindings(): void
    {
        $sub = $this->builder('users')->select('id')->where('active', 1);
        $builder = $this->builder('orders')
            ->where('tenant_id', 9)
            ->joinWhere('regions', 'regions.enabled', '=', 1)
            ->anyLeftJoin($sub, 'user_id', alias: 'u')
            ->preWhere('created_at', '>=', '2026-01-01');

        $this->assertSame([1, 1, '2026-01-01', 9], $builder->getBindings());
    }

    public function test_insert_format(): void
    {
        $grammar = $this->clickhouse()->getQueryGrammar();
        $sql = $grammar->compileInsertFormat($this->builder(), 'JSONEachRow');
        $this->assertSame('INSERT INTO `events` FORMAT JSONEachRow', $sql);
    }

    public function test_insert_format_with_columns(): void
    {
        $grammar = $this->clickhouse()->getQueryGrammar();
        $sql = $grammar->compileInsertFormat($this->builder(), 'CSV', ['id', 'name']);
        $this->assertSame('INSERT INTO `events` (`id`, `name`) FORMAT CSV', $sql);
    }

    public function test_settings_constants(): void
    {
        $sql = $this->toSql(
            $this->builder()->settings([
                Settings::MAX_THREADS => 8,
                Settings::JOIN_ALGORITHM => 'hash',
            ])
        );
        $this->assertStringContainsString("SETTINGS max_threads = 8, join_algorithm = 'hash'", $sql);
    }

    public function test_from_remote(): void
    {
        $sql = $this->toSql(
            $this->clickhouse()->query()->fromRemote('ch-replica:9000', 'analytics', 'events')
        );
        $this->assertStringContainsString("remote('ch-replica:9000', 'analytics', 'events'", $sql);
    }

    public function test_from_remote_escapes_quoted_credentials(): void
    {
        $sql = $this->toSql(
            $this->clickhouse()->query()->fromRemote(
                'ch-replica:9000',
                'analytics',
                'events',
                'user',
                "p'ass\\word",
            )
        );

        $this->assertStringContainsString("'p\\'ass\\\\word'", $sql);
        $this->assertStringNotContainsString("'p'ass", $sql);
    }

    public function test_from_merge(): void
    {
        $sql = $this->toSql(
            $this->clickhouse()->query()->fromMerge('analytics', '^events_.*')
        );
        $this->assertStringContainsString("merge('analytics', '^events_.*')", $sql);
    }

    public function test_remote_table_functions_reject_blank_required_arguments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->clickhouse()->query()->fromRemote('', 'analytics', 'events');
    }
}
