<?php

namespace ClickHouse\Laravel\Tests\Unit\Query;

use ClickHouse\Laravel\Exceptions\ClickHouseGrammarException;
use ClickHouse\Laravel\Query\ClickHouseQueryBuilder;
use ClickHouse\Laravel\Query\ClickHouseQueryGrammar;
use ClickHouse\Laravel\Tests\TestCase;

class AdvancedQueryGrammarTest extends TestCase
{
    private function builder(string $table = 'events'): ClickHouseQueryBuilder
    {
        return $this->clickhouse()->table($table);
    }

    private function grammar(): ClickHouseQueryGrammar
    {
        return $this->clickhouse()->getQueryGrammar();
    }

    public function test_scalar_ctes_compile_before_select_and_bind_before_where(): void
    {
        $builder = $this->builder()
            ->where('tenant_id', 9)
            ->withQuery(10, 'threshold');

        $this->assertSame(
            'WITH ? AS `threshold` select * from `events` where `tenant_id` = ?',
            $builder->toSql(),
        );
        $this->assertSame([10, 9], $builder->getBindings());
    }

    public function test_multiple_ctes_compile_in_registration_order(): void
    {
        $archive = $this->builder('archive')
            ->select('id')
            ->where('tenant_id', 9);
        $builder = $this->builder()
            ->withQuery('active', 'status')
            ->withQuerySub($archive, 'archived');

        $this->assertStringStartsWith(
            'WITH ? AS `status`, `archived` AS (select `id` from `archive` where `tenant_id` = ?)',
            $builder->toSql(),
        );
        $this->assertSame(['active', 9], $builder->getBindings());
    }

    public function test_recursive_cte_compiles_with_recursive_keyword(): void
    {
        $cte = $this->clickhouse()
            ->query()
            ->selectRaw('1 AS id');
        $builder = $this->clickhouse()
            ->query()
            ->withQueryRecursive($cte, 'tree')
            ->from('tree');

        $this->assertStringStartsWith(
            'WITH RECURSIVE `tree` AS (select 1 AS id)',
            $builder->toSql(),
        );
    }

    public function test_global_in_compiles_arrays_subqueries_and_empty_sets(): void
    {
        $subquery = $this->builder('allowed')->select('id')->where('active', 1);

        $this->assertStringContainsString(
            '`id` global in (?, ?)',
            $this->builder()->whereGlobalIn('id', [1, 2])->toSql(),
        );
        $this->assertStringContainsString(
            '`id` global in (select `id` from `allowed` where `active` = ?)',
            $this->builder()->whereGlobalIn('id', $subquery)->toSql(),
        );
        $this->assertStringContainsString(
            'where 0 = 1',
            $this->builder()->whereGlobalIn('id', [])->toSql(),
        );
        $this->assertStringContainsString(
            'where 1 = 1',
            $this->builder()->whereGlobalNotIn('id', [])->toSql(),
        );
    }

    public function test_empty_predicates_compile_for_where_and_having(): void
    {
        $builder = $this->builder()
            ->selectRaw('event, groupArray(id) AS ids')
            ->whereEmpty('tags')
            ->groupBy('event')
            ->havingNotEmpty('ids');

        $this->assertStringContainsString('where empty(`tags`)', $builder->toSql());
        $this->assertStringContainsString('having not empty(`ids`)', $builder->toSql());
    }

    public function test_all_set_operation_variants_compile_explicitly(): void
    {
        $base = $this->builder()->select('id');

        $this->assertStringContainsString(
            'UNION DISTINCT',
            $base->clone()->union($this->builder('archive')->select('id'))->toSql(),
        );
        $this->assertStringContainsString(
            'UNION ALL',
            $base->clone()->unionAll($this->builder('archive')->select('id'))->toSql(),
        );
        $this->assertStringContainsString(
            'INTERSECT DISTINCT',
            $base->clone()->intersect($this->builder('allowed')->select('id'))->toSql(),
        );
        $this->assertStringContainsString(
            'INTERSECT ALL',
            $base->clone()->intersectAll($this->builder('allowed')->select('id'))->toSql(),
        );
        $this->assertStringContainsString(
            'EXCEPT DISTINCT',
            $base->clone()->except($this->builder('blocked')->select('id'))->toSql(),
        );
        $this->assertStringContainsString(
            'EXCEPT ALL',
            $base->clone()->exceptAll($this->builder('blocked')->select('id'))->toSql(),
        );
    }

    public function test_global_set_modifiers_wrap_the_compound_query_and_do_not_mutate_it(): void
    {
        $builder = $this->builder()
            ->select('id')
            ->unionDistinct($this->builder('archive')->select('id'))
            ->orderBy('id')
            ->limit(10)
            ->offset(2)
            ->settings(['max_threads' => 1]);
        $sql = $builder->toSql();

        $this->assertStringStartsWith(
            'select * from (select * from (select `id` from `events`) UNION DISTINCT',
            $sql,
        );
        $this->assertStringContainsString(
            ') AS `_clickhouse_set` order by `id` asc LIMIT 10 OFFSET 2',
            $sql,
        );
        $this->assertStringEndsWith('SETTINGS max_threads = 1', $sql);
        $this->assertSame($sql, $builder->toSql());
    }

    public function test_cte_is_hoisted_over_the_complete_set_query(): void
    {
        $cte = $this->builder('archive')
            ->select('id')
            ->where('active', 1);
        $right = $this->clickhouse()
            ->query()
            ->from('active_archive')
            ->select('id')
            ->where('id', 2);
        $builder = $this->clickhouse()
            ->query()
            ->withQuerySub($cte, 'active_archive')
            ->from('active_archive')
            ->select('id')
            ->unionDistinct($right);
        $sql = $builder->toSql();

        $this->assertStringStartsWith(
            'WITH `active_archive` AS (select `id` from `archive` where `active` = ?) select * from',
            $sql,
        );
        $this->assertSame(1, substr_count($sql, 'WITH '));
        $this->assertSame([1, 2], $builder->getBindings());
        $this->assertSame($sql, $builder->toSql());
    }

    public function test_lightweight_and_partitioned_deletes_compile_with_clickhouse_order(): void
    {
        $builder = $this->builder()->where('id', 1);

        $this->assertSame(
            'DELETE FROM `events` IN PARTITION ? where `id` = ?',
            $this->grammar()->compileDelete($builder, true, '2026-01-01'),
        );
        $this->assertSame(
            'ALTER TABLE `events` DELETE IN PARTITION ? where `id` = ?',
            $this->grammar()->compileDelete($builder, false, '2026-01-01'),
        );
    }

    public function test_delete_bindings_put_partition_before_where(): void
    {
        $builder = $this->builder()->where('id', 1);
        $builder->addBinding('2026-01-01', 'partition');

        $this->assertSame(
            ['2026-01-01', 1],
            $this->grammar()->prepareBindingsForDelete($builder->getRawBindings()),
        );
    }

    public function test_mutations_reject_common_table_expressions(): void
    {
        $this->expectException(ClickHouseGrammarException::class);
        $this->expectExceptionMessage('WITH');

        $this->grammar()->compileDelete(
            $this->builder()->withQuery(1, 'one')->where('id', 1),
        );
    }
}
