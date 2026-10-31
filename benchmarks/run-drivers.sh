#!/usr/bin/env bash

set -euo pipefail

benchmark_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
compose_file="${benchmark_dir}/compose.yaml"
benchmark_project="clickhouse-drivers-bench-$$"
rounds="${BENCHMARK_ROUNDS:-3}"
temporary_results=0

if [[ -n "${BENCHMARK_RESULTS_DIR:-}" ]]; then
    results_dir="${BENCHMARK_RESULTS_DIR}"
    mkdir -p "${results_dir}"
else
    results_dir="$(mktemp -d /tmp/clickhouse-drivers-benchmark.XXXXXX)"
    temporary_results=1
fi

compose() {
    docker compose --project-name "${benchmark_project}" --file "${compose_file}" "$@"
}

cleanup() {
    compose down --volumes --remove-orphans >/dev/null 2>&1 || true

    if [[ "${temporary_results}" -eq 1 && -d "${results_dir}" ]]; then
        rm -rf -- "${results_dir}"
    fi
}

trap cleanup EXIT INT TERM

export BENCHMARK_RESULTS_DIR="${results_dir}"

read -r -a drivers <<< "${BENCHMARK_DRIVERS:-ext-clickhouse pdo-clickhouse one-ck smi2 pdo-mysql}"

compose up --detach --wait clickhouse
compose exec --no-TTY clickhouse clickhouse-client --multiquery < "${benchmark_dir}/setup.sql"
compose build benchmark

# Rotate the order each round so no driver always runs first after the warm cache.
for ((round = 1; round <= rounds; round++)); do
    for ((index = 0; index < ${#drivers[@]}; index++)); do
        driver="${drivers[$(((index + round - 1) % ${#drivers[@]}))]}"
        compose run --rm --no-TTY benchmark \
            php -d memory_limit=-1 /benchmark/drivers.php "${driver}" "/results/driver-${driver}-${round}.json"
    done
done

# A driver that silently drops rows must not pass as a fast driver. Each run sends
# 23 inserts of 100 rows and 17 inserts of 10,000 rows.
expected_rows=$((rounds * ${#drivers[@]} * (23 * 100 + 17 * 10000)))
actual_rows="$(compose exec --no-TTY clickhouse clickhouse-client --multiquery --query "
    SYSTEM FLUSH LOGS;
    SELECT sum(written_rows) FROM system.query_log
    WHERE type = 'QueryFinish' AND query_kind = 'Insert' AND has(tables, 'default.benchmark_sink')")"

if [[ "${actual_rows}" != "${expected_rows}" ]]; then
    echo "Inserted ${actual_rows} rows; expected ${expected_rows}." >&2
    exit 1
fi

compose run --rm --no-TTY --entrypoint sh benchmark \
    -c 'php /benchmark/drivers-report.php /results/driver-*.json'
