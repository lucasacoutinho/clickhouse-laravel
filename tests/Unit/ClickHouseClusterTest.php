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
        $connector = $connector ?? $this->createMock(ClickHouseConnector::class);

        return new ClickHouseCluster(
            $nodes,
            ['database' => 'default', 'username' => 'default', 'password' => ''],
            $connector,
        );
    }

    public function testGetNodesReturnsConfiguredNodes(): void
    {
        $nodes = [
            ['host' => 'ch01', 'port' => 9000],
            ['host' => 'ch02', 'port' => 9000],
        ];

        $cluster = $this->createCluster($nodes);
        $this->assertSame($nodes, $cluster->getNodes());
    }

    public function testActiveIndexStartsAtZero(): void
    {
        $cluster = $this->createCluster([
            ['host' => 'ch01', 'port' => 9000],
        ]);

        $this->assertSame(0, $cluster->getActiveIndex());
    }

    public function testSlideNodeRotatesToNext(): void
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

    public function testSlideNodeWrapsAround(): void
    {
        $cluster = $this->createCluster([
            ['host' => 'ch01', 'port' => 9000],
            ['host' => 'ch02', 'port' => 9000],
        ]);

        $cluster->slideNode(); // -> 1
        $cluster->slideNode(); // -> 0
        $this->assertSame(0, $cluster->getActiveIndex());
    }

    public function testReadConnectionReturnsPdo(): void
    {
        $pdo = $this->createMock(PDO::class);
        $connector = $this->createMock(ClickHouseConnector::class);
        $connector->method('connect')->willReturn($pdo);

        $cluster = $this->createCluster(
            [['host' => 'ch01', 'port' => 9000]],
            $connector,
        );

        $this->assertSame($pdo, $cluster->getReadConnection());
    }

    public function testReadConnectionFailoverSlidesToNextNode(): void
    {
        $pdo = $this->createMock(PDO::class);
        $connector = $this->createMock(ClickHouseConnector::class);

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

    public function testReadConnectionThrowsWhenAllNodesUnreachable(): void
    {
        $connector = $this->createMock(ClickHouseConnector::class);
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

    public function testWriteConnectionsReturnsAllNodes(): void
    {
        $pdo1 = $this->createMock(PDO::class);
        $pdo2 = $this->createMock(PDO::class);
        $connector = $this->createMock(ClickHouseConnector::class);

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

    public function testSingleNodeCluster(): void
    {
        $pdo = $this->createMock(PDO::class);
        $connector = $this->createMock(ClickHouseConnector::class);
        $connector->method('connect')->willReturn($pdo);

        $cluster = $this->createCluster(
            [['host' => 'ch01', 'port' => 9000]],
            $connector,
        );

        $this->assertSame($pdo, $cluster->getReadConnection());
        $this->assertCount(1, $cluster->getWriteConnections());
    }
}
