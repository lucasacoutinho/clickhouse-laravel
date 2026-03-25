<?php

namespace ClickHouse\Laravel\Tests\Feature;

use ClickHouse\Laravel\ClickHouseConnection;
use Illuminate\Support\Facades\DB;

/**
 * Integration tests for cluster failover and distributed writes.
 * Requires two ClickHouse instances on ports 9000 and 9001.
 *
 * @group integration
 * @group cluster
 */
class ClusterTest extends FeatureTestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $port2 = env('CLICKHOUSE_PORT_2', 9001);

        $app['config']->set('database.connections.clickhouse_cluster', [
            'driver'   => 'clickhouse',
            'database' => env('CLICKHOUSE_DATABASE', 'default'),
            'username' => env('CLICKHOUSE_USERNAME', 'default'),
            'password' => env('CLICKHOUSE_PASSWORD', ''),
            'timeout'  => 5,
            'retries'  => 0,
            'settings' => [],
            'options'  => ['final' => false],
            'cluster'  => [
                ['host' => '127.0.0.1', 'port' => (int) env('CLICKHOUSE_PORT', 9000)],
                ['host' => '127.0.0.1', 'port' => (int) $port2],
            ],
        ]);

        $app['config']->set('database.connections.clickhouse_node2', [
            'driver'   => 'clickhouse',
            'host'     => '127.0.0.1',
            'port'     => (int) $port2,
            'database' => env('CLICKHOUSE_DATABASE', 'default'),
            'username' => env('CLICKHOUSE_USERNAME', 'default'),
            'password' => env('CLICKHOUSE_PASSWORD', ''),
            'timeout'  => 5,
            'retries'  => 0,
            'settings' => [],
            'options'  => ['final' => false],
        ]);
    }

    protected function clusterConn(): ClickHouseConnection
    {
        return DB::connection('clickhouse_cluster');
    }

    protected function node1(): ClickHouseConnection
    {
        return DB::connection('clickhouse');
    }

    protected function node2(): ClickHouseConnection
    {
        return DB::connection('clickhouse_node2');
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([$this->node1(), $this->node2()] as $conn) {
            $conn->statement('DROP TABLE IF EXISTS _test_cluster');
            $conn->statement(<<<'SQL'
                CREATE TABLE _test_cluster (
                    id UInt64,
                    name String
                ) ENGINE = MergeTree() ORDER BY id
            SQL);
        }
    }

    protected function tearDown(): void
    {
        foreach ([$this->node1(), $this->node2()] as $conn) {
            $conn->statement('DROP TABLE IF EXISTS _test_cluster');
        }
        parent::tearDown();
    }

    public function testClusterConnectionResolves(): void
    {
        $conn = $this->clusterConn();
        $this->assertInstanceOf(ClickHouseConnection::class, $conn);
        $this->assertNotNull($conn->getCluster());
    }

    public function testClusterHasTwoNodes(): void
    {
        $this->assertCount(2, $this->clusterConn()->getCluster()->getNodes());
    }

    public function testReadFromCluster(): void
    {
        $this->node1()->table('_test_cluster')->insert([
            ['id' => 1, 'name' => 'from_node1'],
        ]);

        $rows = $this->clusterConn()->table('_test_cluster')->get();
        $this->assertNotEmpty($rows);
    }

    public function testDistributedWriteReachesBothNodes(): void
    {
        $this->clusterConn()->table('_test_cluster')->insert([
            ['id' => 100, 'name' => 'distributed'],
        ]);

        $node1Row = $this->node1()->table('_test_cluster')->where('id', 100)->first();
        $node2Row = $this->node2()->table('_test_cluster')->where('id', 100)->first();

        $this->assertNotNull($node1Row, 'Row should exist on node 1');
        $this->assertNotNull($node2Row, 'Row should exist on node 2');
        $this->assertEquals('distributed', $node1Row->name);
        $this->assertEquals('distributed', $node2Row->name);
    }

    public function testDistributedWriteMultipleRows(): void
    {
        $this->clusterConn()->table('_test_cluster')->insert([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
            ['id' => 3, 'name' => 'Charlie'],
        ]);

        $this->assertEquals(3, $this->node1()->table('_test_cluster')->count());
        $this->assertEquals(3, $this->node2()->table('_test_cluster')->count());
    }

    public function testClusterReadFailover(): void
    {
        $this->node1()->table('_test_cluster')->insert([
            ['id' => 1, 'name' => 'exists'],
        ]);
        $this->node2()->table('_test_cluster')->insert([
            ['id' => 1, 'name' => 'exists'],
        ]);

        // Both nodes have data — cluster read should work regardless of which node is active
        $rows = $this->clusterConn()->table('_test_cluster')->get();
        $this->assertNotEmpty($rows);
    }

    public function testClusterInsertChunked(): void
    {
        $rows = array_map(fn($i) => ['id' => $i, 'name' => "row_{$i}"], range(1, 50));

        $this->clusterConn()->table('_test_cluster')->insertChunked($rows, 10);

        $this->assertEquals(50, $this->node1()->table('_test_cluster')->count());
        $this->assertEquals(50, $this->node2()->table('_test_cluster')->count());
    }

    public function testClusterTruncate(): void
    {
        $this->clusterConn()->table('_test_cluster')->insert([
            ['id' => 1, 'name' => 'to_delete'],
        ]);

        // Truncate goes through statement() which distributes to all nodes
        $this->clusterConn()->table('_test_cluster')->truncate();

        $this->assertEquals(0, $this->node1()->table('_test_cluster')->count());
        $this->assertEquals(0, $this->node2()->table('_test_cluster')->count());
    }

    public function testBothNodesIndependent(): void
    {
        // Write directly to each node — verify they're separate instances
        $this->node1()->table('_test_cluster')->insert([['id' => 1, 'name' => 'node1_only']]);
        $this->node2()->table('_test_cluster')->insert([['id' => 2, 'name' => 'node2_only']]);

        $node1Rows = $this->node1()->table('_test_cluster')->get();
        $node2Rows = $this->node2()->table('_test_cluster')->get();

        // Each node only has its own row
        $this->assertCount(1, $node1Rows);
        $this->assertCount(1, $node2Rows);
        $this->assertEquals('node1_only', $node1Rows[0]->name);
        $this->assertEquals('node2_only', $node2Rows[0]->name);
    }
}
