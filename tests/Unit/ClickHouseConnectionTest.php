<?php

namespace ClickHouse\Laravel\Tests\Unit;

use ClickHouse\Laravel\ClickHouseConnection;
use ClickHouse\Laravel\Query\ClickHouseQueryBuilder;
use ClickHouse\Laravel\Query\ClickHouseQueryGrammar;
use ClickHouse\Laravel\Schema\ClickHouseSchemaBuilder;
use ClickHouse\Laravel\Schema\ClickHouseSchemaGrammar;
use ClickHouse\Laravel\Tests\TestCase;
use PDO;
use PDOStatement;

class ClickHouseConnectionTest extends TestCase
{
    protected function connection(array $config = []): ClickHouseConnection
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $pdo->method('prepare')->willReturn($stmt);

        return new ClickHouseConnection($pdo, 'default', '', $config);
    }

 Driver name ---

    public function testGetDriverName(): void
    {
        $this->assertSame('clickhouse', $this->connection()->getDriverName());
    }

 Transactions are no-ops ---

    public function testTransactionExecutesCallback(): void
    {
        $result = $this->connection()->transaction(fn($c) => 'ok');
        $this->assertSame('ok', $result);
    }

    public function testTransactionPassesConnection(): void
    {
        $this->connection()->transaction(function ($c) {
            $this->assertInstanceOf(ClickHouseConnection::class, $c);
        });
    }

    public function testBeginTransactionIsNoOp(): void
    {
        $this->connection()->beginTransaction();
        $this->assertTrue(true); // no exception
    }

    public function testCommitIsNoOp(): void
    {
        $this->connection()->commit();
        $this->assertTrue(true);
    }

    public function testRollBackIsNoOp(): void
    {
        $this->connection()->rollBack();
        $this->assertTrue(true);
    }

    public function testTransactionLevelReturnsZero(): void
    {
        $this->assertSame(0, $this->connection()->transactionLevel());
    }

 Grammars ---

    public function testDefaultQueryGrammarIsCorrectType(): void
    {
        $this->assertInstanceOf(
            ClickHouseQueryGrammar::class,
            $this->connection()->getQueryGrammar(),
        );
    }

    public function testDefaultSchemaGrammarViaBuilder(): void
    {
        $builder = $this->connection()->getSchemaBuilder();
        $this->assertInstanceOf(ClickHouseSchemaBuilder::class, $builder);
    }

 Query builder ---

    public function testQueryReturnsClickHouseQueryBuilder(): void
    {
        $this->assertInstanceOf(
            ClickHouseQueryBuilder::class,
            $this->connection()->query(),
        );
    }

    public function testQueryAppliesFinalFromConfig(): void
    {
        $conn = $this->connection(['options' => ['final' => true]]);
        $builder = $conn->query();
        $this->assertTrue($builder->useFinal);
    }

    public function testQueryDoesNotApplyFinalByDefault(): void
    {
        $builder = $this->connection()->query();
        $this->assertFalse($builder->useFinal);
    }

    public function testTableReturnsBuilderWithFrom(): void
    {
        $builder = $this->connection()->table('events');
        $this->assertInstanceOf(ClickHouseQueryBuilder::class, $builder);
        $this->assertSame('events', $builder->from);
    }

 Cluster ---

    public function testClusterIsNullWithoutConfig(): void
    {
        $this->assertNull($this->connection()->getCluster());
    }

    public function testClusterIsCreatedFromConfig(): void
    {
        $conn = $this->connection([
            'cluster' => [
                ['host' => 'ch01', 'port' => 9000],
                ['host' => 'ch02', 'port' => 9000],
            ],
        ]);

        $this->assertNotNull($conn->getCluster());
        $this->assertCount(2, $conn->getCluster()->getNodes());
    }
}
