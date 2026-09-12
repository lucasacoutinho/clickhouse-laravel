<div align="center">
  <h1>ClickHouse Laravel</h1>
  <p>
    A Laravel database driver for ClickHouse over native TCP, powered by
    <a href="https://github.com/lucasacoutinho/ext-clickhouse-pdo">pdo_clickhouse</a>.
  </p>
  <p>
    <a href="https://github.com/lucasacoutinho/clickhouse-laravel/actions/workflows/tests.yml"><img alt="Build status" src="https://img.shields.io/github/actions/workflow/status/lucasacoutinho/clickhouse-laravel/tests.yml?branch=main&style=for-the-badge&labelColor=000000"></a>
    <a href="https://packagist.org/packages/lucasacoutinho/clickhouse-laravel"><img alt="Packagist version" src="https://img.shields.io/packagist/v/lucasacoutinho/clickhouse-laravel?style=for-the-badge&labelColor=000000"></a>
    <a href="#requirements"><img alt="PHP 8.2 or newer" src="https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=for-the-badge&logo=php&logoColor=white&labelColor=000000"></a>
    <a href="https://github.com/lucasacoutinho/clickhouse-laravel/blob/main/LICENSE"><img alt="MIT license" src="https://img.shields.io/github/license/lucasacoutinho/clickhouse-laravel?style=for-the-badge&labelColor=000000"></a>
  </p>
</div>

## Getting started

