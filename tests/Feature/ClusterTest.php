<?php

namespace ClickHouse\Laravel\Tests\Feature;

use ClickHouse\Laravel\ClickHouseConnection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for cluster failover and single-execution writes.
 * Requires two ClickHouse instances on ports 9000 and 9001.
 */
#[Group('integration')]
#[Group('cluster')]
class ClusterTest extends FeatureTestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $port2 = env('CLICKHOUSE_PORT_2', 9001);

        $app['config']->set('database.connections.clickhouse_cluster', [
            'driver' => 'clickhouse',
            'database' => env('CLICKHOUSE_DATABASE', 'default'),
            'username' => env('CLICKHOUSE_USERNAME', 'default'),
            'password' => env('CLICKHOUSE_PASSWORD', ''),
            'timeout' => 5,
            'retries' => 0,
            'settings' => [],
            'options' => ['final' => false],
            'cluster' => [
                ['host' => '127.0.0.1', 'port' => (int) env('CLICKHOUSE_PORT', 9000)],
                ['host' => '127.0.0.1', 'port' => (int) $port2],
            ],
        ]);

        $app['config']->set('database.connections.clickhouse_node2', [
            'driver' => 'clickhouse',
            'host' => '127.0.0.1',
            'port' => (int) $port2,
            'database' => env('CLICKHOUSE_DATABASE', 'default'),
            'username' => env('CLICKHOUSE_USERNAME', 'default'),
            'password' => env('CLICKHOUSE_PASSWORD', ''),
            'timeout' => 5,
            'retries' => 0,
            'settings' => [],
            'options' => ['final' => false],
        ]);

        $app['config']->set('database.connections.clickhouse_cluster_failover', [
            'driver' => 'clickhouse',
            'database' => env('CLICKHOUSE_DATABASE', 'default'),
            'username' => env('CLICKHOUSE_USERNAME', 'default'),
            'password' => env('CLICKHOUSE_PASSWORD', ''),
            'timeout' => 1,
            'retries' => 0,
            'settings' => [],
            'cluster' => [
                ['host' => '127.0.0.1', 'port' => 65534],
                ['host' => '127.0.0.1', 'port' => (int) $port2],
            ],
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

    public function test_cluster_connection_resolves(): void
    {
        $conn = $this->clusterConn();
        $this->assertInstanceOf(ClickHouseConnection::class, $conn);
        $this->assertNotNull($conn->getCluster());
    }

    public function test_cluster_has_two_nodes(): void
    {
        $this->assertCount(2, $this->clusterConn()->getCluster()->getNodes());
    }

    public function test_read_from_cluster(): void
    {
        $this->node1()->table('_test_cluster')->insert([
            ['id' => 1, 'name' => 'from_node1'],
        ]);

        $rows = $this->clusterConn()->table('_test_cluster')->get();
        $this->assertNotEmpty($rows);
    }

    public function test_write_executes_once_on_active_node(): void
    {
        $this->clusterConn()->table('_test_cluster')->insert([
            ['id' => 100, 'name' => 'distributed'],
        ]);

        $node1Row = $this->node1()->table('_test_cluster')->where('id', 100)->first();
        $this->assertNotNull($node1Row, 'Row should exist on node 1');
        $this->assertEquals('distributed', $node1Row->name);
        $this->assertNull(
            $this->node2()->table('_test_cluster')->where('id', 100)->first(),
            'The Laravel client must not fan out writes behind ClickHouse replication.',
        );
    }

    public function test_write_reports_inserted_rows_and_does_not_fan_out(): void
    {
        $inserted = $this->clusterConn()->table('_test_cluster')->insert([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
            ['id' => 3, 'name' => 'Charlie'],
        ]);

        $this->assertTrue($inserted);
        $this->assertEquals(3, $this->node1()->table('_test_cluster')->count());
        $this->assertEquals(0, $this->node2()->table('_test_cluster')->count());
    }

    public function test_cluster_read_failover(): void
    {
        $this->node1()->table('_test_cluster')->insert([
            ['id' => 1, 'name' => 'exists'],
        ]);
        $this->node2()->table('_test_cluster')->insert([
            ['id' => 1, 'name' => 'exists'],
        ]);

        $rows = DB::connection('clickhouse_cluster_failover')
            ->table('_test_cluster')
            ->get();

        $this->assertNotEmpty($rows);
        $this->assertSame(1, DB::connection('clickhouse_cluster_failover')->getCluster()->getActiveIndex());
    }

    public function test_cluster_insert_chunked(): void
    {
        $rows = array_map(fn ($i) => ['id' => $i, 'name' => "row_{$i}"], range(1, 50));

        $this->clusterConn()->table('_test_cluster')->insertChunked($rows, 10);

        $this->assertEquals(50, $this->node1()->table('_test_cluster')->count());
        $this->assertEquals(0, $this->node2()->table('_test_cluster')->count());
    }

    public function test_cluster_truncate(): void
    {
        $this->clusterConn()->table('_test_cluster')->insert([
            ['id' => 1, 'name' => 'to_delete'],
        ]);
        $this->node2()->table('_test_cluster')->insert([
            ['id' => 2, 'name' => 'must_remain'],
        ]);

        $this->clusterConn()->table('_test_cluster')->truncate();

        $this->assertEquals(0, $this->node1()->table('_test_cluster')->count());
        $this->assertEquals(1, $this->node2()->table('_test_cluster')->count());
    }

    public function test_both_nodes_independent(): void
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
