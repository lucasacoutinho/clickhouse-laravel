<?php

namespace ClickHouse\Laravel;

use ClickHouse\Laravel\Connectors\ClickHouseConnector;
use ClickHouse\Laravel\Exceptions\TransactionsNotSupportedException;
use ClickHouse\Laravel\Query\ClickHouseQueryBuilder;
use ClickHouse\Laravel\Query\ClickHouseQueryGrammar;
use ClickHouse\Laravel\Schema\ClickHouseSchemaBuilder;
use ClickHouse\Laravel\Schema\ClickHouseSchemaGrammar;
use ClickHouse\Laravel\Support\ClickHouseSql;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use PDO;
use PDOException;
use ReflectionClass;
use Throwable;
use WeakMap;

/**
 * @api
 *
 * @psalm-suppress PropertyNotSetInConstructor Laravel owns inherited lazy state.
 */
class ClickHouseConnection extends Connection
{
    protected ?ClickHouseCluster $cluster = null;

    protected bool $settingsApplied = false;

    /** @var WeakMap<PDO, bool>|null */
    protected ?WeakMap $settingsAppliedPdos = null;

    /**
     * @param  (Closure(): PDO)|PDO  $pdo
     * @param  string  $database
     * @param  string  $tablePrefix
     * @param  array<string, mixed>  $config
     */
    public function __construct($pdo, $database = '', $tablePrefix = '', array $config = [])
    {
        parent::__construct($pdo, $database, $tablePrefix, $config);

        if (isset($config['cluster']) && is_array($config['cluster'])) {
            $this->cluster = new ClickHouseCluster(
                $config['cluster'],
                $config,
                new ClickHouseConnector,
            );
        }

        $this->validateRuntimeOptions($config);
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

        if (
            data_get($this->getConfig('query'), 'final', false)
            || data_get($this->getConfig('options'), 'final', false)
        ) {
            $builder->final();
        }

        return $builder;
    }

    public function table($table, $as = null, bool $final = false): ClickHouseQueryBuilder
    {
        $query = $this->query()->from($table, $as);

        return $final ? $query->final() : $query;
    }

    /**
     * Apply ClickHouse server settings via SET statements.
     * Settings come from config and are not user-supplied input.
     *
     * @psalm-suppress MixedAssignment Values are validated by ClickHouseSql::literal.
     */
    protected function applyServerSettings(PDO $pdo): void
    {
        $settings = $this->getConfig('settings') ?? [];

        if (! is_array($settings)) {
            throw new \InvalidArgumentException('ClickHouse settings must be an array.');
        }

        foreach ($settings as $key => $value) {
            if (! is_string($key)) {
                throw new \InvalidArgumentException('ClickHouse server setting names must be strings.');
            }

            $name = ClickHouseSql::settingName($key);
            ClickHouseSql::literal($value, "setting {$name}");
            $pdo->prepare("SET {$name} = ?")->execute([$value]);
        }
    }

