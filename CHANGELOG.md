# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

### Added

- Published package configuration with automatic, non-destructive connection
  registration.
- TLS, custom CA, mutual-TLS, compression, persistent-connection, retry, and
  backoff configuration.
- Safe sequential endpoint failover.
- Native `Bool`, `Date32`, `Time`, and `Time64` schema types, PDO-safe
  `json()`/`jsonb()` String columns, and explicit native `JSON` opt-in.
- Table sampling, TTLs, comments, column expressions, column TTLs, comments,
  skip indexes, projections, rename-column, and change-column schema support.
- `ClickHouseValue` helpers for `Array`, `Map`, `Tuple`, and `JSON` values.
- Synchronous-mutation and asynchronous-insert query helpers.
- Laravel-style `Eloquent\Model`, named `final` table queries, SAMPLE offsets,
  array-based `LIMIT BY`, key/value settings, and the complete PREWHERE
  predicate family.
- Multiple scalar, subquery, raw, and recursive CTEs with correctly ordered
  bindings.
- `GLOBAL IN` / `GLOBAL NOT IN`, empty / not-empty predicates, and explicit
  `UNION`, `INTERSECT`, and `EXCEPT` `ALL` / `DISTINCT` operations.
- Lightweight and partition-scoped deletes for the query builder and Eloquent.
- A ClickHouse-compatible Laravel migration repository.
- Parallel query-builder and Eloquent selects through Laravel's concurrency
  drivers.
- Typed ClickHouse column definitions with fluent codec, TTL, materialized,
  alias, ephemeral, and low-cardinality modifiers.
- PHPStan at level `max`, Psalm at error level 1, Pint, current PHPUnit
  support, and live ClickHouse 26.3/26.6 CI.

### Changed

- Require PHP 8.2+, maintained Laravel 12/13, and version 1.2.0+ of both native
  extensions.
- Preserve SQL binding order across PREWHERE, JOIN subqueries, WHERE, and
  mutation clauses.
- Preserve expression placeholders in PREWHERE and INSERT-select settings.
- Validate or quote ClickHouse identifiers, tokens, settings, table functions,
  schema expressions, and literals.
- Execute cluster writes once on the active node. Replication is delegated to
  ClickHouse engines instead of client-side fan-out.
- Retry read-only operations after lost connections; write retries require
  explicit opt-in.
- Use PDO affected-row accounting for inserts and mutations.
- Keep Eloquent mass assignment guarded until a concrete model declares
  `$fillable` or `$guarded`.
- Refresh `updated_at` on every save when using `HasClickHouseTimestamps`.
- Accept arrays as well as variadic values for schema primary and ordering keys.
- Preserve Laravel Eloquent delete callbacks for ordinary deletes while
  exposing explicit physical ClickHouse delete modes.

### Fixed

- PREWHERE binding order when methods are called after WHERE.
- Duplicate JOIN-subquery bindings after repeated query compilation.
- Quoting of `remote()` and `merge()` table-function arguments.
- Placement of INSERT settings before `VALUES` or `FORMAT`.
- Mutation settings and synchronous mutation execution.
- Initial cluster failover when the first endpoint is unavailable.
- Nullable low-cardinality columns now compile as
  `LowCardinality(Nullable(...))`.
- Schema metadata now returns Laravel's normalized table shape and validates
  column metadata returned by the driver.
- Schema ALTER commands that previously emitted every blueprint column.
- Laravel 12/13 grammar and blueprint constructor compatibility.
- Global ordering, limits, offsets, FORMAT, and SETTINGS placement on compound
  ClickHouse set queries.
- Migration repository replacement for every migration command, rather than
  only the install command.

### Security

- Reject unsafe format, setting, sample, JOIN, interval, DSN, TLS, and schema
  inputs instead of interpolating them into SQL.
- Reject non-finite numeric literals, malformed ports/timeouts, and unsafe
  multi-statement convenience expressions.
- Require explicit predicates for UPDATE/DELETE and reject query clauses that
  ClickHouse mutations cannot honor.
- Validate PREWHERE operators and JOIN keys, and conservatively avoid replaying
  ambiguous `WITH` statements as read-only queries.

### Breaking

- Laravel 10 and 11 are no longer supported because both are beyond Laravel's
  security-support window.
- Transactions throw by default instead of silently running without atomicity.
- Cluster writes are no longer fanned out by the Laravel client.
- `ClickHouseModel` no longer disables Laravel mass-assignment protection.
