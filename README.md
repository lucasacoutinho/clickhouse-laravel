# ClickHouse Laravel

A native ClickHouse database driver for Laravel. Uses the native TCP protocol (port 9000) via [ext-pdo_clickhouse](https://github.com/lightprofco/ext-clickhouse-pdo) — no cURL, no HTTP overhead.

## Requirements

- PHP 8.1+
- Laravel 10, 11, 12, or 13
- [ext-pdo_clickhouse](https://github.com/lightprofco/ext-clickhouse-pdo) PHP extension

## Installation

```bash
composer require clickhouse/laravel
```

The service provider is auto-discovered. No manual registration needed.

## Configuration

Add a `clickhouse` connection to your `config/database.php`:

```php
'connections' => [
    // ...

    'clickhouse' => [
        'driver'      => 'clickhouse',
        'host'        => env('CLICKHOUSE_HOST', 'localhost'),
        'port'        => env('CLICKHOUSE_PORT', 9000),
        'database'    => env('CLICKHOUSE_DATABASE', 'default'),
        'username'    => env('CLICKHOUSE_USERNAME', 'default'),
        'password'    => env('CLICKHOUSE_PASSWORD', ''),
        'compression' => env('CLICKHOUSE_COMPRESSION'),  // 'lz4', 'zstd', or null
        'timeout'     => env('CLICKHOUSE_TIMEOUT', 5),
        'retries'     => env('CLICKHOUSE_RETRIES', 0),
        'settings'    => [
            // 'max_partitions_per_insert_block' => 300,
        ],
        'options' => [
            'final' => false,  // Auto-apply FINAL to all SELECT queries
        ],

        // Cluster: multi-host failover reads + distributed writes
        // 'cluster' => [
        //     ['host' => 'clickhouse01', 'port' => 9000],
        //     ['host' => 'clickhouse02', 'port' => 9000],
        // ],
    ],
],
```

## Usage

### Query Builder

All standard Laravel query builder methods work. Plus ClickHouse-specific extensions:

```php
use Illuminate\Support\Facades\DB;

$rows = DB::connection('clickhouse')
    ->table('events')
    ->select('user_id', 'path')
    ->selectRaw('count(*) as views')
    ->final()                                    // ReplacingMergeTree dedup
    ->sample(0.1)                                // 10% sample
    ->preWhere('date', '>=', '2026-01-01')       // Fast pre-filter
    ->where('site_id', 42)
    ->arrayJoin('tags', 'tag')                   // Explode array column
    ->groupBy('user_id', 'path')
    ->limitBy(3, 'user_id')                      // Top 3 per user
    ->orderBy('views', 'desc')
    ->limit(1000)
    ->format('JSONEachRow')                      // Output format
    ->settings(['max_threads' => 8])             // Per-query settings
    ->get();
```

### PREWHERE

Filters rows before reading all columns — significantly faster than WHERE for selective filters on wide tables:

```php
DB::connection('clickhouse')
    ->table('events')
    ->preWhere('date', '>=', '2026-01-01')
    ->preWhereIn('status', [1, 2, 3])
    ->preWhereBetween('score', [0.5, 1.0])
    ->preWhereRaw('toHour(ts) BETWEEN 9 AND 17')
    ->where('user_id', 42)
    ->get();
```

### WITH FILL (Time Series)

Fill gaps in time series data:

```php
// Convenience method for DateTime ranges
DB::connection('clickhouse')
    ->table('metrics')
    ->selectRaw("toStartOfMinute(ts) AS bucket, count() AS cnt")
    ->groupByRaw('bucket')
    ->orderBy('bucket')
    ->withFillTime('2026-01-01', '2026-01-02', '5 minute', precision: 3)
    ->interpolate('cumulative')
    ->get();

// Raw expression for full control
->orderBy('bucket')
->withFillRaw("FROM toDateTime64('2026-01-01', 3) STEP toIntervalMillisecond(100)")

// Structured with DB::raw()
->orderBy('bucket')
->withFill(
    from: DB::raw("toDateTime64('2026-01-01', 3)"),
    to: DB::raw("toDateTime64('2026-01-02', 3)"),
    step: DB::raw("toIntervalMinute(5)"),
)
->interpolate('cumulative')
```

### ClickHouse JOINs

Native ANY/ALL strictness and GLOBAL distribution:

```php
// USING columns
->anyLeftJoin('users', 'user_id')
->allInnerJoin('dim', ['key1', 'key2'], global: true)

// ON conditions
->anyLeftJoin('users', on: [['orders.user_id', '=', 'users.id']])

// Join against subquery
$sub = DB::connection('clickhouse')->table('users')->where('active', 1);
->anyLeftJoin($sub, 'user_id', alias: 'u')
```

### Async Inserts

```php
DB::connection('clickhouse')
    ->table('events')
    ->async()           // fire and forget
    ->insert($rows);

// Or wait for flush
->async(wait: true)->insert($rows);
```

### Chunked Inserts

```php
DB::connection('clickhouse')
    ->table('events')
    ->insertChunked($millionRows, 50000);  // 50k per batch
```

### ON CLUSTER

Distributed DDL and mutations:

```php
// Query mutations
DB::connection('clickhouse')
    ->table('events')
    ->onCluster('production')
    ->where('date', '<', '2025-01-01')
    ->delete();

// Schema DDL
Schema::connection('clickhouse')->create('events', function ($table) {
    $table->onCluster('production');
    $table->engine('ReplicatedMergeTree()');
    // ...
});
```

### Settings Constants

```php
use ClickHouse\Laravel\Query\Settings;

->settings([
    Settings::MAX_THREADS => 8,
    Settings::ASYNC_INSERT => 1,
    Settings::JOIN_ALGORITHM => 'hash',
    Settings::MAX_MEMORY_USAGE => 10_000_000_000,
])
```

### Remote Tables

```php
DB::connection('clickhouse')
    ->query()
    ->fromRemote('ch-replica:9000', 'analytics', 'events')
    ->where('date', '>=', '2026-01-01')
    ->get();

// Merge table function
->fromMerge('analytics', '^events_20.*')
```

## Eloquent Models

```php
use ClickHouse\Laravel\Eloquent\ClickHouseModel;

class Event extends ClickHouseModel
{
    protected $table = 'events';
    protected $casts = ['user_id' => 'integer'];
}
```

The base model sets: `$connection = 'clickhouse'`, `$incrementing = false`, `$timestamps = false`, `$keyType = 'string'`, `$guarded = []`.

### Timestamps

```php
use ClickHouse\Laravel\Concerns\HasClickHouseTimestamps;

class User extends ClickHouseModel
{
    use HasClickHouseTimestamps;
}
```

Sets `created_at` and `updated_at` on create. Works with `ReplacingMergeTree(updated_at)`.

## Schema Builder

```php
Schema::connection('clickhouse')->create('events', function ($table) {
    $table->uint64('id')->codec('ZSTD(3)');
    $table->string('name');
    $table->float64('value')->codec('Delta, ZSTD');
    $table->dateTime('ts')->codec('DoubleDelta, LZ4');
    $table->arrayOf('tags', 'String');
    $table->mapOf('metadata', 'String', 'String');
    $table->lowCardinality('country');
    $table->clickhouseType('coords', 'Tuple(Float64, Float64)');

    $table->engine('ReplicatedMergeTree()');
    $table->orderBy('id');
    $table->partitionBy('toYYYYMM(ts)');
    $table->primaryKey('id');
    $table->setting('index_granularity', 8192);
    $table->onCluster('production');
});
```

### Column Types

| Method | ClickHouse Type |
|--------|----------------|
| `uint8`, `uint16`, `uint32`, `uint64` | UInt8, UInt16, UInt32, UInt64 |
| `int8`, `int16`, `int32`, `int64` | Int8, Int16, Int32, Int64 |
| `float32`, `float64` | Float32, Float64 |
| `string`, `text`, `longText` | String |
| `boolean` | UInt8 |
| `date`, `dateTime`, `dateTime64` | Date, DateTime, DateTime64 |
| `uuid` | UUID |
| `ipv4`, `ipv6` | IPv4, IPv6 |
| `decimal` | Decimal(p, s) |
| `enum` | Enum8 |
| `fixedString` | FixedString(N) |
| `int128`, `uint128` | Int128, UInt128 |
| `arrayOf`, `mapOf`, `tupleOf` | Array(T), Map(K,V), Tuple(T...) |
| `lowCardinality` | LowCardinality(T) |
| `clickhouseType` | Any raw type string |

### Compression Codecs

```php
$table->uint64('id')->codec('ZSTD(3)');
$table->float64('value')->codec('Delta, ZSTD');
$table->dateTime('ts')->codec('DoubleDelta, LZ4');
```

## Transactions

ClickHouse has no transactions. `DB::transaction()` runs the callback directly — safe to use in code shared between MySQL and ClickHouse:

```php
DB::connection('clickhouse')->transaction(function ($conn) {
    // Runs directly, no BEGIN/COMMIT
});
```

## Cluster Support

Configure multiple nodes for failover reads and distributed writes:

```php
'clickhouse' => [
    'driver' => 'clickhouse',
    'cluster' => [
        ['host' => 'clickhouse01', 'port' => 9000],
        ['host' => 'clickhouse02', 'port' => 9000],
    ],
    'database' => 'default',
    'username' => 'default',
    'password' => '',
],
```

- **Reads**: Failover — tries nodes sequentially, slides to next on failure
- **Writes**: Distributed — executes on ALL nodes

## Compression

Protocol-level compression for data in transit (requires ext-pdo_clickhouse with compression support):

```php
'clickhouse' => [
    'compression' => 'lz4',  // 'lz4', 'zstd', or null (no compression)
    // ...
],
```

## Testing

```bash
# Unit tests (no ClickHouse needed)
composer test:unit

# All tests (requires ClickHouse on localhost:9000)
composer test
```

## License

MIT
