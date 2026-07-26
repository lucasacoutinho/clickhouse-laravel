#!/usr/bin/env bash

set -euo pipefail

benchmark_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repository_dir="$(cd "${benchmark_dir}/.." && pwd)"
compose_file="${benchmark_dir}/compose.yaml"
benchmark_project="clickhouse-laravel-bench-$$"
temporary_results=0

if [[ -n "${BENCHMARK_RESULTS_DIR:-}" ]]; then
    results_dir="${BENCHMARK_RESULTS_DIR}"
    mkdir -p "${results_dir}"
else
    results_dir="$(mktemp -d /tmp/clickhouse-laravel-benchmark.XXXXXX)"
    temporary_results=1
fi

cleanup() {
    docker compose \
        --project-name "${benchmark_project}" \
        --file "${compose_file}" \
        down --volumes --remove-orphans >/dev/null 2>&1 || true

    if [[ "${temporary_results}" -eq 1 && -d "${results_dir}" ]]; then
        rm -rf -- "${results_dir}"
    fi
}

trap cleanup EXIT INT TERM

native_source_ref="$(git -C "${repository_dir}" rev-parse --short=12 HEAD 2>/dev/null || echo working-tree)"

if ! git -C "${repository_dir}" diff --quiet -- composer.json src; then
    native_source_ref="${native_source_ref}-dirty"
fi

export BENCHMARK_RESULTS_DIR="${results_dir}"
export NATIVE_SOURCE_REF="${native_source_ref}"

read -r -a benchmark_stacks <<< "${BENCHMARK_STACK_ORDER:-http native}"

if [[ "${#benchmark_stacks[@]}" -ne 2 ]] \
    || [[ " ${benchmark_stacks[*]} " != *" native "* ]] \
    || [[ " ${benchmark_stacks[*]} " != *" http "* ]]; then
    echo "BENCHMARK_STACK_ORDER must contain 'native' and 'http' exactly once." >&2
    exit 2
fi

docker compose \
    --project-name "${benchmark_project}" \
    --file "${compose_file}" \
    up --detach --wait clickhouse

docker compose \
    --project-name "${benchmark_project}" \
    --file "${compose_file}" \
    exec --no-TTY clickhouse \
    clickhouse-client --multiquery < "${benchmark_dir}/setup.sql"

docker compose \
    --project-name "${benchmark_project}" \
    --file "${compose_file}" \
    build benchmark

for stack in "${benchmark_stacks[@]}"; do
    docker compose \
        --project-name "${benchmark_project}" \
        --file "${compose_file}" \
        run --rm --no-TTY benchmark \
        php /benchmark/benchmark.php "${stack}" "/results/${stack}.json"
done

docker compose \
    --project-name "${benchmark_project}" \
    --file "${compose_file}" \
    run --rm --no-TTY benchmark \
    php /benchmark/report.php /results/native.json /results/http.json
