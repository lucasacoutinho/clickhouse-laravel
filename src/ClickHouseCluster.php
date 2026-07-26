<?php

namespace ClickHouse\Laravel;

use ClickHouse\Laravel\Connectors\ClickHouseConnector;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Manages a cluster of ClickHouse nodes.
 *
 * Connections fail over sequentially. Replication and distributed writes are
 * intentionally delegated to ClickHouse engines and Distributed tables.
 *
 * @api
 */
class ClickHouseCluster
{
    /** @var list<array<string, mixed>> */
    protected array $nodes;

    protected int $activeIndex = 0;

    /** @var array<int, PDO> */
    protected array $connections = [];

    /** @var array<string, mixed> */
    protected array $baseConfig;

    protected ClickHouseConnector $connector;

    /**
     * @param  array<array-key, mixed>  $nodes
     * @param  array<string, mixed>  $baseConfig
     */
    public function __construct(
        array $nodes,
        array $baseConfig,
        ClickHouseConnector $connector,
    ) {
        if ($nodes === []) {
            throw new InvalidArgumentException('ClickHouse cluster must contain at least one node.');
        }

        $validatedNodes = [];

        foreach ($nodes as $node) {
            if (! is_array($node) || blank($node['host'] ?? null)) {
                throw new InvalidArgumentException(
                    'Each ClickHouse cluster node must define a non-empty host.'
                );
            }

            /** @var array<string, mixed> $node */
            $validatedNodes[] = $node;
        }

        $this->nodes = $validatedNodes;
        unset($baseConfig['cluster']);
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
        $previous = null;

        while ($tried < $total) {
            try {
                return $this->getConnectionForNode($this->activeIndex);
            } catch (InvalidArgumentException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                $previous = $exception;
                unset($this->connections[$this->activeIndex]);
                $this->slideNode();
                $tried++;
            }
        }

        throw new RuntimeException('All ClickHouse cluster nodes are unreachable.', 0, $previous);
    }

    /**
     * Get the active connection for both reads and writes.
     *
     * Writes are not automatically replayed or fanned out. Use ReplicatedMergeTree
     * or Distributed tables so ClickHouse owns replication and failure semantics.
     */
    public function getWriteConnection(): PDO
    {
        return $this->getReadConnection();
    }

    /**
     * Return connections for every node for explicit administrative use only.
     *
     * @return PDO[]
     */
    public function getWriteConnections(): array
    {
        $connections = [];

        foreach (array_keys($this->nodes) as $index) {
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
     * Forget a failed active connection and move subsequent work to the next node.
     */
    public function invalidateActiveConnection(bool $rotate = true): void
    {
        unset($this->connections[$this->activeIndex]);

        if ($rotate) {
            $this->slideNode();
        }
    }

    public function disconnect(): void
    {
        $this->connections = [];
        $this->activeIndex = 0;
    }

    /**
     * Get or create a PDO connection for a specific node.
     */
    protected function getConnectionForNode(int $index): PDO
    {
        if (isset($this->connections[$index])) {
            return $this->connections[$index];
        }

        $node = $this->nodes[$index] ?? throw new InvalidArgumentException(
            "Unknown ClickHouse cluster node index: {$index}."
        );
        $nodeConfig = array_merge($this->baseConfig, $node);
        unset($nodeConfig['cluster']);
        $this->connections[$index] = $this->connector->connect($nodeConfig);

        return $this->connections[$index];
    }

    public function getActiveIndex(): int
    {
        return $this->activeIndex;
    }

    /** @return list<array<string, mixed>> */
    public function getNodes(): array
    {
        return $this->nodes;
    }
}
