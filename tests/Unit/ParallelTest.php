<?php

namespace ClickHouse\Laravel\Tests\Unit;

use ClickHouse\Laravel\Eloquent\ClickHouseModel;
use ClickHouse\Laravel\Parallel;
use ClickHouse\Laravel\Tests\TestCase;
use Illuminate\Contracts\Concurrency\Driver;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ParallelTestModel extends ClickHouseModel
{
    protected $table = 'events';
}

class ParallelTest extends TestCase
{
    public function test_parallel_get_preserves_keys_and_hydrates_each_query_type(): void
    {
        $executor = $this->createMock(Driver::class);
        $executor->expects($this->once())
            ->method('run')
            ->with($this->callback(
                fn (array $tasks): bool => array_keys($tasks) === ['rows', 'models']
                    && $tasks['rows'] instanceof \Closure
                    && $tasks['models'] instanceof \Closure,
            ))
            ->willReturn([
                'rows' => [(object) ['id' => 1]],
                'models' => [(object) ['id' => 2]],
            ]);

        $results = Parallel::get([
            'rows' => $this->clickhouse()->table('events')->where('id', 1),
            'models' => ParallelTestModel::query()->where('id', 2),
        ], executor: $executor);

        $this->assertSame(['rows', 'models'], array_keys($results));
        $this->assertInstanceOf(Collection::class, $results['rows']);
        $this->assertSame(1, $results['rows']->first()->id);
        $this->assertInstanceOf(ParallelTestModel::class, $results['models']->first());
        $this->assertSame(2, $results['models']->first()->id);
    }

    public function test_parallel_get_applies_query_callbacks(): void
    {
        $executor = $this->createStub(Driver::class);
        $executor->method('run')->willReturn([
            [(object) ['id' => 1], (object) ['id' => 2]],
        ]);
        $query = $this->clickhouse()
            ->table('events')
            ->afterQuery(fn (Collection $rows): Collection => $rows->pluck('id'));

        $results = Parallel::get([$query], executor: $executor);

        $this->assertSame([1, 2], $results[0]->all());
    }

    public function test_parallel_get_rejects_invalid_queries(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Parallel::get(['SELECT 1']);
    }

    public function test_parallel_get_rejects_a_driver_name_with_an_executor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Parallel::get(
            [$this->clickhouse()->table('events')],
            driver: 'sync',
            executor: $this->createStub(Driver::class),
        );
    }
}
