<?php

namespace ClickHouse\Laravel\Query;

/**
 * Common ClickHouse server settings.
 *
 * Not exhaustive — ClickHouse has 600+ settings that change every release.
 * These are the most commonly tuned settings for analytics workloads.
 *
 * @see https://clickhouse.com/docs/en/operations/settings/settings
 */
final class Settings
{
    // Query execution
    const MAX_THREADS = 'max_threads';
    const MAX_MEMORY_USAGE = 'max_memory_usage';
    const MAX_EXECUTION_TIME = 'max_execution_time';
    const MAX_ROWS_TO_READ = 'max_rows_to_read';
    const MAX_BYTES_TO_READ = 'max_bytes_to_read';
    const MAX_RESULT_ROWS = 'max_result_rows';
    const MAX_RESULT_BYTES = 'max_result_bytes';
    const TIMEOUT_OVERFLOW_MODE = 'timeout_overflow_mode';

    // Insert behavior
    const ASYNC_INSERT = 'async_insert';
    const WAIT_FOR_ASYNC_INSERT = 'wait_for_async_insert';
    const MAX_INSERT_BLOCK_SIZE = 'max_insert_block_size';
    const MAX_PARTITIONS_PER_INSERT_BLOCK = 'max_partitions_per_insert_block';
    const INPUT_FORMAT_ALLOW_ERRORS_NUM = 'input_format_allow_errors_num';
    const INPUT_FORMAT_ALLOW_ERRORS_RATIO = 'input_format_allow_errors_ratio';

    // JOIN behavior
    const JOIN_ALGORITHM = 'join_algorithm';
    const MAX_ROWS_IN_JOIN = 'max_rows_in_join';
    const MAX_BYTES_IN_JOIN = 'max_bytes_in_join';
    const JOIN_OVERFLOW_MODE = 'join_overflow_mode';
    const JOIN_USE_NULLS = 'join_use_nulls';

    // Aggregation
    const MAX_ROWS_TO_GROUP_BY = 'max_rows_to_group_by';
    const GROUP_BY_OVERFLOW_MODE = 'group_by_overflow_mode';
    const MAX_BYTES_BEFORE_EXTERNAL_GROUP_BY = 'max_bytes_before_external_group_by';
    const MAX_BYTES_BEFORE_EXTERNAL_SORT = 'max_bytes_before_external_sort';

    // Network / distributed
    const MAX_DISTRIBUTED_CONNECTIONS = 'max_distributed_connections';
    const DISTRIBUTED_PRODUCT_MODE = 'distributed_product_mode';
    const ALLOW_EXPERIMENTAL_PARALLEL_READING_FROM_REPLICAS = 'allow_experimental_parallel_reading_from_replicas';

    // ReplacingMergeTree
    const FINAL = 'final';

    // Compression
    const NETWORK_COMPRESSION_METHOD = 'network_compression_method';
    const NETWORK_ZSTD_COMPRESSION_LEVEL = 'network_zstd_compression_level';

    // Format
    const OUTPUT_FORMAT_JSON_QUOTE_64BIT_INTEGERS = 'output_format_json_quote_64bit_integers';
    const OUTPUT_FORMAT_JSON_QUOTE_DENORMALS = 'output_format_json_quote_denormals';
    const DATE_TIME_OUTPUT_FORMAT = 'date_time_output_format';
}
