# Performance comparison

Two benchmarks live here:

- `run-drivers.sh` compares PHP ClickHouse drivers directly, without a framework.
- `run.sh` compares this package with `laravel-clickhouse/laravel-clickhouse`
  1.1.3 and its default Guzzle HTTP transport.

Both use one shared client image, so the PHP build, operating system, CPU
allocation, and Docker network are identical. Both use the same ClickHouse
26.6.2.81 container and one-million-row dataset, and warm each workload before
its measured samples.

The server runs with `async_insert=0` (`clickhouse-users.xml`). ClickHouse 26.6
enables async inserts by default, and the server then holds every client insert
for at least 50 ms. That wait is identical for every driver and hides their
cost, so it made every insert look like a 60 ms tie.

## Driver comparison

```bash
BENCHMARK_RESULTS_DIR="$PWD/benchmark-results/drivers" ./benchmarks/run-drivers.sh
```

Set `BENCHMARK_ROUNDS` (default 3) to change the rounds per driver. The order of
the drivers rotates each round. The run fails if `system.query_log` does not
show every inserted row. To test extensions that are not published yet, set
`BENCHMARK_BASE_IMAGE` to a local image.

Inserts use each driver's own path: columnar blocks for `ext-clickhouse`,
`insert()` for `one-ck` and `smi2/phpclickhouse`, and a multi-row `VALUES`
statement for both PDO drivers. All drivers run with compression off, and every
row is fully materialized as a PHP array.

<!-- DRIVER_RESULTS_START -->

This run was captured on October 31, 2026, using an AMD Ryzen 9 9900X under WSL2
with PHP 8.5.12 and five rounds per driver. `ext-clickhouse` and
`pdo_clickhouse` were local 1.6.1 builds. The machine was running other
containers. Values are the median of the per-round medians in milliseconds, and
the multiple is relative to `ext-clickhouse`.

| Workload | `ext-clickhouse` 1.6.1 | `pdo_clickhouse` 1.6.1 | `one-ck` 0.2.9 | `smi2/phpclickhouse` 1.26.925 | `pdo_mysql` (port 9004) |
|---|---:|---:|---:|---:|---:|
| Cold connect + SELECT 1 | 2.853 | 2.877 (1.01×) | 1.969 (0.69×) | 2.582 (0.90×) | 1.646 (0.58×) |
| Reused SELECT 1 | 1.139 | 1.094 (0.96×) | 0.966 (0.85×) | 2.619 (2.30×) | 0.683 (0.60×) |
| Indexed point lookup | 2.250 | 2.019 (0.90×) | 2.106 (0.94×) | 3.556 (1.58×) | 1.503 (0.67×) |
| Aggregate 1M rows | 7.945 | 7.207 (0.91×) | 7.278 (0.92×) | 8.207 (1.03×) | 6.814 (0.86×) |
| Fetch 1,000 rows | 4.366 | 3.849 (0.88×) | 3.760 (0.86×) | 6.423 (1.47×) | 4.083 (0.94×) |
| Fetch 100,000 rows | 33.649 | 24.179 (0.72×) | 66.952 (1.99×) | 124.9 (3.71×) | 54.552 (1.62×) |
| Insert 100 rows | 1.589 | 1.312 (0.83×) | 1.423 (0.90×) | 2.561 (1.61×) | 1.077 (0.68×) |
| Insert 10,000 rows | 7.354 | 5.408 (0.74×) | 6.315 (0.86×) | 15.509 (2.11×) | 4.301 (0.58×) |
| Peak process RSS | 93.7 MiB | 91.4 MiB | 83.8 MiB | 163.5 MiB | 89.8 MiB |

Median spread between rounds was 27% of the median, and the largest was 62%. Do
not read small differences as real. The gaps that held in every round were:

- Fetching 100,000 rows, where both native extensions beat every other driver.
- `smi2/phpclickhouse` trailing on every workload except the aggregate and cold
  connect. It also exceeds PHP's default 128 MB limit when fetching 100,000
  rows, so the driver benchmark sets `memory_limit=-1`.
- `pdo_mysql` leading reused `SELECT 1`, and beating both native extensions on
  cold connect.

<!-- DRIVER_RESULTS_END -->

## Laravel comparison

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

This run was captured on October 31, 2026, using an AMD Ryzen 9 9900X under WSL2.
The clients used PHP 8.5.12 and Laravel 13.39.0. The native side used local
`clickhouse` and `pdo_clickhouse` 1.6.1 builds; the HTTP side used
`laravel-clickhouse/laravel-clickhouse` 1.1.3. Each cell shows the native-first
and HTTP-first medians, so the difference between them is a guide to the noise.

| Workload | Native median (ms) | HTTP median (ms) |
|---|---:|---:|
| Cold connect + SELECT 1 | 2.652 / 2.800 | 2.683 / 2.961 |
| Reused connection SELECT 1 | 1.153 / 1.114 | 2.457 / 2.575 |
| Indexed point lookup | 2.445 / 2.236 | 3.383 / 4.052 |
| Aggregate 1M rows | 7.605 / 7.030 | 8.045 / 8.685 |
| Fetch 1,000 rows | 4.449 / 4.212 | 5.639 / 7.410 |
| Insert 100 rows | 3.614 / 3.508 | 2.281 / 2.802 |

Native leads on small queries over an open connection. With async inserts off,
the HTTP package leads the 100-row Query Builder insert in both orders. Earlier
published results showed that insert as a tie because of the async insert wait.

Lower is better.

<!-- BENCHMARK_RESULTS_END -->

## Scope

The timed samples include Laravel connection/query handling, binding, network
transport, response decoding, and result materialization. They exclude
container startup, dependency installation, dataset creation, and warmups.

This is deliberately small. It does not compare TLS, remote network behavior,
parallel-query APIs, Eloquent model hydration, persistent native connections,
the competitor's optional Curl transport, or bulk formats such as RowBinary.
Those need separate workload-specific benchmarks.
