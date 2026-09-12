# Performance comparison

This microbenchmark compares the package's native ClickHouse TCP/PDO path with
`laravel-clickhouse/laravel-clickhouse` 1.1.3 using that package's default
Guzzle HTTP transport.

It runs both clients with PHP 8.5.8, Laravel 13.22.0, and the same
ClickHouse 26.6.2.81 container and one-million-row dataset. The client image is
shared, so the PHP build, operating system, CPU allocation, and Docker network
are identical. Each workload is warmed before its measured samples.

The current native fixture uses `clickhouse` and `pdo_clickhouse` v1.4.1. The
reference result below records its original v1.2.0 run for historical accuracy.

Run it from the repository root:

```bash
./benchmarks/run.sh
```

Set `BENCHMARK_RESULTS_DIR` to retain the two raw JSON result files:

```bash
BENCHMARK_RESULTS_DIR="$PWD/benchmark-results" ./benchmarks/run.sh
```

Use `BENCHMARK_STACK_ORDER="native http"` to reverse the default client order
when checking for cache or ordering effects.

The benchmark covers:

- a new client connection followed by `SELECT 1`;
- a reused connection running `SELECT 1`;
- an indexed point lookup;
- a grouped aggregate over one million rows;
- materializing 1,000 rows with a 64-byte payload; and
- a Laravel Query Builder insert of 100 rows into a `Null` engine table.

## Reference result

Reference measurements will vary with hardware and system load. Treat the
ratios as a local transport/profile comparison, not a universal throughput
claim.

<!-- BENCHMARK_RESULTS_START -->

The following reference run was captured on July 26, 2026, using an AMD Ryzen
9 9900X under WSL2 and Docker 29.6.2. The clients used PHP 8.5.8 and Laravel
13.22.0. The native side used this repository at `c9c76e5322c9` with
`clickhouse` and `pdo_clickhouse` 1.2.0; the HTTP side used
`laravel-clickhouse/laravel-clickhouse` 1.1.3 and Guzzle 7.15.1.

| Workload | Native median (p95) | HTTP median (p95) | Lower median |
|---|---:|---:|---:|
| Cold connect + SELECT 1 | 1.905 ms (2.292) | 2.152 ms (2.601) | native 1.13× |
| Reused connection SELECT 1 | 1.130 ms (1.354) | 2.252 ms (2.774) | native 1.99× |
| Indexed point lookup | 2.224 ms (3.048) | 3.200 ms (4.071) | native 1.44× |
| Aggregate 1M rows | 7.701 ms (8.252) | 8.527 ms (9.153) | native 1.11× |
| Fetch 1,000 rows | 4.464 ms (5.021) | 6.000 ms (7.121) | native 1.34× |
| Insert 100 rows | 61.821 ms (62.763) | 61.709 ms (62.518) | tie (<5%) |

An additional run with the client order reversed produced the same overall
direction and comparable medians. The largest native advantage is on small
queries over an already-open connection. The gap narrows as ClickHouse server
work dominates, and the 100-row synchronous insert is effectively tied.

Lower is better. Differences below 5% are labeled as ties.

<!-- BENCHMARK_RESULTS_END -->

## Scope

The timed samples include Laravel connection/query handling, binding, network
transport, response decoding, and result materialization. They exclude
container startup, dependency installation, dataset creation, and warmups.

This is deliberately small. It does not compare TLS, remote network behavior,
parallel-query APIs, Eloquent model hydration, persistent native connections,
the competitor's optional Curl transport, or bulk formats such as RowBinary.
Those need separate workload-specific benchmarks.
