# Security policy

## Supported versions

Until the first tagged release, security fixes are applied to `main`. After a
stable release, only the latest stable major line will receive security fixes.

## Reporting a vulnerability

Do not open a public issue for a suspected vulnerability.

Use GitHub's private vulnerability reporting or security-advisory flow for
`lucasacoutinho/clickhouse-laravel`. Include:

- the affected version or commit;
- the smallest reproducible query or configuration;
- whether untrusted input is required;
- the expected and observed behavior;
- any practical impact or proof of concept.

Avoid including production credentials, certificates, customer data, or
publicly reachable ClickHouse endpoints. A maintainer will acknowledge the
report privately and coordinate validation, remediation, and disclosure.

## Trust boundaries

Normal query-builder values use PDO bindings. APIs ending in `Raw`,
`DB::raw()`, ClickHouse schema expressions, and user-supplied SQL passed to
Laravel's raw query methods are trusted-input surfaces and must not contain
unvalidated user input.
