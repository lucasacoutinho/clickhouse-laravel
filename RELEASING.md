# Releasing

Releases are created by the repository's gated `Release` workflow. Do not push
release tags manually.

## Prepare

1. Move the intended entries from `Unreleased` into a dated
   `## [X.Y.Z] - YYYY-MM-DD` section in `CHANGELOG.md`.
2. Update the comparison links at the bottom of the changelog.
3. Merge the release commit into `main` and wait for the normal `Tests`
   workflow to pass.

## Publish

Run the `Release` workflow from `main` and enter the version without a `v`
prefix. For example:

```bash
gh workflow run release.yml \
    --repo lucasacoutinho/clickhouse-laravel \
    --ref main \
    --field version=1.1.0
```

The workflow validates the version and changelog, reruns the complete PHP,
Laravel, and ClickHouse test matrix, creates an annotated `vX.Y.Z` tag, builds
a Composer archive and SHA-256 checksum, and publishes the GitHub release.

Packagist is connected to the GitHub repository and imports semantic tags after
they are pushed. Verify the new version at
<https://packagist.org/packages/lucasacoutinho/clickhouse-laravel> before
announcing it.
