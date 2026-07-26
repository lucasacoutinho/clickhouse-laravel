<?php

namespace ClickHouse\Laravel\Tests\Unit;

use ClickHouse\Laravel\ClickHouseCluster;
use ClickHouse\Laravel\Connectors\ClickHouseConnector;
use ClickHouse\Laravel\Tests\TestCase;
use PDO;

class ClickHouseClusterTest extends TestCase
{
    protected function createCluster(array $nodes, ?ClickHouseConnector $connector = null): ClickHouseCluster
    {
        $connector = $connector ?? $this->createStub(ClickHouseConnector::class);

        return new ClickHouseCluster(
            $nodes,
            ['database' => 'default', 'username' => 'default', 'password' => ''],
            $connector,
        );
    }

    public function test_get_nodes_returns_configured_nodes(): void
    {
        $nodes = [
            ['host' => 'ch01', 'port' => 9000],
            ['host' => 'ch02', 'port' => 9000],
        ];

        $cluster = $this->createCluster($nodes);
        $this->assertSame($nodes, $cluster->getNodes());
    }

    public function test_active_index_starts_at_zero(): void
    {
        $cluster = $this->createCluster([
            ['host' => 'ch01', 'port' => 9000],
        ]);

        $this->assertSame(0, $cluster->getActiveIndex());
    }

    public function test_slide_node_rotates_to_next(): void
    {
        $cluster = $this->createCluster([
            ['host' => 'ch01', 'port' => 9000],
            ['host' => 'ch02', 'port' => 9000],
            ['host' => 'ch03', 'port' => 9000],
        ]);

        $cluster->slideNode();
        $this->assertSame(1, $cluster->getActiveIndex());

        $cluster->slideNode();
        $this->assertSame(2, $cluster->getActiveIndex());
    }

    public function test_slide_node_wraps_around(): void
    {
        $cluster = $this->createCluster([
            ['host' => 'ch01', 'port' => 9000],
            ['host' => 'ch02', 'port' => 9000],
        ]);

        $cluster->slideNode(); // -> 1
        $cluster->slideNode(); // -> 0
        $this->assertSame(0, $cluster->getActiveIndex());
    }

    public function test_read_connection_returns_pdo(): void
    {
        $pdo = $this->createStub(PDO::class);
        $connector = $this->createStub(ClickHouseConnector::class);
        $connector->method('connect')->willReturn($pdo);

        $cluster = $this->createCluster(
            [['host' => 'ch01', 'port' => 9000]],
            $connector,
        );

        $this->assertSame($pdo, $cluster->getReadConnection());
    }

    public function test_read_connection_failover_slides_to_next_node(): void
    {
        $pdo = $this->createStub(PDO::class);
        $connector = $this->createStub(ClickHouseConnector::class);

        $callCount = 0;
        $connector->method('connect')->willReturnCallback(
            function (array $config) use (&$callCount, $pdo) {
                $callCount++;
                if ($config['host'] === 'ch01') {
                    throw new \RuntimeException('Node ch01 unreachable');
                }

                return $pdo;
            }
        );

        $cluster = $this->createCluster(
            [
                ['host' => 'ch01', 'port' => 9000],
                ['host' => 'ch02', 'port' => 9000],
            ],
            $connector,
        );

        $result = $cluster->getReadConnection();
        $this->assertSame($pdo, $result);
        $this->assertSame(1, $cluster->getActiveIndex());
    }

    public function test_read_connection_throws_when_all_nodes_unreachable(): void
    {
        $connector = $this->createStub(ClickHouseConnector::class);
        $connector->method('connect')->willThrowException(new \RuntimeException('Unreachable'));

        $cluster = $this->createCluster(
            [
                ['host' => 'ch01', 'port' => 9000],
                ['host' => 'ch02', 'port' => 9000],
            ],
            $connector,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('All ClickHouse cluster nodes are unreachable');
        $cluster->getReadConnection();
    }

    public function test_write_connections_returns_all_nodes(): void
    {
        $pdo1 = $this->createStub(PDO::class);
        $pdo2 = $this->createStub(PDO::class);
        $connector = $this->createStub(ClickHouseConnector::class);

        $connector->method('connect')->willReturnOnConsecutiveCalls($pdo1, $pdo2);

        $cluster = $this->createCluster(
            [
                ['host' => 'ch01', 'port' => 9000],
                ['host' => 'ch02', 'port' => 9000],
            ],
            $connector,
        );

        $connections = $cluster->getWriteConnections();
        $this->assertCount(2, $connections);
    }

    public function test_single_node_cluster(): void
    {
        $pdo = $this->createStub(PDO::class);
        $connector = $this->createStub(ClickHouseConnector::class);
        $connector->method('connect')->willReturn($pdo);

        $cluster = $this->createCluster(
            [['host' => 'ch01', 'port' => 9000]],
            $connector,
        );

        $this->assertSame($pdo, $cluster->getReadConnection());
        $this->assertCount(1, $cluster->getWriteConnections());
    }

    public function test_empty_cluster_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->createCluster([]);
    }

    public function test_node_without_host_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->createCluster([['port' => 9000]]);
    }

    public function test_invalid_node_configuration_is_not_hidden_by_failover(): void
    {
        $cluster = $this->createCluster(
            [
                ['host' => 'ch01', 'port' => -1],
                ['host' => 'ch02', 'port' => 9000],
            ],
            new ClickHouseConnector,
        );

        $this->expectException(\InvalidArgumentException::class);
        $cluster->getReadConnection();
    }

    public function test_invalidating_active_connection_rotates_and_reconnects(): void
    {
        $pdo1 = $this->createStub(PDO::class);
        $pdo2 = $this->createStub(PDO::class);
        $connector = $this->createMock(ClickHouseConnector::class);
        $connector->expects($this->exactly(2))
            ->method('connect')
            ->willReturnOnConsecutiveCalls($pdo1, $pdo2);

        $cluster = $this->createCluster([
            ['host' => 'ch01', 'port' => 9000],
            ['host' => 'ch02', 'port' => 9000],
        ], $connector);

        $this->assertSame($pdo1, $cluster->getReadConnection());
        $cluster->invalidateActiveConnection();
        $this->assertSame(1, $cluster->getActiveIndex());
        $this->assertSame($pdo2, $cluster->getReadConnection());
    }
}
