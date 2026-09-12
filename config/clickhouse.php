<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Connection registration
    |--------------------------------------------------------------------------
    |
    | The package registers this connection only when an entry with the same
    | name is not already present in database.connections.
    |
    */
    'connection' => env('CLICKHOUSE_CONNECTION', 'clickhouse'),
    'register_connection' => true,

    'driver' => 'clickhouse',
    'host' => env('CLICKHOUSE_HOST', '127.0.0.1'),
    'port' => env('CLICKHOUSE_PORT', 9000),
    'database' => env('CLICKHOUSE_DATABASE', 'default'),
    'username' => env('CLICKHOUSE_USERNAME', 'default'),
    'password' => env('CLICKHOUSE_PASSWORD', ''),

    'compression' => env('CLICKHOUSE_COMPRESSION'),
    'timeout' => env('CLICKHOUSE_TIMEOUT', 5),
    'persistent' => env('CLICKHOUSE_PERSISTENT', false),

    'max_buffered_rows' => env('CLICKHOUSE_MAX_BUFFERED_ROWS'),
    'max_buffered_bytes' => env('CLICKHOUSE_MAX_BUFFERED_BYTES'),

    'ssl' => env('CLICKHOUSE_SSL', false),
    'ssl_skip_verify' => env('CLICKHOUSE_SSL_SKIP_VERIFY', false),
    'ssl_ca_path' => env('CLICKHOUSE_SSL_CA_PATH'),
    'ssl_ca_file' => env('CLICKHOUSE_SSL_CA_FILE'),
    'ssl_client_cert' => env('CLICKHOUSE_SSL_CLIENT_CERT'),
    'ssl_client_key' => env('CLICKHOUSE_SSL_CLIENT_KEY'),

    'retries' => env('CLICKHOUSE_RETRIES', 0),
    'retry_backoff_ms' => env('CLICKHOUSE_RETRY_BACKOFF_MS', 100),
    'retry_writes' => env('CLICKHOUSE_RETRY_WRITES', false),
    'use_lightweight_delete' => env('CLICKHOUSE_LIGHTWEIGHT_DELETES', false),

    /*
     * "throw" protects callers from assuming rollback semantics exist.
     * "passthrough" explicitly runs transaction callbacks without a transaction.
     */
    'transactions' => env('CLICKHOUSE_TRANSACTIONS', 'throw'),

    'settings' => [],

    'query' => [
        'final' => false,
    ],

    /*
     * Configure multiple native endpoints for connection failover. Writes use
     * the active endpoint once; replication belongs in ClickHouse table engines.
     */
    'cluster' => null,

    /*
     * Numeric PDO attributes may be supplied here. String keys are reserved for
     * package configuration and are ignored by the connector.
     */
    'options' => [],
];
