<?php

namespace ClickHouse\Laravel\Tests\Unit\Query;

use ClickHouse\Laravel\Query\ClickHouseQueryBuilder;
use ClickHouse\Laravel\Tests\TestCase;
use Illuminate\Database\Query\Expression;

class ClickHouseQueryBuilderTest extends TestCase
{
    protected function builder(): ClickHouseQueryBuilder
    {
        return $this->clickhouse()->query();
    }

    public function test_final_sets_flag(): void
    {
        $this->assertTrue($this->builder()->final()->useFinal);
    }

    public function test_final_can_be_disabled(): void
    {
        $this->assertFalse($this->builder()->final()->final(false)->useFinal);
    }

    public function test_final_default_is_false(): void
    {
        $this->assertFalse($this->builder()->useFinal);
    }

    public function test_sample_sets_clause(): void
    {
        $this->assertSame('0.1', $this->builder()->sample(0.1)->sampleClause);
    }

    public function test_sample_with_integer(): void
    {
        $this->assertSame('10000', $this->builder()->sample(10000)->sampleClause);
    }

    public function test_sample_preserves_float_precision(): void
    {
        $value = 0.12345678901234566;

        $this->assertSame(
            json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            $this->builder()->sample($value)->sampleClause,
        );
    }

    public function test_sample_default_is_null(): void
    {
        $this->assertNull($this->builder()->sampleClause);
    }

    public function test_sample_accepts_a_fractional_offset(): void
    {
        $builder = $this->builder()->sample(0.1, 0.5);

        $this->assertSame('0.1', $builder->sampleClause);
        $this->assertSame('0.5', $builder->sampleOffsetClause);
    }

    public function test_array_join_adds_to_array(): void
    {
        $b = $this->builder()->arrayJoin('tags');
        $this->assertCount(1, $b->arrayJoins);
        $this->assertSame([
            'column' => 'tags',
            'alias' => null,
            'type' => 'inner',
        ], $b->arrayJoins[0]);
    }

    public function test_array_join_with_alias(): void
    {
        $this->assertSame('tag', $this->builder()->arrayJoin('tags', 'tag')->arrayJoins[0]['alias']);
    }

    public function test_left_array_join_helper(): void
    {
        $b = $this->builder()->leftArrayJoin('items', 'item');
        $this->assertSame('left', $b->arrayJoins[0]['type']);
        $this->assertSame('item', $b->arrayJoins[0]['alias']);
    }

    public function test_array_join_rejects_blank_alias(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->arrayJoin('items', ' ');
    }

    public function test_multiple_array_joins_accumulate(): void
    {
        $this->assertCount(2, $this->builder()->arrayJoin('tags')->arrayJoin('items')->arrayJoins);
    }

    public function test_pre_where_adds_clause(): void
    {
        $b = $this->builder()->preWhere('date', '>=', '2026-01-01');
        $this->assertCount(1, $b->preWheres);
        $this->assertSame('date', $b->preWheres[0]['column']);
        $this->assertSame('>=', $b->preWheres[0]['operator']);
    }

    public function test_pre_where_in_adds_clause(): void
    {
        $b = $this->builder()->preWhereIn('status', [1, 2, 3]);
        $this->assertSame('In', $b->preWheres[0]['type']);
    }

    public function test_pre_where_between_adds_clause(): void
    {
        $b = $this->builder()->preWhereBetween('age', [18, 65]);
        $this->assertSame('between', $b->preWheres[0]['type']);
    }

    public function test_pre_where_rejects_invalid_boolean(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->preWhere('id', '=', 1, 'OR 1 = 1');
    }

