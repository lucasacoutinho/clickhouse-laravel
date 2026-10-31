<?php

declare(strict_types=1);

$results = [];

foreach (array_slice($argv, 1) as $path) {
    $result = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $results[$result['driver']][] = $result;
}

if ($results === []) {
    fwrite(STDERR, "Usage: php drivers-report.php <result.json>...\n");
    exit(2);
}

function median(array $values): float
{
    sort($values, SORT_NUMERIC);
    $count = count($values);

    return $count % 2 === 0
        ? ($values[(int) ($count / 2) - 1] + $values[(int) ($count / 2)]) / 2
        : (float) $values[(int) floor($count / 2)];
}

function milliseconds(float $value): string
{
    return number_format($value, $value >= 100 ? 1 : 3);
}

$labels = [
    'cold_connect_select_1' => 'Cold connect + SELECT 1',
    'reused_select_1' => 'Reused SELECT 1',
    'point_lookup' => 'Indexed point lookup',
    'aggregate_1m_rows' => 'Aggregate 1M rows',
    'fetch_1000_rows' => 'Fetch 1,000 rows',
    'fetch_100k_rows' => 'Fetch 100,000 rows',
    'insert_100_rows' => 'Insert 100 rows',
    'insert_10k_rows' => 'Insert 10,000 rows',
];
$order = ['ext-clickhouse', 'pdo-clickhouse', 'one-ck', 'smi2', 'pdo-mysql'];
$drivers = array_values(array_intersect($order, array_keys($results)));
$first = $results[$drivers[0]][0];
$baseline = $drivers[0];

echo "# PHP ClickHouse driver benchmark\n\n";
echo '- PHP: `'.$first['runtime']['php']."`\n";
echo '- ClickHouse: `'.$first['runtime']['server']."`\n";
echo '- Rounds per driver: `'.count($results[$baseline])."`\n\n";

foreach ($drivers as $driver) {
    $result = $results[$driver][0];
    echo sprintf("- `%s` %s: %s\n", $result['label'], $result['version'], $result['transport']);
}

echo "\nMedian of per-round medians in milliseconds. Multiples are relative to `{$results[$baseline][0]['label']}`.\n\n";
echo '| Workload | '.implode(' | ', array_map(static fn (string $driver): string => $results[$driver][0]['label'], $drivers))." |\n";
echo '|---|'.str_repeat('---:|', count($drivers))."\n";

$spread = [];

foreach ($labels as $key => $label) {
    $base = median(array_map(static fn (array $result): float => $result['workloads'][$key]['median_ms'], $results[$baseline]));
    $cells = [];

    foreach ($drivers as $driver) {
        $medians = array_map(static fn (array $result): float => $result['workloads'][$key]['median_ms'], $results[$driver]);
        $value = median($medians);
        $spread[] = (max($medians) - min($medians)) / $value;
        $cells[] = $driver === $baseline
            ? milliseconds($value)
            : sprintf('%s (%.2f×)', milliseconds($value), $value / $base);
    }

    echo "| {$label} | ".implode(' | ', $cells)." |\n";
}

$rss = array_map(
    static fn (string $driver): string => number_format(
        median(array_map(static fn (array $result): float => $result['peak_rss_kb'] / 1024, $results[$driver])),
        1,
    ).' MiB',
    $drivers,
);
echo '| Peak process RSS | '.implode(' | ', $rss)." |\n";

printf("\nLargest spread between rounds: %.0f%% of the median; median spread: %.1f%%.\n", max($spread) * 100, median($spread) * 100);