Install the matching v1.4 native extensions with
[PIE](https://github.com/php/pie):

```bash
pie install "lucasacoutinho/ext-clickhouse:~1.5.0"
pie install "lucasacoutinho/ext-clickhouse-pdo:~1.5.0"
```

Install the Laravel package:

```bash
composer require lucasacoutinho/clickhouse-laravel
```

Laravel discovers `ClickHouseServiceProvider` automatically. Publish the
package configuration:

```bash
php artisan vendor:publish --tag=clickhouse-config
```

The package registers the configured `clickhouse` connection when the
application has not already defined a connection with that name. An explicit
entry in `config/database.php` takes precedence.

Laravel's query builder, Eloquent, query logging, bindings, and schema builder
all use the native driver. The package does not add an HTTP or cURL transport.

## Requirements

| Component | Supported version |
| --- | --- |
| PHP | 8.2 or newer |
| Laravel | 12 or 13 |
| `ext-clickhouse` | 1.5.0 or newer within 1.5.x |
| `ext-pdo_clickhouse` | 1.5.0 or newer within 1.5.x |
| ClickHouse | 26.3 or newer recommended |

The two native extensions share C++ types, so their minor release lines must
match. CI tests the exact v1.5.0 releases against ClickHouse 26.3, 26.6, and
26.8.

The default native TCP port is `9000`. TLS endpoints, including ClickHouse
Cloud, commonly use port `9440`.

## Configuration

The published `config/clickhouse.php` reads these environment variables:

```dotenv
CLICKHOUSE_CONNECTION=clickhouse
CLICKHOUSE_HOST=127.0.0.1
CLICKHOUSE_PORT=9000
CLICKHOUSE_DATABASE=default
CLICKHOUSE_USERNAME=default
CLICKHOUSE_PASSWORD=
CLICKHOUSE_COMPRESSION=lz4
CLICKHOUSE_TIMEOUT=5
CLICKHOUSE_PERSISTENT=false
# Optional; omit both to use the native driver's defaults.
# CLICKHOUSE_MAX_BUFFERED_ROWS=
# CLICKHOUSE_MAX_BUFFERED_BYTES=

CLICKHOUSE_SSL=false
CLICKHOUSE_SSL_SKIP_VERIFY=false
CLICKHOUSE_SSL_CA_PATH=
CLICKHOUSE_SSL_CA_FILE=
CLICKHOUSE_SSL_CLIENT_CERT=
CLICKHOUSE_SSL_CLIENT_KEY=

CLICKHOUSE_RETRIES=0
CLICKHOUSE_RETRY_BACKOFF_MS=100
CLICKHOUSE_RETRY_WRITES=false
CLICKHOUSE_LIGHTWEIGHT_DELETES=false
CLICKHOUSE_TRANSACTIONS=throw
```

`compression` accepts `lz4`, `zstd`, `none`, or a blank value. Numeric PDO
attributes can be supplied through `options`; the connector always enables
exception mode.

SELECT results are buffered by the native PDO driver before Laravel fetches
them. Set `max_buffered_rows` and `max_buffered_bytes` in the connection
configuration to reject results that exceed the configured row count or
serialized result byte budget:

```php
'max_buffered_rows' => 1_000_000,
'max_buffered_bytes' => 67_108_864,
```

Both values must be positive integers. Leaving them unset delegates to the
native driver's defaults of 1,000,000 rows and 64 MiB. These limits apply to
the buffered result and are separate from pagination; the byte limit is not a
hard ceiling on the PHP process's total resident memory. The driver raises an
error when a result exceeds either configured limit.

### TLS and mutual TLS

```php
'ssl' => true,
'ssl_ca_file' => '/run/secrets/clickhouse-ca.pem',
'ssl_client_cert' => '/run/secrets/client.crt',
'ssl_client_key' => '/run/secrets/client.key',
```

Providing a CA file/path, client credentials, or `ssl_skip_verify=true` enables
TLS automatically. Client certificate and key must be configured together.
Avoid `ssl_skip_verify` outside local development.

### Connection settings

Server settings are applied once to every native PDO connection:

```php
'settings' => [
    'max_threads' => 4,
    'max_memory_usage' => 10_000_000_000,
],

'query' => [
    'final' => false,
],
```

## Query builder

Standard Laravel query-builder methods remain available. ClickHouse-specific
clauses preserve Laravel bindings in the same order as the generated SQL:

```php
use Illuminate\Support\Facades\DB;

$rows = DB::connection('clickhouse')
    ->table('events', final: true)
    ->select('user_id', 'path')
    ->selectRaw('count() AS views')
    ->sample(0.1, 0.5)
    ->preWhere('occurred_on', '>=', '2026-01-01')
    ->where('site_id', 42)
    ->arrayJoin('tags', 'tag')
    ->groupBy('user_id', 'path')
    ->orderBy('views', 'desc')
    ->limitBy(3, ['user_id', 'path'])
    ->limit(1000)
    ->settings('max_threads', 8)
    ->get();
```

Supported extensions include:

- `final()` and `table('events', final: true)`
- fractional or absolute `sample()` values, with optional fractional offsets
- `preWhere()`, raw, `IN`, `BETWEEN`, and null predicate variants
- `arrayJoin()` and `leftArrayJoin()`
- `limitBy()` with variadic columns or an array
- ClickHouse `ANY` / `ALL` and `GLOBAL` joins
- scalar and subquery CTEs, including multiple and recursive CTEs
- `GLOBAL IN`, `GLOBAL NOT IN`, `empty()`, and `not empty()` predicates
- explicit `UNION`, `INTERSECT`, and `EXCEPT` `ALL`/`DISTINCT` operations
- `withFill()`, `withFillTime()`, and `interpolate()`
- `fromRemote()` and `fromMerge()`
- `format()`
- per-query `settings()` as an array or key/value pair
- `onCluster()` for mutations

Identifiers, settings, format names, and convenience expressions are validated
or quoted. Methods ending in `Raw`, Laravel `DB::raw()`, and schema expressions
are explicit trust boundaries; never concatenate untrusted input into them.

### PREWHERE

```php
DB::connection('clickhouse')
    ->table('events')
    ->preWhere('occurred_on', '>=', '2026-01-01')
    ->preWhereIn('status', [1, 2, 3])
    ->preWhereBetween('score', [0.5, 1.0])
    ->preWhereNotNull('user_id')
    ->orPreWhereRaw('is_test = ?', [0])
    ->where('user_id', 42)
    ->get();
```

The complete predicate family includes `orPreWhere()`, `preWhereRaw()`,
`orPreWhereRaw()`, `preWhereNotIn()`, `preWhereNotBetween()`,
`preWhereNull()`, and `preWhereNotNull()`. Raw expressions are trusted SQL;
use bindings for their values. `PREWHERE` is intentionally unavailable on
UPDATE/DELETE mutations.

### ClickHouse joins

```php
// USING
$rows = DB::connection('clickhouse')
    ->table('events')
    ->anyLeftJoin('users', 'user_id')
    ->get();

// ON
$rows = DB::connection('clickhouse')
    ->table('events')
    ->anyLeftJoin(
        'users',
        on: [['events.user_id', '=', 'users.id']],
    )
    ->get();

// A subquery requires an explicit alias.
$activeUsers = DB::connection('clickhouse')
    ->table('users')
    ->select('id')
    ->where('active', true);

$rows = DB::connection('clickhouse')
    ->table('events')
    ->anyInnerJoin($activeUsers, 'id', alias: 'active_users')
    ->get();
```

### CTEs, predicates, and set operations

Scalar expressions are bound as values. Query builders and closures compile as
subquery CTEs:

```php
$activeUsers = DB::connection('clickhouse')
    ->table('users')
    ->select('id')
    ->where('active', true);

$rows = DB::connection('clickhouse')
    ->table('events')
    ->withQuery(100, 'minimum_score')
    ->withQuerySub($activeUsers, 'active_users')
    ->whereRaw('score >= minimum_score')
    ->whereGlobalIn(
        'user_id',
        DB::connection('clickhouse')->table('active_users')->select('id'),
    )
    ->whereNotEmpty('tags')
    ->get();
```

`withQuery()` accepts a bound scalar or a query builder.
`withQueryRaw()` is the explicit trusted-SQL form, and
`withQueryRecursive()` / `withRecursiveQuery()` compile recursive subquery
CTEs. Multiple calls accumulate instead of replacing an earlier CTE.

The predicate families include `whereGlobalIn()`, `whereGlobalNotIn()`, their
`orWhere...` variants, `whereEmpty()`, `whereNotEmpty()`, `havingEmpty()`, and
their OR / NOT variants. Empty `GLOBAL IN` and `GLOBAL NOT IN` sets compile to
false and true predicates respectively.

Set operations use explicit ClickHouse semantics:

```php
$current = DB::connection('clickhouse')->table('events')->select('user_id');
$archive = DB::connection('clickhouse')->table('events_archive')->select('user_id');

$users = $current
    ->unionDistinct($archive)
    ->intersect($allowedUsers) // INTERSECT DISTINCT
    ->exceptAll($blockedUsers)
    ->orderBy('user_id')
    ->get();
```

Available methods are `unionDistinct()`, `intersect()`, `intersectAll()`,
`intersectDistinct()`, `except()`, `exceptAll()`, and `exceptDistinct()`.
Laravel's `union()` compiles as `UNION DISTINCT` so behavior does not depend on
the server's `union_default_mode` setting.

### Time-series gap filling

```php
DB::connection('clickhouse')
    ->table('metrics')
    ->selectRaw('toStartOfMinute(ts) AS bucket, count() AS count')
    ->groupByRaw('bucket')
    ->orderBy('bucket')
    ->withFillTime(
        '2026-01-01 00:00:00',
        '2026-01-02 00:00:00',
        '5 minute',
        precision: 3,
    )
    ->interpolate('count')
    ->get();
```

For expressions, make the trust boundary explicit:

```php
->withFill(
    from: DB::raw("toDateTime64('2026-01-01', 3)"),
    to: DB::raw("toDateTime64('2026-01-02', 3)"),
    step: DB::raw('toIntervalMinute(5)'),
)
```

### Inserts and native values

```php
use ClickHouse\Laravel\Support\ClickHouseValue;

DB::connection('clickhouse')->table('events')->insert([
    [
        'id' => 1,
        'tags' => ClickHouseValue::array(['web', 'paid']),
        'attributes' => ClickHouseValue::map(['country' => 'BR']),
        'coordinates' => ClickHouseValue::tuple(-23.55, -46.63),
        'payload' => ClickHouseValue::json(['source' => 'landing-page']),
    ],
]);
```

PDO has no native PHP array parameter type, so `ClickHouseValue` produces
escaped ClickHouse literals for `Array`, `Map`, and `Tuple` columns and JSON
text for native `JSON` columns.

For large batches:

```php
DB::connection('clickhouse')
    ->table('events')
    ->insertChunked($rows, 50_000);
```

For asynchronous inserts:

```php
DB::connection('clickhouse')
    ->table('events')
    ->async(wait: true)
    ->insert($rows);
```

### Mutations

ClickHouse UPDATE and DELETE operations are mutations and are asynchronous by
default. Use `mutationsSync()` when the next operation depends on completion:

```php
DB::connection('clickhouse')
    ->table('events')
    ->where('id', 42)
    ->mutationsSync()
    ->update(['status' => 'archived']);
```

UPDATE and DELETE require a WHERE clause and reject clauses that ClickHouse
mutations cannot honor. Use `whereRaw('1')` for an intentional full-table
mutation, or `truncate()` when the intent is to remove every row.

Classic and lightweight deletes are both available:

```php
// Lightweight DELETE FROM; useful for small, frequent deletes.
DB::connection('clickhouse')
    ->table('events')
    ->where('id', 42)
    ->mutationsSync()
    ->deleteLightweight();

// Classic ALTER TABLE ... DELETE, scoped to a partition.
DB::connection('clickhouse')
    ->table('events')
    ->where('tenant_id', 7)
    ->mutationsSync()
    ->deleteMutation('2026-07');
```

`delete()` uses classic mutation semantics by default. Set
`CLICKHOUSE_LIGHTWEIGHT_DELETES=true` to make it use lightweight deletes, or
choose explicitly with `deleteLightweight()` / `deleteMutation()`. Each method
accepts an optional partition value. Eloquent exposes the same helpers;
ordinary Eloquent `delete()` continues through Laravel's delete callbacks,
while explicit ClickHouse delete options perform a physical delete.

### FORMAT

`format('JSONEachRow')` emits a native ClickHouse `FORMAT` clause. The PDO
driver still exposes the result as normal PDO rows; this is not an HTTP
response-body serializer.

## Eloquent

```php
use ClickHouse\Laravel\Eloquent\Model;

final class Event extends Model
{
    protected $table = 'events';

    protected $fillable = ['id', 'user_id', 'event', 'payload'];

    protected $casts = [
        'user_id' => 'integer',
    ];
}
```

The conventional `Model` name and the original `ClickHouseModel` name refer to
the same base behavior. It selects the `clickhouse` connection, disables
incrementing IDs and Laravel's built-in timestamps, and retains Laravel's
guarded mass-assignment default. Define `$fillable` or `$guarded` on every
concrete model.

For `created_at` and `updated_at` values managed by the application:

```php
use ClickHouse\Laravel\Concerns\HasClickHouseTimestamps;
use ClickHouse\Laravel\Eloquent\Model;

final class Event extends Model
{
    use HasClickHouseTimestamps;
}
```

The trait sets both timestamps on create and refreshes `updated_at` on every
save, which is useful with `ReplacingMergeTree(updated_at)`.

## Parallel queries

`Parallel::get()` accepts keyed query-builder and Eloquent selects and returns
keyed Laravel collections:

```php
use ClickHouse\Laravel\Parallel;

$results = Parallel::get([
    'recent' => Event::query()->where('occurred_at', '>=', now()->subHour()),
    'errors' => DB::connection('clickhouse')
        ->table('events')
        ->where('level', 'error'),
]);

$recentEvents = $results['recent']; // Eloquent collection
$errorRows = $results['errors'];    // Support collection
```

The default Laravel concurrency driver starts isolated child PHP processes.
Only the connection name, compiled SQL, and bindings cross the process
boundary; PDO objects are never serialized, and every worker opens its own
native connection. A Laravel driver name can be selected with
`Parallel::get($queries, driver: 'fork')`. Use Laravel's `sync` concurrency
driver in tests when parallel execution is not under test:
`Parallel::get($queries, driver: 'sync')`.

Parallel execution is intended for independent SELECT queries. Eager loading
and registered after-query callbacks are applied after each result is hydrated.

## Schema builder

```php
use ClickHouse\Laravel\Schema\ClickHouseBlueprint;
use Illuminate\Support\Facades\Schema;

Schema::connection('clickhouse')->create(
    'events',
    function (ClickHouseBlueprint $table) {
        $table->uint64('id')->codec('ZSTD(3)');
        $table->boolean('active');
        $table->json('payload'); // JSON text in a PDO-safe String column
        $table->date32('historical_day');
        $table->dateTime64('occurred_at', 6);
        $table->time64('local_time', 3);
        $table->array('tags', 'String');
        $table->mapOf('attributes', 'String', 'String');
        $table->string('country')->nullable()->lowCardinality();

        $table->engine('MergeTree()');
        $table->partitionBy('toYYYYMM(occurred_at)');
        $table->primaryKey(['id']);
        $table->orderBy(['id', 'occurred_at']);
        $table->sampleBy('id');
        $table->ttl('occurred_at + INTERVAL 90 DAY');
        $table->setting('index_granularity', 8192);
        $table->tableComment('Analytics events');
    },
);
```

Native helpers include signed and unsigned integers up to 128 bits, `Float32`,
`Float64`, `Decimal`, `Bool`, `String`, `FixedString`, `LowCardinality`,
`Date`, `Date32`, `DateTime`, `DateTime64`, `Time`, `Time64`, `UUID`, `IPv4`,
`IPv6`, `Enum8`, `Array`, `Map`, and `Tuple`. Laravel's `json()` and `jsonb()`
map to `String` so JSON round-trips through PDO reliably.

`nativeJson()` is available when ClickHouse's native `JSON` type is required.
The released PDO extension cannot decode `Dynamic` values directly, so cast
native JSON results to a supported scalar type, for example
`toJSONString(payload)` or `toString(payload.source)`.

Use `clickhouseType()` for another trusted type expression not covered by a
helper.

ALTER helpers include:

```php
Schema::connection('clickhouse')->table('events', function (ClickHouseBlueprint $table) {
    $table->renameColumn('country', 'country_code');
    $table->lowCardinalityString('country_code')->change();
    $table->skipIndex('idx_status', 'status', 'set(100)', granularity: 2);
    $table->projection(
        'events_by_user',
        'SELECT user_id, count() GROUP BY user_id',
    );
});
```

ClickHouse column definitions expose typed fluent `codec()`, `ttl()`,
`materialized()`, `alias()`, `ephemeral()`, and `lowCardinality()` modifiers
alongside Laravel's standard column modifiers. `array()` is the concise alias
of `arrayOf()`, while `orderBy()` and `primaryKey()` accept either arrays or
variadic columns. Table DDL and ALTER operations support `onCluster()`.

## Migrations

Normal Laravel migration commands can target ClickHouse:

```bash
php artisan migrate --database=clickhouse
php artisan migrate:status --database=clickhouse
php artisan migrate:rollback --database=clickhouse
```

The package replaces Laravel's migration repository with a compatible
repository before it is first resolved. On ClickHouse, the repository table
uses `MergeTree()` and orders by batch and migration name; other database
connections retain Laravel's normal repository behavior. Removing a migration
log entry waits for the ClickHouse mutation so a subsequent migration command
sees consistent state.

Migration `up()` and `down()` methods should use
`Schema::connection('clickhouse')`. ClickHouse has no transactional migration
rollback, so write reversible `down()` methods and avoid assuming DDL
atomicity.

## Cluster failover and retries

```php
'cluster' => [
    ['host' => 'clickhouse-01', 'port' => 9000],
    ['host' => 'clickhouse-02', 'port' => 9000],
],
'retries' => 2,
'retry_backoff_ms' => 100,
'retry_writes' => false,
```

Nodes are attempted sequentially. Both reads and writes use the active endpoint
once; the package does not fan writes out to every host. Put replication in
ClickHouse with `ReplicatedMergeTree` and/or `Distributed` tables.

After a lost connection, read-only statements can be retried with exponential
backoff. Writes are not replayed by default because the server may have
committed them before the connection failed. `retry_writes=true` is an explicit
at-least-once tradeoff and requires idempotent writes or deduplication.
Raw statements beginning with `WITH` are not automatically retried because a
CTE can precede an INSERT; opt in to write retries only with those delivery
semantics in mind.

## Transactions

ClickHouse does not provide Laravel transaction semantics. Transaction methods
therefore throw `TransactionsNotSupportedException` by default:

```php
DB::connection('clickhouse')->transaction(fn () => doWork());
// Throws: the callback was not run.
```

Legacy shared code can explicitly opt into non-transactional passthrough:

```php
'transactions' => 'passthrough',
```

Passthrough only runs the callback. It does not provide BEGIN, COMMIT, rollback,
or atomicity.

## Performance

The package uses ClickHouse's native TCP protocol through `pdo_clickhouse`.
A reproducible Docker microbenchmark compares that path with
`laravel-clickhouse/laravel-clickhouse`'s default Guzzle HTTP transport across
connection, point-query, aggregate, result-materialization, and insert
workloads.

See [benchmarks/README.md](benchmarks/README.md) for the methodology, measured
reference result, caveats, and the one-command runner.

## Development

```bash
composer validate --strict
composer format:check
composer analyse
composer test:unit
composer test:integration
```

`composer analyse` runs PHPStan at level `max` and Psalm at error level 1.
Both analyze `src/` without a generated baseline; the only suppressions are
documented compatibility or framework-boundary cases.

Integration tests expect native ClickHouse instances on `127.0.0.1:9000` and
`127.0.0.1:9001`. See [CONTRIBUTING.md](CONTRIBUTING.md) for the complete
workflow. Maintainers should use the gated process in
[RELEASING.md](RELEASING.md) for tagged releases.

## License

MIT
