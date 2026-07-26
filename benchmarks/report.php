<?php

declare(strict_types=1);

/**
 * @return array<string, mixed>
 */
function loadResult(string $path): array
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException("Unable to read benchmark result [{$path}].");
    }

    $result = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($result)) {
        throw new RuntimeException("Invalid benchmark result [{$path}].");
    }

    return $result;
}

function textValue(array $values, string $key): string
{
    $value = $values[$key] ?? null;

    if (! is_string($value) && ! is_numeric($value)) {
        throw new RuntimeException("Benchmark field [{$key}] is missing.");
    }

    return (string) $value;
}

function winner(float $native, float $http): string
{
    $ratio = max($native, $http) / min($native, $http);

    if ($ratio < 1.05) {
        return 'tie (<5%)';
    }

    if ($native < $http) {
        return sprintf('native %.2f×', $http / $native);
    }

    return sprintf('HTTP %.2f×', $native / $http);
}

$native = loadResult($argv[1] ?? '');
$http = loadResult($argv[2] ?? '');

if (($native['stack'] ?? null) !== 'native' || ($http['stack'] ?? null) !== 'http') {
    throw new RuntimeException('Expected native and HTTP benchmark results, in that order.');
}

if (($native['dataset_rows'] ?? null) !== ($http['dataset_rows'] ?? null)) {
    throw new RuntimeException('Benchmark results used different datasets.');
}

$nativeRuntime = $native['runtime'] ?? null;
$httpRuntime = $http['runtime'] ?? null;
$nativeWorkloads = $native['workloads'] ?? null;
$httpWorkloads = $http['workloads'] ?? null;

if (
    ! is_array($nativeRuntime)
    || ! is_array($httpRuntime)
    || ! is_array($nativeWorkloads)
    || ! is_array($httpWorkloads)
) {
    throw new RuntimeException('Benchmark result metadata is incomplete.');
}

$labels = [
    'cold_connect_select_1' => 'Cold connect + SELECT 1',
    'reused_select_1' => 'Reused connection SELECT 1',
    'point_lookup' => 'Indexed point lookup',
    'aggregate_1m_rows' => 'Aggregate 1M rows',
    'fetch_1000_rows' => 'Fetch 1,000 rows',
    'insert_100_rows' => 'Insert 100 rows',
];

echo "# ClickHouse Laravel transport benchmark\n\n";
echo '- Native source: `'.textValue($native, 'source')."`\n";
echo '- HTTP source: `'.textValue($http, 'source')."`\n";
echo '- PHP: `'.textValue($nativeRuntime, 'php')."`\n";
echo '- Laravel framework: `'.textValue($nativeRuntime, 'laravel_framework')."`\n";
echo '- ClickHouse: `'.textValue($nativeRuntime, 'server')."`\n";
echo '- Dataset: `'.number_format((int) $native['dataset_rows'])."` rows\n\n";
echo "| Workload | Native median (p95) | HTTP median (p95) | Lower median |\n";
echo "|---|---:|---:|---:|\n";

foreach ($labels as $key => $label) {
    $nativeMetric = $nativeWorkloads[$key] ?? null;
    $httpMetric = $httpWorkloads[$key] ?? null;

    if (! is_array($nativeMetric) || ! is_array($httpMetric)) {
        throw new RuntimeException("Workload [{$key}] is missing.");
    }

    $nativeMedian = (float) ($nativeMetric['median_ms'] ?? 0);
    $httpMedian = (float) ($httpMetric['median_ms'] ?? 0);
    $nativeP95 = (float) ($nativeMetric['p95_ms'] ?? 0);
    $httpP95 = (float) ($httpMetric['p95_ms'] ?? 0);

    if ($nativeMedian <= 0 || $httpMedian <= 0) {
        throw new RuntimeException("Workload [{$key}] has invalid timing data.");
    }

    printf(
        "| %s | %.3f ms (%.3f) | %.3f ms (%.3f) | %s |\n",
        $label,
        $nativeMedian,
        $nativeP95,
        $httpMedian,
        $httpP95,
        winner($nativeMedian, $httpMedian),
    );
}

echo "\n";
echo 'Medians exclude container startup and benchmark warmups. ';
echo 'Differences below 5% are labeled as ties. ';
echo "“Native 2×” means the HTTP median was twice the native median.\n";
