<?php

declare(strict_types=1);

use ClickHouse\Laravel\ClickHouseConnection;
use ClickHouse\Laravel\Connectors\ClickHouseConnector;
use Composer\InstalledVersions;
use Illuminate\Database\Connection;

const DATASET_ROWS = 1_000_000;

/**
 * @return non-empty-string
 */
function environment(string $name, string $default): string
{
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : $default;
}

function field(mixed $row, string $name): mixed
{
    if (is_array($row) && array_key_exists($name, $row)) {
        return $row[$name];
    }

    if (is_object($row) && property_exists($row, $name)) {
        return $row->{$name};
    }

    throw new RuntimeException("Result field [{$name}] is missing.");
}

function createConnection(string $stack): Connection
{
    $host = environment('BENCHMARK_CLICKHOUSE_HOST', '127.0.0.1');
    $database = environment('BENCHMARK_DATABASE', 'default');

    if ($stack === 'native') {
        $config = [
            'driver' => 'clickhouse',
            'host' => $host,
            'port' => (int) environment('BENCHMARK_CLICKHOUSE_NATIVE_PORT', '9000'),
            'database' => $database,
            'username' => 'default',
            'password' => '',
            'persistent' => false,
        ];

        $pdo = (new ClickHouseConnector)->connect($config);

        return new ClickHouseConnection(
            $pdo,
            $database,
            '',
            $config,
        );
    }

    if ($stack === 'http') {
        return new ClickHouse\Laravel\Connection(
            $database,
            '',
            [
                'host' => $host,
                'port' => (int) environment('BENCHMARK_CLICKHOUSE_HTTP_PORT', '8123'),
                'username' => 'default',
                'password' => '',
                'transport' => 'guzzle',
                'https' => false,
            ],
        );
    }

    throw new RuntimeException("Unknown benchmark stack [{$stack}].");
}

function resultFingerprint(mixed $result): int
{
    if (is_array($result)) {
        if ($result === []) {
            return 0;
        }

        $first = $result[array_key_first($result)];
        $value = 0;

        foreach (['id', 'value', 'total', 'category'] as $field) {
            try {
                $candidate = field($first, $field);
            } catch (RuntimeException) {
                continue;
            }

            if (is_numeric($candidate)) {
                $value = (int) $candidate;
                break;
            }
        }

        return count($result) + $value;
    }

    return $result === true ? 1 : 0;
}

/**
 * @param  Closure(int): mixed  $operation
 * @param  Closure(mixed, int): void  $validate
 * @return array{
 *     iterations: int,
 *     warmups: int,
 *     min_ms: float,
 *     median_ms: float,
 *     mean_ms: float,
 *     p95_ms: float,
 *     max_ms: float,
 *     standard_deviation_ms: float,
 *     median_operations_per_second: float,
 *     fingerprint: int
 * }
 */
function measure(
    string $name,
    int $warmups,
    int $iterations,
    Closure $operation,
    Closure $validate,
): array {
    fwrite(STDERR, "Running {$name}: {$warmups} warmups, {$iterations} samples\n");

    for ($iteration = 0; $iteration < $warmups; $iteration++) {
        $result = $operation($iteration);
        $validate($result, $iteration);
        unset($result);
    }

    gc_collect_cycles();
    $samples = [];
    $fingerprint = 0;

    for ($iteration = 0; $iteration < $iterations; $iteration++) {
        $sample = $iteration + $warmups;
        $start = hrtime(true);
        $result = $operation($sample);
        $samples[] = (hrtime(true) - $start) / 1_000_000;

        $validate($result, $sample);
        $fingerprint += resultFingerprint($result);
        unset($result);

        if ($iteration % 25 === 24) {
            gc_collect_cycles();
        }
    }

    sort($samples, SORT_NUMERIC);
    $count = count($samples);
    $mean = array_sum($samples) / $count;
    $variance = array_sum(array_map(
        static fn (float $sample): float => ($sample - $mean) ** 2,
        $samples,
    )) / $count;
    $median = $count % 2 === 0
        ? ($samples[(int) ($count / 2) - 1] + $samples[(int) ($count / 2)]) / 2
        : $samples[(int) floor($count / 2)];
    $p95 = $samples[max(0, (int) ceil($count * 0.95) - 1)];

    return [
        'iterations' => $iterations,
        'warmups' => $warmups,
        'min_ms' => round($samples[0], 6),
        'median_ms' => round($median, 6),
        'mean_ms' => round($mean, 6),
        'p95_ms' => round($p95, 6),
        'max_ms' => round($samples[$count - 1], 6),
        'standard_deviation_ms' => round(sqrt($variance), 6),
        'median_operations_per_second' => round(1000 / $median, 2),
        'fingerprint' => $fingerprint,
    ];
}

function packageVersion(string $package): string
{
    return InstalledVersions::isInstalled($package)
        ? (InstalledVersions::getPrettyVersion($package) ?? 'unknown')
        : 'unknown';
}

$stack = $argv[1] ?? '';
$output = $argv[2] ?? '';

if (! in_array($stack, ['native', 'http'], true) || $output === '') {
    fwrite(STDERR, "Usage: php benchmark.php <native|http> <output.json>\n");
    exit(2);
}

