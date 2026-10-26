# Releasing

Releases are published by the `Release` workflow when a stable `vX.Y.Z` tag is
pushed. The workflow does not create tags.

## Prepare

1. Move the intended entries from `Unreleased` into a dated
   `## [X.Y.Z] - YYYY-MM-DD` section in `CHANGELOG.md`.
2. Update the comparison links at the bottom of the changelog.
3. Merge the release commit into `main` and wait for the normal `Tests`
   workflow to pass.

## Publish

Push an annotated tag for that release commit. The tag must be a stable semver
with a `v` prefix, and the commit must be on `main`:

```bash
git switch main
git pull
git tag --annotate v1.1.0 --message "Release v1.1.0"
git push origin v1.1.0
```

The tag push runs `Release`. It derives the version by stripping the leading
`v`, checks that the tag is `vX.Y.Z`, that the tagged commit is on the default
branch, and that `CHANGELOG.md` contains `## [X.Y.Z] - `. It then reruns the
complete PHP, Laravel, and ClickHouse test matrix, builds a Composer archive
and SHA-256 checksum, and publishes the GitHub release for the existing tag.

`workflow_dispatch` is only a fallback to publish again when the tag already
exists and points at the current default-branch commit. It does not create a
tag. If the tag is missing, push `vX.Y.Z` (or run `gh release create`) first:

```bash
gh workflow run release.yml \
    --repo lucasacoutinho/clickhouse-laravel \
    --ref main \
    --field version=1.1.0
```

Packagist is connected to the GitHub repository and imports semantic tags after
they are pushed. Verify the new version at
<https://packagist.org/packages/lucasacoutinho/clickhouse-laravel> before
announcing it.