    /**
     * Apply server settings once per PDO instance (used in cluster mode).
     */
    protected function applyServerSettingsOnce(PDO $pdo): void
    {
        $settingsAppliedPdos = $this->settingsAppliedPdos;

        if ($settingsAppliedPdos === null) {
            /** @var WeakMap<PDO, bool> $settingsAppliedPdos */
            $settingsAppliedPdos = new WeakMap;
            $this->settingsAppliedPdos = $settingsAppliedPdos;
        }

        if (! isset($settingsAppliedPdos[$pdo])) {
            $this->applyServerSettings($pdo);
            $settingsAppliedPdos[$pdo] = true;
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

        if (! $this->settingsApplied) {
            $this->applyServerSettings($pdo);
            $this->settingsApplied = true;
        }

        return $pdo;
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
     * Create a grammar instance, handling Laravel 12 vs 13 constructor differences.
     */
    /**
     * @template TGrammar of object
     *
     * @param  class-string<TGrammar>  $class
     * @return TGrammar
     */
    private function createGrammar(string $class): object
    {
        $constructor = (new ReflectionClass($class))->getConstructor();
        /** @psalm-suppress MixedMethodCall The class-string template is reflection-validated. */
        $grammar = $constructor !== null && $constructor->getNumberOfParameters() > 0
            ? new $class($this)
            : new $class;

        if (method_exists($this, 'withTablePrefix')) {
            /** @var TGrammar $grammar */
            $grammar = $this->withTablePrefix($grammar);
        }

        return $grammar;
    }

    public function getSchemaBuilder(): ClickHouseSchemaBuilder
    {
        parent::getSchemaBuilder();

        return new ClickHouseSchemaBuilder($this);
    }

    public function getCluster(): ?ClickHouseCluster
    {
        return $this->cluster;
    }

    /**
     * ClickHouse has no transactions. Passthrough mode is available only as an
     * explicit compatibility escape hatch for shared application code.
     */
    public function transaction(Closure $callback, $attempts = 1): mixed
    {
        $this->ensureTransactionPassthrough();

        return $callback($this);
    }

    public function beginTransaction(): void
    {
        $this->ensureTransactionPassthrough();
    }

    public function commit(): void
    {
        $this->ensureTransactionPassthrough();
    }

    public function rollBack($toLevel = null): void
    {
        $this->ensureTransactionPassthrough();
    }

    public function transactionLevel(): int
    {
        return 0;
    }

    public function disconnect(): void
    {
        $this->cluster?->disconnect();
        $this->resetSettingsState();
        parent::disconnect();
    }

    /**
     * @param  array<array-key, mixed>  $bindings
     * @param  string  $query
     */
    protected function tryAgainIfCausedByLostConnection(
        QueryException $e,
        $query,
        $bindings,
        Closure $callback,
    ) {
        if (! $this->isConnectionFailure($e->getPrevious() ?? $e)) {
            throw $e;
        }

        if (! $this->queryMayBeRetried($query)) {
            $this->invalidateFailedConnection();

            throw $e;
        }

        $configuredRetries = $this->integerConfig('retries', 0);
        $retries = $this->cluster
            ? max($configuredRetries, count($this->cluster->getNodes()) - 1)
            : $configuredRetries;
        /** @var non-empty-list<QueryException> $failures */
        $failures = [$e];

        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            $this->backoff($attempt);

            try {
                $this->discardFailedConnection();

                return $this->runQueryCallback($query, $bindings, $callback);
            } catch (QueryException $retryException) {
                if (! $this->isConnectionFailure($retryException->getPrevious() ?? $retryException)) {
                    throw $retryException;
                }

                $failures[] = $retryException;
            } catch (Throwable $retryException) {
                if (! $this->isConnectionFailure($retryException)) {
                    throw $retryException;
                }
            }
        }

        $this->invalidateFailedConnection();

        throw $failures[array_key_last($failures)];
    }

    protected function queryMayBeRetried(string $query): bool
    {
        if (filter_var($this->getConfig('retry_writes') ?? false, FILTER_VALIDATE_BOOL)) {
            return true;
        }

        $query = $this->stripLeadingSqlTrivia($query);

        return preg_match('/\A(?:SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|EXISTS)\b/i', $query) === 1;
    }

    protected function isConnectionFailure(Throwable $exception): bool
    {
        $current = $exception;

        do {
            if (
                $current instanceof LostConnectionException
                || $this->isNativeConnectionException($current)
            ) {
                return true;
            }

            if ($current instanceof PDOException) {
                $sqlState = isset($current->errorInfo[0])
                    && is_string($current->errorInfo[0])
                    ? $current->errorInfo[0]
                    : (string) $current->getCode();
                if (str_starts_with($sqlState, '08')) {
                    return true;
                }
            }

            if ($this->causedByLostConnection($current)) {
                return true;
            }

            if (preg_match(
                '/connection (?:refused|reset|closed|lost)|broken pipe|network is unreachable|socket|unexpected eof|all clickhouse cluster nodes are unreachable/i',
                $current->getMessage(),
            )) {
                return true;
            }

            $current = $current->getPrevious();
        } while ($current instanceof Throwable);

        return false;
    }

    private function isNativeConnectionException(Throwable $exception): bool
    {
        /** @var class-string $connectionException */
        $connectionException = 'ClickHouse\\Driver\\Exception\\ConnectionException';

        return is_a($exception, $connectionException);
    }

    private function discardFailedConnection(): void
    {
        $this->invalidateFailedConnection();

        if ($this->cluster) {
            return;
        }

        $this->reconnect();
    }

    private function invalidateFailedConnection(): void
    {
        $this->resetSettingsState();

        if ($this->cluster) {
            $this->cluster->invalidateActiveConnection();

            return;
        }

        parent::disconnect();
    }

    private function backoff(int $attempt): void
    {
        $baseMilliseconds = $this->integerConfig('retry_backoff_ms', 100);

        if ($baseMilliseconds === 0) {
            return;
        }

        $milliseconds = min(5000, $baseMilliseconds * (2 ** ($attempt - 1)));
        usleep($milliseconds * 1000);
    }

    private function stripLeadingSqlTrivia(string $query): string
    {
        do {
            $previous = $query;
            $query = ltrim($query);
            $query = preg_replace('/\A\/\*.*?\*\//s', '', $query) ?? $query;
            $query = preg_replace('/\A(?:--|#)[^\r\n]*(?:\r\n|\r|\n|$)/', '', $query) ?? $query;
        } while ($query !== $previous);

        return ltrim($query, " \t\n\r\0\x0B(");
    }

    private function ensureTransactionPassthrough(): void
    {
        if ($this->getConfig('transactions') !== 'passthrough') {
            throw TransactionsNotSupportedException::make();
        }
    }

    private function resetSettingsState(): void
    {
        $this->settingsApplied = false;
        $this->settingsAppliedPdos = null;
    }

    /** @param array<string, mixed> $config */
    private function validateRuntimeOptions(array $config): void
    {
        $retries = filter_var($config['retries'] ?? 0, FILTER_VALIDATE_INT);
        if ($retries === false || $retries < 0 || $retries > 100) {
            throw new \InvalidArgumentException(
                'ClickHouse retries must be an integer between 0 and 100.'
            );
        }

        $backoff = filter_var($config['retry_backoff_ms'] ?? 100, FILTER_VALIDATE_INT);
        if ($backoff === false || $backoff < 0 || $backoff > 5000) {
            throw new \InvalidArgumentException(
                'ClickHouse retry_backoff_ms must be an integer between 0 and 5000.'
            );
        }

        if (! is_array($config['settings'] ?? [])) {
            throw new \InvalidArgumentException('ClickHouse settings must be an array.');
        }

        if (
            isset($config['retry_writes'])
            && filter_var($config['retry_writes'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === null
        ) {
            throw new \InvalidArgumentException('ClickHouse retry_writes must be a boolean.');
        }

        if (
            isset($config['use_lightweight_delete'])
            && filter_var(
                $config['use_lightweight_delete'],
                FILTER_VALIDATE_BOOL,
                FILTER_NULL_ON_FAILURE,
            ) === null
        ) {
            throw new \InvalidArgumentException(
                'ClickHouse use_lightweight_delete must be a boolean.'
            );
        }

        $transactionMode = $config['transactions'] ?? 'throw';
        if (! in_array($transactionMode, ['throw', 'passthrough'], true)) {
            throw new \InvalidArgumentException(
                'ClickHouse transactions must be configured as "throw" or "passthrough".'
            );
        }
    }

    private function integerConfig(string $key, int $default): int
    {
        $value = filter_var(
            $this->getConfig($key) ?? $default,
            FILTER_VALIDATE_INT,
        );

        if (! is_int($value)) {
            throw new \InvalidArgumentException(
                "ClickHouse {$key} must be an integer."
            );
        }

        return $value;
    }
}
