<?php

namespace ClickHouse\Laravel\Tests\Unit\Query;

use ClickHouse\Laravel\Query\ClickHouseQueryBuilder;
use ClickHouse\Laravel\Tests\TestCase;
use Illuminate\Database\Query\Expression;
use InvalidArgumentException;

class AdvancedQueryBuilderTest extends TestCase
{
    private function builder(string $table = 'events'): ClickHouseQueryBuilder
    {
        return $this->clickhouse()->table($table);
    }

    public function test_common_expressions_accumulate_and_keep_their_own_bindings(): void
    {
        $builder = $this->builder()
            ->where('tenant_id', 9)
            ->withQuery(10, 'threshold')
            ->withQuery('active', 'status');

        $this->assertCount(2, $builder->withQueries);
        $this->assertSame([10, 'active', 9], $builder->getBindings());
    }

    public function test_query_builder_can_be_registered_as_a_cte(): void
    {
        $cte = $this->builder('archived_events')
            ->select('id')
            ->where('tenant_id', 9);
        $builder = $this->builder()->withQuerySub($cte, 'archive');

        $this->assertTrue($builder->withQueries[0]['subquery']);
        $this->assertSame([9], $builder->getBindings());
    }

    public function test_recursive_cte_requires_a_subquery(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder()->withQueryRaw(
            '1',
            'tree',
            recursive: true,
        );
    }

    public function test_global_in_helpers_rewrite_laravel_predicate_types(): void
    {
        $builder = $this->builder()
            ->whereGlobalIn('tenant_id', [1, 2])
            ->orWhereGlobalNotIn('user_id', [3]);

        $this->assertSame('GlobalIn', $builder->wheres[0]['type']);
        $this->assertSame('GlobalNotIn', $builder->wheres[1]['type']);
        $this->assertSame([1, 2, 3], $builder->getBindings());
    }

    public function test_empty_predicates_accept_columns_and_expressions(): void
    {
        $builder = $this->builder()
            ->whereEmpty(['tags', new Expression('attributes')])
            ->havingNotEmpty('events');

        $this->assertSame('Empty', $builder->wheres[0]['type']);
        $this->assertSame('Empty', $builder->wheres[1]['type']);
        $this->assertSame('NotEmpty', $builder->havings[0]['type']);
    }

    public function test_empty_predicates_reject_an_empty_column_list(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder()->whereEmpty([]);
    }

    public function test_empty_predicates_reject_invalid_columns(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder()->havingEmpty(['tags', 42]);
    }

    public function test_set_operations_record_explicit_clickhouse_operators(): void
    {
        $builder = $this->builder()
            ->unionDistinct($this->builder('archive')->where('kind', 'union'))
            ->intersectAll($this->builder('allowed')->where('kind', 'intersect'))
            ->exceptDistinct($this->builder('blocked')->where('kind', 'except'));

        $this->assertSame(
            ['UNION DISTINCT', 'INTERSECT ALL', 'EXCEPT DISTINCT'],
            array_column($builder->unions ?? [], 'operator'),
        );
        $this->assertSame(['union', 'intersect', 'except'], $builder->getBindings());
    }
}
