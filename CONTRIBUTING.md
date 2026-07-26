# Contributing

## Local requirements

- PHP 8.2 or newer
- Composer 2
- `ext-clickhouse` and `ext-pdo_clickhouse` 1.2.0 or newer
- Two ClickHouse instances for cluster integration tests

Install dependencies:

```bash
composer install
```

Run the complete local quality gate:

```bash
composer validate --strict
composer format:check
composer analyse
composer test:unit
composer test:integration
```

The integration suite uses these defaults:

```dotenv
CLICKHOUSE_HOST=127.0.0.1
CLICKHOUSE_PORT=9000
CLICKHOUSE_PORT_2=9001
CLICKHOUSE_DATABASE=default
CLICKHOUSE_USERNAME=default
CLICKHOUSE_PASSWORD=
```

Use `composer format` to apply the repository's Pint rules.

## Pull requests

- Add regression coverage for every behavior change.
- Prefer a live integration test when native protocol or ClickHouse server
  behavior is involved.
- Keep Laravel-native bindings for values. Treat methods ending in `Raw`,
  `DB::raw()`, and schema expressions as explicit trusted-input APIs.
- Do not add automatic write replay or multi-node write fan-out without
  documenting the resulting delivery semantics.
- Keep the README and changelog aligned with public behavior.

CI checks PHP 8.2 through 8.5, Laravel 12 and 13, and ClickHouse 26.3 and 26.6.