    public function test_pre_where_rejects_invalid_operator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->preWhere('id', '= ? OR 1 = 1 --', 42);
    }

    public function test_or_pre_where_supports_two_argument_shorthand(): void
    {
        $builder = $this->builder()
            ->preWhere('active', true)
            ->orPreWhere('id', 42);

        $this->assertSame([true, 42], $builder->getBindings());
        $this->assertSame('=', $builder->preWheres[1]['operator']);
    }

    public function test_pre_where_between_requires_two_values(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->preWhereBetween('id', [1]);
    }

    public function test_limit_by_sets_count_and_columns(): void
    {
        $b = $this->builder()->limitBy(1, 'user_id');
        $this->assertSame(1, $b->limitByCount);
        $this->assertSame(['user_id'], $b->limitByColumns);
    }

    public function test_limit_by_multiple_columns(): void
    {
        $b = $this->builder()->limitBy(5, 'category', 'status');
        $this->assertSame(['category', 'status'], $b->limitByColumns);
    }

    public function test_limit_by_accepts_an_array_of_columns(): void
    {
        $b = $this->builder()->limitBy(5, ['category', 'status']);

        $this->assertSame(['category', 'status'], $b->limitByColumns);
    }

    public function test_limit_by_rejects_blank_columns(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->limitBy(5, ' ');
    }

    public function test_any_left_join_adds_to_array(): void
    {
        $b = $this->builder()->anyLeftJoin('users', 'user_id');
        $this->assertSame('ANY', $b->clickhouseJoins[0]['strict']);
        $this->assertSame('LEFT', $b->clickhouseJoins[0]['type']);
        $this->assertSame(['user_id'], $b->clickhouseJoins[0]['using']);
    }

    public function test_all_inner_join_adds_to_array(): void
    {
        $b = $this->builder()->allInnerJoin('dim', ['key1', 'key2']);
        $this->assertSame('ALL', $b->clickhouseJoins[0]['strict']);
        $this->assertSame('INNER', $b->clickhouseJoins[0]['type']);
    }

    public function test_global_join(): void
    {
        $this->assertTrue($this->builder()->anyLeftJoin('users', 'user_id', global: true)->clickhouseJoins[0]['global']);
    }

    public function test_format_sets_property(): void
    {
        $this->assertSame('JSONEachRow', $this->builder()->format('JSONEachRow')->outputFormat);
    }

    public function test_format_default_is_null(): void
    {
        $this->assertNull($this->builder()->outputFormat);
    }

    public function test_settings_sets_array(): void
    {
        $this->assertSame(['max_threads' => 4], $this->builder()->settings(['max_threads' => 4])->querySettings);
    }

    public function test_settings_merges(): void
    {
        $b = $this->builder()
            ->settings(['max_threads' => 4])
            ->settings(['max_memory_usage' => 10000000000]);

        $this->assertSame([
            'max_threads' => 4,
            'max_memory_usage' => 10000000000,
        ], $b->querySettings);
    }

    public function test_settings_accepts_a_name_and_value(): void
    {
        $this->assertSame(
            ['max_threads' => 4],
            $this->builder()->settings('max_threads', 4)->querySettings,
        );
    }

    public function test_settings_name_requires_a_value(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->settings('max_threads');
    }

    public function test_settings_default_is_empty(): void
    {
        $this->assertSame([], $this->builder()->querySettings);
    }

    public function test_with_fill_attaches_to_last_order(): void
    {
        $b = $this->builder()->from('t')->orderBy('bucket')->withFill(step: 1);
        $this->assertSame(['step' => '1'], $b->withFills[0]);
    }

    public function test_with_fill_full_params(): void
    {
        $b = $this->builder()->from('t')->orderBy('ts')->withFill(from: 0, to: 100, step: 5);
        $this->assertSame(['from' => '0', 'to' => '100', 'step' => '5'], $b->withFills[0]);
    }

    public function test_with_fill_per_column(): void
    {
        $b = $this->builder()->from('t')
            ->orderBy('date')->withFill(step: 1)
            ->orderBy('hour')->withFill(from: 0, to: 23);
        $this->assertCount(2, $b->withFills);
    }

    public function test_with_fill_ignored_without_order_by(): void
    {
        $b = $this->builder()->withFill(step: 1);
        $this->assertEmpty($b->withFills);
    }

    public function test_interpolate_adds_columns(): void
    {
        $b = $this->builder()->from('t')->orderBy('ts')->withFill()->interpolate('cumulative');
        $this->assertSame([['column' => 'cumulative']], $b->interpolateColumns);
    }

    public function test_interpolate_multiple_columns(): void
    {
        $b = $this->builder()->from('t')->orderBy('ts')->withFill()
            ->interpolate('cumulative', new Expression('value AS 0'));
        $this->assertSame([
            ['column' => 'cumulative'],
            ['raw' => 'value AS 0'],
        ], $b->interpolateColumns);
    }

    public function test_with_fill_raw_stores_raw_expression(): void
    {
        $b = $this->builder()->from('t')
            ->orderBy('bucket')
            ->withFillRaw("FROM toDateTime64('2026-01-01', 3) STEP toIntervalMinute(5)");
        $this->assertArrayHasKey('raw', $b->withFills[0]);
    }

    public function test_with_fill_time_stores_params(): void
    {
        $b = $this->builder()->from('t')
            ->orderBy('bucket')
            ->withFillTime('2026-01-01', '2026-01-02', '5 minute', precision: 3);
        $this->assertStringContainsString("toDateTime64('2026-01-01', 3)", $b->withFills[0]['from']);
        $this->assertStringContainsString('toIntervalMinute(5)', $b->withFills[0]['step']);
    }

    public function test_async_convenience_method(): void
    {
        $b = $this->builder()->async();
        $this->assertSame(1, $b->querySettings['async_insert']);
        $this->assertSame(0, $b->querySettings['wait_for_async_insert']);
    }

    public function test_async_with_wait(): void
    {
        $this->assertSame(1, $this->builder()->async(wait: true)->querySettings['wait_for_async_insert']);
    }

    public function test_mutation_sync_convenience_method(): void
    {
        $this->assertSame(2, $this->builder()->mutationsSync(2)->querySettings['mutations_sync']);
    }

    public function test_pre_where_bindings_follow_sql_clause_order(): void
    {
        $builder = $this->builder()
            ->where('user_id', 7)
            ->preWhere('site_id', 42);

        $this->assertSame([42, 7], $builder->getBindings());
    }

    public function test_invalid_format_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->format('CSV; DROP TABLE users');
    }

    public function test_invalid_setting_name_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->settings(['max_threads; DROP TABLE users' => 1]);
    }

    public function test_invalid_sample_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->sample(-0.5);
    }

    public function test_invalid_sample_offset_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->sample(0.5, 1.1);
    }

    public function test_absolute_sample_cannot_use_an_offset(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->sample(10000, 0.5);
    }

    public function test_invalid_limit_by_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->limitBy(0, 'user_id');
    }

    public function test_asof_join_rejects_unsupported_join_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->clickhouseJoin('events', 'id', 'ASOF', 'FULL');
    }

    public function test_join_rejects_both_using_and_on_keys(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->builder()->clickhouseJoin(
            'users',
            'user_id',
            on: [['events.user_id', '=', 'users.id']],
        );
    }

    public function test_join_rejects_empty_using_keys(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->builder()->clickhouseJoin('users', []);
    }

    public function test_invalid_subquery_join_does_not_leave_stale_bindings(): void
    {
        $builder = $this->builder();
        $subquery = $this->builder()->from('users')->where('active', true);

        try {
            $builder->clickhouseJoin($subquery, [], alias: 'users');
        } catch (\RuntimeException) {
            // The builder remains reusable after validation fails.
        }

        $this->assertSame([], $builder->getBindings());
        $this->assertSame([], $builder->clickhouseJoins);
    }

    public function test_full_fluent_chaining(): void
    {
        $b = $this->builder()->from('t');
        $result = $b->final()
            ->sample(0.1)
            ->arrayJoin('tags')
            ->preWhere('date', '>=', '2026-01-01')
            ->limitBy(1, 'user_id')
            ->anyLeftJoin('users', 'user_id')
            ->format('JSON')
            ->settings(['max_threads' => 4]);
        $this->assertSame($b, $result);
    }

    public function test_insert_chunked_empty_returns_true(): void
    {
        $this->assertTrue($this->builder()->from('t')->insertChunked([]));
    }
}
