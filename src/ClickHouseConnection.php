<?php

namespace ClickHouse\Laravel;

use Closure;
use ClickHouse\Laravel\Connectors\ClickHouseConnector;
use ClickHouse\Laravel\Query\ClickHouseQueryBuilder;
use ClickHouse\Laravel\Query\ClickHouseQueryGrammar;
use ClickHouse\Laravel\Schema\ClickHouseSchemaBuilder;
use ClickHouse\Laravel\Schema\ClickHouseSchemaGrammar;
use Illuminate\Database\Connection;

class ClickHouseConnection extends Connection
{
    protected ?ClickHouseCluster $cluster = null;
    protected bool $settingsApplied = false;
    protected array $settingsAppliedPdos = [];

    public function __construct($pdo, $database = '', $tablePrefix = '', array $config = [])
    {
        parent::__construct($pdo, $database, $tablePrefix, $config);

        if (isset($config['cluster']) && is_array($config['cluster'])) {
            $this->cluster = new ClickHouseCluster(
                $config['cluster'],
                $config,
                new ClickHouseConnector(),
            );
        }
    }

    public function getDriverName(): string
    {
        return 'clickhouse';
    }

    public function query(): ClickHouseQueryBuilder
    {
        $builder = new ClickHouseQueryBuilder(
            $this,
            $this->getQueryGrammar(),
            $this->getPostProcessor()
        );

        if (data_get($this->getConfig('options'), 'final', false)) {
            $builder->final();
        }

        return $builder;
    }

    public function table($table, $as = null): ClickHouseQueryBuilder
    {
        return $this->query()->from($table, $as);
    }

    /**
     * Apply ClickHouse server settings via SET statements.
     * Settings come from config and are not user-supplied input.
     */
    protected function applyServerSettings(\PDO $pdo): void
    {
        foreach ($this->getConfig('settings') ?? [] as $key => $value) {
            $pdo->prepare("SET {$key} = ?")->execute([$value]);
        }
    }

    /**
     * Apply server settings once per PDO instance (used in cluster mode).
     */
    protected function applyServerSettingsOnce(\PDO $pdo): void
    {
        $oid = spl_object_id($pdo);

        if (!isset($this->settingsAppliedPdos[$oid])) {
            $this->applyServerSettings($pdo);
            $this->settingsAppliedPdos[$oid] = true;
        }
    }

    /**
     * Get the PDO connection, applying server settings on first use.
     * Delegates to cluster for reads when cluster is configured.
     */
    public function getPdo()
    {
        if ($this->cluster) {
            $pdo = $this->cluster->getReadConnection();
            $this->applyServerSettingsOnce($pdo);
            return $pdo;
        }

        $pdo = parent::getPdo();

        if (!$this->settingsApplied) {
            $this->applyServerSettings($pdo);
            $this->settingsApplied = true;
        }

        return $pdo;
    }

    /**
     * Execute a statement, distributing to all cluster nodes for writes.
     */
    public function statement($query, $bindings = []): bool
    {
        if ($this->cluster) {
            return $this->executeOnCluster($query, $bindings);
        }

        return parent::statement($query, $bindings);
    }

    /**
     * Execute an affecting statement, distributing to all cluster nodes.
     */
    public function affectingStatement($query, $bindings = []): int
    {
        if ($this->cluster) {
            $this->executeOnCluster($query, $bindings);
            return 0; // ClickHouse doesn't reliably return affected row counts
        }

        return parent::affectingStatement($query, $bindings);
    }

    /**
     * Execute a write query on all cluster nodes.
     *
     * Note: if a node fails mid-loop, earlier nodes will have already committed.
     * ClickHouse does not support distributed transactions — partial writes are possible.
     */
    protected function executeOnCluster(string $query, array $bindings): bool
    {
        foreach ($this->cluster->getWriteConnections() as $pdo) {
            $this->applyServerSettingsOnce($pdo);
            $statement = $pdo->prepare($query);
            $statement->execute($this->prepareBindings($bindings));
        }

        return true;
    }

    /**
     * Retry-aware query execution.
     */
    protected function run($query, $bindings, Closure $callback)
    {
        $retries = (int) ($this->getConfig('retries') ?? 0);
        $attempts = 0;

        while (true) {
            try {
                return parent::run($query, $bindings, $callback);
            } catch (\Throwable $e) {
                if (++$attempts > $retries) {
                    throw $e;
                }

                $this->settingsApplied = false;
                $this->reconnect();
            }
        }
    }

    protected function getDefaultQueryGrammar(): ClickHouseQueryGrammar
    {
        return $this->createGrammar(ClickHouseQueryGrammar::class);
    }

    protected function getDefaultSchemaGrammar(): ClickHouseSchemaGrammar
    {
        return $this->createGrammar(ClickHouseSchemaGrammar::class);
    }

    /**
     * Create a grammar instance, handling Laravel 10-12 vs 13 constructor differences.
     */
    private function createGrammar(string $class): object
    {
        try {
            $grammar = new $class($this);
        } catch (\Throwable) {
            $grammar = new $class();
        }

        return method_exists($this, 'withTablePrefix')
            ? $this->withTablePrefix($grammar)
            : $grammar;
    }

    public function getSchemaBuilder(): ClickHouseSchemaBuilder
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new ClickHouseSchemaBuilder($this);
    }

    public function getCluster(): ?ClickHouseCluster
    {
        return $this->cluster;
    }

    /**
     * ClickHouse has no transactions.
     * We run the callback directly — this makes DB::transaction() safe to use
     * in code shared between MySQL and ClickHouse connections.
     */
    public function transaction(Closure $callback, $attempts = 1): mixed
    {
        return $callback($this);
    }

    public function beginTransaction(): void
    {
        // no-op
    }

    public function commit(): void
    {
        // no-op
    }

    public function rollBack($toLevel = null): void
    {
        // no-op — don't throw, packages like Horizon wrap in transactions
    }

    public function transactionLevel(): int
    {
        return 0;
    }
}
