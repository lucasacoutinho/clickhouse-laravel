<?php

declare(strict_types=1);

use ClickHouse\Driver\Client;
use ClickHouse\Driver\ClientOptions;
use ClickHouse\Laravel\ClickHouseConnection;
use ClickHouse\Laravel\Connectors\ClickHouseConnector;

require __DIR__.'/support.php';
require environment('BENCHMARK_AUTOLOAD', __DIR__.'/../vendor/autoload.php');

$driver = $argv[1] ?? '';
$output = $argv[2] ?? '';
if (! in_array($driver, ['ext-clickhouse', 'pdo-clickhouse', 'laravel'], true) || $output === '') {
    throw new InvalidArgumentException('Usage: client-workloads.php <ext-clickhouse|pdo-clickhouse|laravel> <output.json>');
}

$host = environment('BENCHMARK_CLICKHOUSE_HOST', '127.0.0.1');
$port = (int) environment('BENCHMARK_CLICKHOUSE_NATIVE_PORT', '9000');
$config = ['host' => $host, 'port' => $port, 'database' => 'default', 'username' => 'default', 'password' => ''];
if ($driver === 'ext-clickhouse') {
    $client = new Client(new ClientOptions($host, $port));
    $select = $client->select(...);
} else {
    $pdo = (new ClickHouseConnector)->connect($config);
    if ($driver === 'laravel') {
        $connection = new ClickHouseConnection($pdo, 'default', '', $config);
        $select = $connection->select(...);
    } else {
        $select = static fn (string $sql): array => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}

$count = 16_384;
$queries = [
    'decimal' => 'SELECT number AS id, toDecimal64(toInt64(number) - 8192, 4) AS value',
    'array' => 'SELECT number AS id, arrayMap(x -> toUInt32(number + x), range(32)) AS value',
];
$workloads = [];
foreach ($queries as $type => $query) {
    $sql = $query." FROM numbers({$count}) SETTINGS max_threads = 1";
    $workloads[$type] = measure(
        "{$driver} fetch {$count} {$type} rows",
        5,
        25,
        static fn (): array => $select($sql),
        static function (array $rows) use ($count, $type): void {
            if (count($rows) !== $count) {
                throw new RuntimeException('Unexpected result size.');
            }
            foreach ($rows as $i => $row) {
                $expected = $type === 'decimal' ? ($i - 8192).'.0000' : range($i, $i + 31);
                if (field($row, 'id') !== $i || field($row, 'value') !== $expected) {
                    throw new RuntimeException("Unexpected {$type} at row {$i}.");
                }
            }
        },
    );
}

$workloads['invoice_lines'] = measure(
    "{$driver} fetch {$count} invoice lines",
    5,
    25,
    static fn (): array => $select(
        "SELECT number AS id, toDecimal64(number, 2) + toDecimal64('0.49', 2) AS subtotal,
            toDecimal64(number % 13, 2) + toDecimal64('0.02', 2) AS tax, subtotal + tax AS total
         FROM numbers({$count}) SETTINGS max_threads = 1",
    ),
    static function (array $rows) use ($count): void {
        if (count($rows) !== $count) {
            throw new RuntimeException('Unexpected invoice line count.');
        }
        foreach ($rows as $i => $row) {
            if (field($row, 'id') !== $i
                || field($row, 'subtotal') !== $i.'.49'
                || field($row, 'tax') !== ($i % 13).'.02'
                || field($row, 'total') !== ($i + $i % 13).'.51') {
                throw new RuntimeException("Unexpected invoice line at row {$i}.");
            }
        }
    },
);

if (isset($connection)) {
    $connection->statement('CREATE TEMPORARY TABLE bench_insert (id UInt32, category UInt16, value Float64, payload String)');
    $batch = array_map(static fn (int $i): array => [
        'id' => $i, 'category' => $i % 100, 'value' => $i / 2, 'payload' => "event-{$i}",
    ], range(1, 10_000));
    $workloads['insert_10k'] = measure(
        'Laravel Query Builder insert of 10,000 rows',
        3,
        15,
        static fn (): bool => $connection->table('bench_insert')->insert($batch),
        static function (bool $ok) use ($connection): void {
            $stats = $connection->selectOne("SELECT count() AS n, uniqExact(id) AS ids,
                countIf(id BETWEEN 1 AND 10000 AND category = id % 100 AND value = id / 2
                    AND payload = concat('event-', toString(id))) AS valid FROM bench_insert");
            if (! $ok || $stats->n !== 10_000 || $stats->ids !== 10_000 || $stats->valid !== 10_000) {
                throw new RuntimeException('Incorrect batch insert.');
            }
            $connection->statement('TRUNCATE TABLE bench_insert');
        },
    );
}

$result = [
    'driver' => $driver,
    'php' => PHP_VERSION,
    'server' => field($select('SELECT version() AS version')[0], 'version'),
    'laravel' => packageVersion('laravel/framework'),
    'workloads' => $workloads,
];
if (file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL) === false) {
    throw new RuntimeException("Could not write {$output}.");
}
