<?php

declare(strict_types=1);

require '/opt/drivers/vendor/autoload.php';
require __DIR__.'/support.php';

use ClickHouse\Driver\Block;
use ClickHouse\Driver\Client as NativeClient;
use ClickHouse\Driver\ClientOptions;
use ClickHouse\Driver\Column;
use ClickHouseDB\Client as Smi2Client;
use OneCk\Client as OneCkClient;

const DATASET_ROWS = 1_000_000;
const SINK_COLUMNS = ['id', 'category', 'value', 'payload'];

interface Driver
{
    /** @return list<array<string, mixed>> */
    public function select(string $sql): array;

    /** @param list<array{id: int, category: int, value: float, payload: string}> $rows */
    public function insert(array $rows): void;
}

function valuesInsert(array $rows): string
{
    $tuples = array_map(
        static fn (array $row): string => sprintf(
            "(%d,%d,%s,'%s')",
            $row['id'],
            $row['category'],
            var_export($row['value'], true),
            $row['payload'],
        ),
        $rows,
    );

    return 'INSERT INTO benchmark_sink ('.implode(',', SINK_COLUMNS).') VALUES '.implode(',', $tuples);
}

final class ExtClickHouseDriver implements Driver
{
    private NativeClient $client;

    public function __construct(string $host)
    {
        $this->client = new NativeClient(new ClientOptions($host, 9000));
    }

    public function select(string $sql): array
    {
        return $this->client->select($sql);
    }

    public function insert(array $rows): void
    {
        $block = new Block;
        $block->appendColumn('id', Column::create('UInt64', array_column($rows, 'id')));
        $block->appendColumn('category', Column::create('UInt16', array_column($rows, 'category')));
        $block->appendColumn('value', Column::create('Float64', array_column($rows, 'value')));
        $block->appendColumn('payload', Column::create('String', array_column($rows, 'payload')));
        $this->client->insert('benchmark_sink', $block);
    }
}

final class PdoDriver implements Driver
{
    private PDO $pdo;

