<?php

namespace ClickHouse\Laravel\Tests\Unit\Query;

use ClickHouse\Laravel\Query\ClickHouseQueryBuilder;
use ClickHouse\Laravel\Tests\TestCase;

class ClickHouseQueryBuilderTest extends TestCase
{
    protected function builder(): ClickHouseQueryBuilder
    {
        return $this->clickhouse()->query();
    }

    public function testFinalSetsFlag(): void
    {
        $this->assertTrue($this->builder()->final()->useFinal);
    }

    public function testFinalCanBeDisabled(): void
    {
        $this->assertFalse($this->builder()->final()->final(false)->useFinal);
    }

    public function testFinalDefaultIsFalse(): void
    {
        $this->assertFalse($this->builder()->useFinal);
    }

    public function testSampleSetsClause(): void
    {
        $this->assertSame('0.1', $this->builder()->sample(0.1)->sampleClause);
    }

    public function testSampleWithInteger(): void
    {
        $this->assertSame('10000', $this->builder()->sample(10000)->sampleClause);
    }

    public function testSampleDefaultIsNull(): void
    {
        $this->assertNull($this->builder()->sampleClause);
    }

    public function testArrayJoinAddsToArray(): void
    {
        $b = $this->builder()->arrayJoin('tags');
        $this->assertCount(1, $b->arrayJoins);
        $this->assertSame([
            'column' => 'tags',
            'alias'  => null,
            'type'   => 'inner',
        ], $b->arrayJoins[0]);
    }

    public function testArrayJoinWithAlias(): void
    {
        $this->assertSame('tag', $this->builder()->arrayJoin('tags', 'tag')->arrayJoins[0]['alias']);
    }

    public function testLeftArrayJoinHelper(): void
    {
        $b = $this->builder()->leftArrayJoin('items', 'item');
        $this->assertSame('left', $b->arrayJoins[0]['type']);
        $this->assertSame('item', $b->arrayJoins[0]['alias']);
    }

    public function testMultipleArrayJoinsAccumulate(): void
    {
        $this->assertCount(2, $this->builder()->arrayJoin('tags')->arrayJoin('items')->arrayJoins);
    }

    public function testPreWhereAddsClause(): void
    {
        $b = $this->builder()->preWhere('date', '>=', '2026-01-01');
        $this->assertCount(1, $b->preWheres);
        $this->assertSame('date', $b->preWheres[0]['column']);
        $this->assertSame('>=', $b->preWheres[0]['operator']);
    }

    public function testPreWhereInAddsClause(): void
    {
        $b = $this->builder()->preWhereIn('status', [1, 2, 3]);
        $this->assertSame('In', $b->preWheres[0]['type']);
    }

    public function testPreWhereBetweenAddsClause(): void
    {
        $b = $this->builder()->preWhereBetween('age', [18, 65]);
        $this->assertSame('between', $b->preWheres[0]['type']);
    }

    public function testLimitBySetsCountAndColumns(): void
    {
        $b = $this->builder()->limitBy(1, 'user_id');
        $this->assertSame(1, $b->limitByCount);
        $this->assertSame(['user_id'], $b->limitByColumns);
    }

    public function testLimitByMultipleColumns(): void
    {
        $b = $this->builder()->limitBy(5, 'category', 'status');
        $this->assertSame(['category', 'status'], $b->limitByColumns);
    }

    public function testAnyLeftJoinAddsToArray(): void
    {
        $b = $this->builder()->anyLeftJoin('users', 'user_id');
        $this->assertSame('ANY', $b->clickhouseJoins[0]['strict']);
        $this->assertSame('LEFT', $b->clickhouseJoins[0]['type']);
        $this->assertSame(['user_id'], $b->clickhouseJoins[0]['using']);
    }

    public function testAllInnerJoinAddsToArray(): void
    {
        $b = $this->builder()->allInnerJoin('dim', ['key1', 'key2']);
        $this->assertSame('ALL', $b->clickhouseJoins[0]['strict']);
        $this->assertSame('INNER', $b->clickhouseJoins[0]['type']);
    }

