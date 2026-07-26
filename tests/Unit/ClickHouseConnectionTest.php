<?php

namespace ClickHouse\Laravel\Tests\Unit;

use ClickHouse\Laravel\ClickHouseConnection;
use ClickHouse\Laravel\Exceptions\TransactionsNotSupportedException;
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
        $pdo = $this->createStub(PDO::class);
        $stmt = $this->createStub(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $pdo->method('prepare')->willReturn($stmt);

        return new ClickHouseConnection($pdo, 'default', '', $config);
    }

    protected function queryMayBeRetried(
        ClickHouseConnection $connection,
        string $query,
    ): bool {
        $method = new \ReflectionMethod($connection, 'queryMayBeRetried');

        return $method->invoke($connection, $query);
    }

    public function test_get_driver_name(): void
    {
        $this->assertSame('clickhouse', $this->connection()->getDriverName());
    }

    public function test_transaction_throws_by_default(): void
    {
        $this->expectException(TransactionsNotSupportedException::class);
        $this->connection()->transaction(fn ($c) => 'ok');
    }

    public function test_transaction_executes_callback_in_explicit_passthrough_mode(): void
    {
        $result = $this->connection(['transactions' => 'passthrough'])
            ->transaction(fn ($c) => 'ok');
        $this->assertSame('ok', $result);
    }

    public function test_transaction_passes_connection_in_explicit_passthrough_mode(): void
    {
        $this->connection(['transactions' => 'passthrough'])->transaction(function ($c) {
            $this->assertInstanceOf(ClickHouseConnection::class, $c);
        });
    }

    public function test_begin_transaction_throws_by_default(): void
    {
        $this->expectException(TransactionsNotSupportedException::class);
        $this->connection()->beginTransaction();
    }

    public function test_commit_throws_by_default(): void
    {
        $this->expectException(TransactionsNotSupportedException::class);
        $this->connection()->commit();
    }

    public function test_roll_back_throws_by_default(): void
    {
        $this->expectException(TransactionsNotSupportedException::class);
        $this->connection()->rollBack();
    }

    public function test_transaction_methods_are_no_ops_in_explicit_passthrough_mode(): void
    {
        $connection = $this->connection(['transactions' => 'passthrough']);

        $connection->beginTransaction();
        $connection->commit();
        $connection->rollBack();

        $this->assertSame(0, $connection->transactionLevel());
    }

    public function test_transaction_level_returns_zero(): void
    {
        $this->assertSame(0, $this->connection()->transactionLevel());
    }

    public function test_default_query_grammar_is_correct_type(): void
    {
        $this->assertInstanceOf(
            ClickHouseQueryGrammar::class,
            $this->connection()->getQueryGrammar(),
        );
    }

    public function test_default_schema_grammar_via_builder(): void
    {
        $builder = $this->connection()->getSchemaBuilder();
        $this->assertInstanceOf(ClickHouseSchemaBuilder::class, $builder);
    }

    public function test_schema_builder_preserves_a_configured_schema_grammar(): void
    {
        $connection = $this->connection();
        $connection->getSchemaBuilder();
        $grammar = $connection->getSchemaGrammar();

        $this->assertInstanceOf(ClickHouseSchemaGrammar::class, $grammar);
        $connection->getSchemaBuilder();
        $this->assertSame($grammar, $connection->getSchemaGrammar());
    }

    public function test_query_returns_click_house_query_builder(): void
    {
        $this->assertInstanceOf(
            ClickHouseQueryBuilder::class,
            $this->connection()->query(),
        );
    }

    public function test_query_applies_final_from_config(): void
    {
        $conn = $this->connection(['options' => ['final' => true]]);
        $builder = $conn->query();
        $this->assertTrue($builder->useFinal);
    }

    public function test_query_does_not_apply_final_by_default(): void
    {
        $builder = $this->connection()->query();
        $this->assertFalse($builder->useFinal);
    }

    public function test_table_returns_builder_with_from(): void
    {
        $builder = $this->connection()->table('events');
        $this->assertInstanceOf(ClickHouseQueryBuilder::class, $builder);
        $this->assertSame('events', $builder->from);
    }

    public function test_table_can_enable_final_with_a_named_argument(): void
    {
        $builder = $this->connection()->table('events', final: true);

        $this->assertTrue($builder->useFinal);
        $this->assertSame('select * from `events` FINAL', $builder->toSql());
    }

    public function test_cluster_is_null_without_config(): void
    {
        $this->assertNull($this->connection()->getCluster());
    }

    public function test_cluster_is_created_from_config(): void
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

    public function test_empty_cluster_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->connection(['cluster' => []]);
    }

    public function test_negative_retries_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->connection(['retries' => -1]);
    }

    public function test_excessive_retries_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->connection(['retries' => 101]);
    }

    public function test_excessive_retry_backoff_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->connection(['retry_backoff_ms' => 5001]);
    }

    public function test_invalid_transaction_mode_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->connection(['transactions' => 'silent']);
    }

    public function test_read_queries_with_leading_comments_may_be_retried(): void
    {
        $query = <<<'SQL'
            /* tracing metadata */
            -- generated by the application
            SELECT 1
            SQL;

        $this->assertTrue($this->queryMayBeRetried($this->connection(), $query));
    }

    public function test_write_queries_are_not_retried_by_default(): void
    {
        $connection = $this->connection();

        $this->assertFalse(
            $this->queryMayBeRetried($connection, 'INSERT INTO events VALUES (1)'),
        );
        $this->assertFalse(
            $this->queryMayBeRetried($connection, 'ALTER TABLE events DELETE WHERE id = 1'),
        );
    }

    public function test_with_queries_are_not_assumed_to_be_read_only(): void
    {
        $this->assertFalse(
            $this->queryMayBeRetried(
                $this->connection(),
                'WITH 1 AS id INSERT INTO events SELECT id',
            ),
        );
    }

    public function test_retry_writes_requires_explicit_opt_in(): void
    {
        $connection = $this->connection(['retry_writes' => true]);

        $this->assertTrue(
            $this->queryMayBeRetried($connection, 'INSERT INTO events VALUES (1)'),
        );
    }

    public function test_query_keyword_must_end_at_a_word_boundary(): void
    {
        $this->assertFalse(
            $this->queryMayBeRetried($this->connection(), 'SELECTED FROM events'),
        );
    }

    public function test_invalid_retry_writes_value_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->connection(['retry_writes' => 'sometimes']);
    }
}