    public function __construct(string $dsn)
    {
        $this->pdo = new PDO($dsn, 'default', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function select(string $sql): array
    {
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insert(array $rows): void
    {
        $this->pdo->exec(valuesInsert($rows));
    }
}

final class Smi2Driver implements Driver
{
    private Smi2Client $client;

    public function __construct(string $host)
    {
        $this->client = new Smi2Client([
            'host' => $host,
            'port' => 8123,
            'username' => 'default',
            'password' => '',
        ]);
        $this->client->database('default');
    }

    public function select(string $sql): array
    {
        return $this->client->select($sql)->rows();
    }

    public function insert(array $rows): void
    {
        $this->client->insert(
            'benchmark_sink',
            array_map(array_values(...), $rows),
            SINK_COLUMNS,
        );
    }
}

final class OneCkDriver implements Driver
{
    private OneCkClient $client;

    public function __construct(string $host)
    {
        $this->client = new OneCkClient("tcp://{$host}:9000", 'default', '', 'default', ['tcp_nodelay' => true]);
    }

    public function select(string $sql): array
    {
        return $this->client->query($sql);
    }

    public function insert(array $rows): void
    {
        $this->client->insert('benchmark_sink', $rows);
    }
}

/** @return array{label: string, transport: string, version: string, open: Closure(): Driver} */
function driverDefinition(string $name, string $host): array
{
    return match ($name) {
        'ext-clickhouse' => [
            'label' => 'ext-clickhouse',
            'transport' => 'Native TCP, C++ extension',
            'version' => (string) phpversion('clickhouse'),
            'open' => static fn (): Driver => new ExtClickHouseDriver($host),
        ],
        'pdo-clickhouse' => [
            'label' => 'pdo_clickhouse',
            'transport' => 'Native TCP, C++ extension through PDO',
            'version' => (string) phpversion('pdo_clickhouse'),
            'open' => static fn (): Driver => new PdoDriver("clickhouse:host={$host};port=9000;dbname=default"),
        ],
        'smi2' => [
            'label' => 'smi2/phpclickhouse',
            'transport' => 'HTTP through curl',
            'version' => packageVersion('smi2/phpclickhouse'),
            'open' => static fn (): Driver => new Smi2Driver($host),
        ],
        'one-ck' => [
            'label' => 'lizhichao/one-ck',
            'transport' => 'Native TCP, pure PHP',
            'version' => packageVersion('lizhichao/one-ck'),
            'open' => static fn (): Driver => new OneCkDriver($host),
        ],
        'pdo-mysql' => [
            'label' => 'pdo_mysql',
            'transport' => 'MySQL wire protocol, ClickHouse port 9004',
            'version' => (string) phpversion('pdo_mysql'),
            'open' => static fn (): Driver => new PdoDriver("mysql:host={$host};port=9004;dbname=default"),
        ],
        default => throw new RuntimeException("Unknown driver [{$name}]."),
    };
}

/** @return list<array{id: int, category: int, value: float, payload: string}> */
function sinkRows(int $count): array
{
    return array_map(
        static fn (int $id): array => [
            'id' => $id,
            'category' => $id % 100,
            'value' => $id / 2,
            'payload' => str_repeat('x', 64),
        ],
        range(1, $count),
    );
}

function peakRssKilobytes(): int
{
    $status = (string) file_get_contents('/proc/self/status');

    if (preg_match('/^VmHWM:\s+(\d+) kB$/m', $status, $match) !== 1) {
        throw new RuntimeException('VmHWM is unavailable.');
    }

    return (int) $match[1];
}

function assertWindow(mixed $rows, int $start, int $count): void
{
    if (
        ! is_array($rows)
        || count($rows) !== $count
        || (int) $rows[0]['id'] !== $start
        || (int) $rows[$count - 1]['id'] !== $start + $count - 1
        || strlen($rows[$count - 1]['payload']) !== 64
    ) {
        throw new RuntimeException("Fetch of {$count} rows returned an unexpected result.");
    }
}

function windowQuery(int $start, int $count): string
{
    return "SELECT id, category, value, payload FROM benchmark_events WHERE id >= {$start} ORDER BY id LIMIT {$count}";
}

$name = $argv[1] ?? '';
$output = $argv[2] ?? '';

if ($name === '' || $output === '') {
    fwrite(STDERR, "Usage: php drivers.php <driver> <output.json>\n");
    exit(2);
}

$definition = driverDefinition($name, environment('BENCHMARK_CLICKHOUSE_HOST', '127.0.0.1'));
$open = $definition['open'];
$driver = $open();
$server = (string) field($driver->select('SELECT version() AS version')[0] ?? null, 'version');
$datasetRows = (int) field($driver->select('SELECT count() AS total FROM benchmark_events')[0] ?? null, 'total');

if ($datasetRows !== DATASET_ROWS) {
    throw new RuntimeException('Expected '.DATASET_ROWS." benchmark rows, found {$datasetRows}.");
}

$assertOne = static function (mixed $rows): void {
    if (! is_array($rows) || (int) field($rows[0] ?? null, 'value') !== 1) {
        throw new RuntimeException('SELECT 1 returned an unexpected result.');
    }
};
$noResult = static function (mixed $result): void {};
$sink100 = sinkRows(100);
$sink10k = sinkRows(10_000);

$workloads = [];

$workloads['cold_connect_select_1'] = measure('cold connect + SELECT 1', 5, 50, static function () use ($open): array {
    $connection = $open();
    $rows = $connection->select('SELECT 1 AS value');
    unset($connection);

    return $rows;
}, $assertOne);

$workloads['reused_select_1'] = measure(
    'reused SELECT 1',
    50,
    500,
    static fn (): array => $driver->select('SELECT 1 AS value'),
    $assertOne,
);

$workloads['point_lookup'] = measure(
    'point lookup',
    20,
    200,
    static function (int $sample) use ($driver): array {
        $id = ($sample * 7919) % DATASET_ROWS;

        return $driver->select("SELECT id, category, value FROM benchmark_events WHERE id = {$id}");
    },
    static function (mixed $rows, int $sample): void {
        if (count($rows) !== 1 || (int) $rows[0]['id'] !== ($sample * 7919) % DATASET_ROWS) {
            throw new RuntimeException('Point lookup returned an unexpected result.');
        }
    },
);

$workloads['aggregate_1m_rows'] = measure(
    'aggregate 1M rows',
    5,
    50,
    static fn (): array => $driver->select(
        'SELECT category, count() AS total, sum(value) AS value_sum
         FROM benchmark_events GROUP BY category ORDER BY category',
    ),
    static function (mixed $rows): void {
        if (count($rows) !== 100) {
            throw new RuntimeException('Aggregate returned an unexpected result.');
        }
    },
);

$workloads['fetch_1000_rows'] = measure(
    'fetch 1,000 rows',
    5,
    50,
    static fn (int $sample): array => $driver->select(windowQuery(($sample * 7919) % 999_001, 1_000)),
    static fn (mixed $rows, int $sample) => assertWindow($rows, ($sample * 7919) % 999_001, 1_000),
);

$workloads['fetch_100k_rows'] = measure(
    'fetch 100,000 rows',
    2,
    15,
    static fn (int $sample): array => $driver->select(windowQuery(($sample * 7919) % 900_001, 100_000)),
    static fn (mixed $rows, int $sample) => assertWindow($rows, ($sample * 7919) % 900_001, 100_000),
);

$workloads['insert_100_rows'] = measure(
    'insert 100 rows',
    3,
    20,
    static function () use ($driver, $sink100): bool {
        $driver->insert($sink100);

        return true;
    },
    $noResult,
);

$workloads['insert_10k_rows'] = measure(
    'insert 10,000 rows',
    2,
    15,
    static function () use ($driver, $sink10k): bool {
        $driver->insert($sink10k);

        return true;
    },
    $noResult,
);

unset($driver);

$result = [
    'schema_version' => 1,
    'generated_at' => gmdate(DATE_ATOM),
    'driver' => $name,
    'label' => $definition['label'],
    'transport' => $definition['transport'],
    'version' => $definition['version'],
    'runtime' => ['php' => PHP_VERSION, 'server' => $server],
    'dataset_rows' => $datasetRows,
    'peak_rss_kb' => peakRssKilobytes(),
    'workloads' => $workloads,
];

$json = json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

if (file_put_contents($output, $json) === false) {
    throw new RuntimeException("Unable to write benchmark result [{$output}].");
}

fwrite(STDERR, "Wrote {$output}\n");