    public function testGlobalJoin(): void
    {
        $this->assertTrue($this->builder()->anyLeftJoin('users', 'user_id', global: true)->clickhouseJoins[0]['global']);
    }

    public function testFormatSetsProperty(): void
    {
        $this->assertSame('JSONEachRow', $this->builder()->format('JSONEachRow')->outputFormat);
    }

    public function testFormatDefaultIsNull(): void
    {
        $this->assertNull($this->builder()->outputFormat);
    }

    public function testSettingsSetsArray(): void
    {
        $this->assertSame(['max_threads' => 4], $this->builder()->settings(['max_threads' => 4])->querySettings);
    }

    public function testSettingsMerges(): void
    {
        $b = $this->builder()
            ->settings(['max_threads' => 4])
            ->settings(['max_memory_usage' => 10000000000]);

        $this->assertSame([
            'max_threads' => 4,
            'max_memory_usage' => 10000000000,
        ], $b->querySettings);
    }

    public function testSettingsDefaultIsEmpty(): void
    {
        $this->assertSame([], $this->builder()->querySettings);
    }

    public function testWithFillAttachesToLastOrder(): void
    {
        $b = $this->builder()->from('t')->orderBy('bucket')->withFill(step: '1');
        $this->assertSame(['step' => '1'], $b->withFills[0]);
    }

    public function testWithFillFullParams(): void
    {
        $b = $this->builder()->from('t')->orderBy('ts')->withFill(from: '0', to: '100', step: '5');
        $this->assertSame(['from' => '0', 'to' => '100', 'step' => '5'], $b->withFills[0]);
    }

    public function testWithFillPerColumn(): void
    {
        $b = $this->builder()->from('t')
            ->orderBy('date')->withFill(step: '1')
            ->orderBy('hour')->withFill(from: '0', to: '23');
        $this->assertCount(2, $b->withFills);
    }

    public function testWithFillIgnoredWithoutOrderBy(): void
    {
        $b = $this->builder()->withFill(step: '1');
        $this->assertEmpty($b->withFills);
    }

    public function testInterpolateAddsColumns(): void
    {
        $b = $this->builder()->from('t')->orderBy('ts')->withFill()->interpolate('cumulative');
        $this->assertSame(['cumulative'], $b->interpolateColumns);
    }

    public function testInterpolateMultipleColumns(): void
    {
        $b = $this->builder()->from('t')->orderBy('ts')->withFill()
            ->interpolate('cumulative', 'value AS 0');
        $this->assertSame(['cumulative', 'value AS 0'], $b->interpolateColumns);
    }

    public function testWithFillRawStoresRawExpression(): void
    {
        $b = $this->builder()->from('t')
            ->orderBy('bucket')
            ->withFillRaw("FROM toDateTime64('2026-01-01', 3) STEP toIntervalMinute(5)");
        $this->assertArrayHasKey('raw', $b->withFills[0]);
    }

    public function testWithFillTimeStoresParams(): void
    {
        $b = $this->builder()->from('t')
            ->orderBy('bucket')
            ->withFillTime('2026-01-01', '2026-01-02', '5 minute', precision: 3);
        $this->assertStringContainsString("toDateTime64('2026-01-01', 3)", $b->withFills[0]['from']);
        $this->assertStringContainsString('toIntervalMinute(5)', $b->withFills[0]['step']);
    }

    public function testAsyncConvenienceMethod(): void
    {
        $b = $this->builder()->async();
        $this->assertSame(1, $b->querySettings['async_insert']);
        $this->assertSame(0, $b->querySettings['wait_for_async_insert']);
    }

    public function testAsyncWithWait(): void
    {
        $this->assertSame(1, $this->builder()->async(wait: true)->querySettings['wait_for_async_insert']);
    }

    public function testFullFluentChaining(): void
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

    public function testInsertChunkedEmptyReturnsTrue(): void
    {
        $this->assertTrue($this->builder()->from('t')->insertChunked([]));
    }
}
