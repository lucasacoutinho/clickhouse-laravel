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
`pdo_clickhouse` were local builds of 1.6.1 plus the connect and materialization
changes. The machine was under heavy load from other containers. Values are the
median of the per-round medians in milliseconds, and the multiple is relative to
`ext-clickhouse`.

| Workload | `ext-clickhouse` | `pdo_clickhouse` | `one-ck` 0.2.9 | `smi2/phpclickhouse` 1.26.925 | `pdo_mysql` (port 9004) |
|---|---:|---:|---:|---:|---:|
| Cold connect + SELECT 1 | 3.057 | 3.175 (1.04×) | 3.212 (1.05×) | 4.351 (1.42×) | 2.221 (0.73×) |
| Reused SELECT 1 | 1.695 | 1.872 (1.10×) | 1.421 (0.84×) | 3.864 (2.28×) | 0.865 (0.51×) |
| Indexed point lookup | 3.191 | 3.491 (1.09×) | 2.985 (0.94×) | 6.577 (2.06×) | 2.108 (0.66×) |
| Aggregate 1M rows | 11.065 | 11.847 (1.07×) | 12.130 (1.10×) | 14.236 (1.29×) | 10.282 (0.93×) |
| Fetch 1,000 rows | 5.845 | 5.972 (1.02×) | 5.962 (1.02×) | 10.186 (1.74×) | 6.190 (1.06×) |
| Fetch 100,000 rows | 26.135 | 30.223 (1.16×) | 106.7 (4.08×) | 164.4 (6.29×) | 88.093 (3.37×) |
| Insert 100 rows | 1.737 | 1.881 (1.08×) | 2.150 (1.24×) | 3.691 (2.12×) | 1.704 (0.98×) |
| Insert 10,000 rows | 7.610 | 7.858 (1.03×) | 13.132 (1.73×) | 24.534 (3.22×) | 6.820 (0.90×) |
| Peak process RSS | 80.7 MiB | 91.5 MiB | 83.7 MiB | 163.1 MiB | 89.5 MiB |

Median spread between rounds was 57% of the median, so do not read small
differences as real, and do not compare these absolute times with earlier runs.

`pdo_mysql` still leads reused `SELECT 1` and point lookups. About 85% of a reused
native `SELECT 1` is spent waiting on the server, which writes several packets per
query where the MySQL handler writes one. A client cannot remove that wait.

The server also sends a `ProfileEvents` block that these drivers discard. Setting
`send_profile_events=0` in a server profile saved about 7% on reused `SELECT 1` in
an interleaved test. Drivers cannot default it per query, because the server
rejects that setting for `readonly` users.

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

This run was captured on October 31, 2026, using an AMD Ryzen 9 9900X under WSL2
and heavy load from other containers. The clients used PHP 8.5.12 and Laravel
13.39.0. The native side used this package with literal inserts and local builds
of 1.6.1 plus the connect and materialization changes; the HTTP side used
`laravel-clickhouse/laravel-clickhouse` 1.1.3. Each cell shows the native-first
and HTTP-first medians, so the difference between them is a guide to the noise.

| Workload | Native median (ms) | HTTP median (ms) |
|---|---:|---:|
| Cold connect + SELECT 1 | 3.646 / 3.317 | 5.707 / 5.510 |
| Reused connection SELECT 1 | 1.963 / 2.040 | 7.817 / 6.314 |
| Indexed point lookup | 4.356 / 4.958 | 15.378 / 10.222 |
| Aggregate 1M rows | 20.537 / 19.492 | 30.640 / 21.147 |
| Fetch 1,000 rows | 9.281 / 9.426 | 16.930 / 16.256 |
| Insert 100 rows | 3.154 / 2.684 | 9.548 / 6.622 |

Native leads every workload in both orders. Earlier published results showed the
insert as a tie because of the async insert wait.

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
