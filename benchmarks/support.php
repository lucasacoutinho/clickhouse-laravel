<?php

declare(strict_types=1);

use Composer\InstalledVersions;

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