require $stack === 'native'
    ? '/opt/native/vendor/autoload.php'
    : '/opt/http/vendor/autoload.php';

$probe = createConnection($stack);
$serverVersionRows = $probe->select('SELECT version() AS version');
$datasetRows = $probe->select('SELECT count() AS total FROM benchmark_events');
$serverVersion = (string) field($serverVersionRows[0] ?? null, 'version');
$rowCount = (int) field($datasetRows[0] ?? null, 'total');
$probe->disconnect();

if ($rowCount !== DATASET_ROWS) {
    throw new RuntimeException(
        'Expected '.DATASET_ROWS." benchmark rows, found {$rowCount}.",
    );
}

$workloads = [];

$workloads['cold_connect_select_1'] = measure(
    'cold connection + SELECT 1',
    5,
    50,
    static function () use ($stack): array {
        $connection = createConnection($stack);
        $result = $connection->select('SELECT 1 AS value');
        $connection->disconnect();

        return $result;
    },
    static function (mixed $result): void {
        if (! is_array($result) || (int) field($result[0] ?? null, 'value') !== 1) {
            throw new RuntimeException('Cold SELECT 1 returned an unexpected result.');
        }
    },
);

$connection = createConnection($stack);

$workloads['reused_select_1'] = measure(
    'reused connection SELECT 1',
    30,
    250,
    static fn (): array => $connection->select('SELECT 1 AS value'),
    static function (mixed $result): void {
        if (! is_array($result) || (int) field($result[0] ?? null, 'value') !== 1) {
            throw new RuntimeException('SELECT 1 returned an unexpected result.');
        }
    },
);

$workloads['point_lookup'] = measure(
    'indexed point lookup',
    20,
    150,
    static function (int $sample) use ($connection): array {
        $id = ($sample * 7919) % DATASET_ROWS;

        return $connection->select(
            'SELECT id, category, value FROM benchmark_events WHERE id = ?',
            [$id],
        );
    },
    static function (mixed $result, int $sample): void {
        $expected = ($sample * 7919) % DATASET_ROWS;

        if (
            ! is_array($result)
            || count($result) !== 1
            || (int) field($result[0], 'id') !== $expected
        ) {
            throw new RuntimeException('Point lookup returned an unexpected result.');
        }
    },
);

$workloads['aggregate_1m_rows'] = measure(
    'aggregate 1,000,000 rows',
    5,
    50,
    static fn (): array => $connection->select(
        'SELECT category, count() AS total, sum(value) AS value_sum
         FROM benchmark_events
         GROUP BY category
         ORDER BY category',
    ),
    static function (mixed $result): void {
        if (! is_array($result) || count($result) !== 100) {
            throw new RuntimeException('Aggregate query returned an unexpected result.');
        }
    },
);

$workloads['fetch_1000_rows'] = measure(
    'fetch 1,000 rows with a 64-byte payload',
    5,
    30,
    static function (int $sample) use ($connection): array {
        $start = ($sample * 7919) % 999_001;

        return $connection->select(
            'SELECT id, category, value, payload
             FROM benchmark_events
             WHERE id >= ?
             ORDER BY id
             LIMIT 1000',
            [$start],
        );
    },
    static function (mixed $result): void {
        if (! is_array($result) || count($result) !== 1000) {
            throw new RuntimeException('Row fetch returned an unexpected result.');
        }
    },
);

$insertRows = array_map(
    static fn (int $id): array => [
        'id' => $id,
        'category' => $id % 100,
        'value' => $id / 2,
        'payload' => str_repeat('x', 64),
    ],
    range(1, 100),
);

$workloads['insert_100_rows'] = measure(
    'Query Builder insert of 100 rows',
    3,
    20,
    static fn (): bool => $connection->table('benchmark_sink')->insert($insertRows),
    static function (mixed $result): void {
        if ($result !== true) {
            throw new RuntimeException('Insert did not report success.');
        }
    },
);

$connection->disconnect();

$result = [
    'schema_version' => 1,
    'generated_at' => gmdate(DATE_ATOM),
    'stack' => $stack,
    'transport' => $stack === 'native'
        ? 'ClickHouse native TCP through pdo_clickhouse'
        : 'ClickHouse HTTP through Guzzle',
    'source' => $stack === 'native'
        ? environment('NATIVE_SOURCE_REF', 'working-tree')
        : 'laravel-clickhouse/laravel-clickhouse 1.1.3',
    'runtime' => [
        'php' => PHP_VERSION,
        'laravel_framework' => packageVersion('laravel/framework'),
        'package' => $stack === 'native'
            ? packageVersion('lucasacoutinho/clickhouse-laravel')
            : packageVersion('laravel-clickhouse/laravel-clickhouse'),
        'guzzle' => $stack === 'http'
            ? packageVersion('guzzlehttp/guzzle')
            : null,
        'ext_clickhouse' => phpversion('clickhouse') ?: null,
        'ext_pdo_clickhouse' => phpversion('pdo_clickhouse') ?: null,
        'server' => $serverVersion,
    ],
    'dataset_rows' => $rowCount,
    'workloads' => $workloads,
];

$json = json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

if (file_put_contents($output, $json) === false) {
    throw new RuntimeException("Unable to write benchmark result [{$output}].");
}

fwrite(STDERR, "Wrote {$output}\n");
