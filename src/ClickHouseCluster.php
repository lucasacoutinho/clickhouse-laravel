<?php

namespace ClickHouse\Laravel;

use ClickHouse\Laravel\Connectors\ClickHouseConnector;
use PDO;

/**
 * Manages a cluster of ClickHouse nodes.
 *
 * Reads: failover — try nodes sequentially until one connects.
 * Writes: distributed — execute on ALL nodes in the cluster.
 */
class ClickHouseCluster
{
    protected array $nodes;
    protected int $activeIndex = 0;
    protected array $connections = [];
    protected array $baseConfig;
    protected ClickHouseConnector $connector;

    public function __construct(array $nodes, array $baseConfig, ClickHouseConnector $connector)
    {
        $this->nodes = array_values($nodes);
        $this->baseConfig = $baseConfig;
        $this->connector = $connector;
    }

    /**
     * Get a PDO connection for reads, with failover to next node on failure.
     */
    public function getReadConnection(): PDO
    {
        $tried = 0;
        $total = count($this->nodes);

        while ($tried < $total) {
            try {
                return $this->getConnectionForNode($this->activeIndex);
            } catch (\Throwable) {
                unset($this->connections[$this->activeIndex]);
                $this->slideNode();
                $tried++;
            }
        }

        throw new \RuntimeException('All ClickHouse cluster nodes are unreachable.');
    }

    /**
     * Get PDO connections for ALL nodes (for distributed writes).
     *
     * @return PDO[]
     */
    public function getWriteConnections(): array
    {
        $connections = [];

        foreach ($this->nodes as $index => $node) {
            $connections[] = $this->getConnectionForNode($index);
        }

        return $connections;
    }

    /**
     * Rotate to the next node in the cluster.
     */
    public function slideNode(): void
    {
        $this->activeIndex = ($this->activeIndex + 1) % count($this->nodes);
    }

    /**
     * Get or create a PDO connection for a specific node.
     */
    protected function getConnectionForNode(int $index): PDO
    {
        if (isset($this->connections[$index])) {
            return $this->connections[$index];
        }

        $nodeConfig = array_merge($this->baseConfig, $this->nodes[$index]);
        $this->connections[$index] = $this->connector->connect($nodeConfig);

        return $this->connections[$index];
    }

    public function getActiveIndex(): int
    {
        return $this->activeIndex;
    }

    public function getNodes(): array
    {
        return $this->nodes;
    }
}
