# Kibble

This is the package that makes our package manager work.

> [!WARNING]  
> This package is currently under active development, and we have not yet released a major version. Once a 0.* version
> has been tagged, we strongly recommend locking your application to a specific working version because we might make
> breaking changes even in patch releases until we've tagged 1.0.

## Installation

`composer require artisan-build/kibble`

## Configuration

## Usage

### Splitting packages

`php artisan kibble:split` mirrors each `packages/*` directory to its own read-only repository on
GitHub. The monorepo is always the source of truth — split repositories are force-synced from it.

```bash
# Split every package (force-syncs each split repo's main branch)
php artisan kibble:split

# Split a single package
php artisan kibble:split adverbs
```

### Distribution cleaning (default)

A split repository exists to be published, so by default `kibble:split` does **not** copy each
package's `composer.json` verbatim. It produces a *distribution* `composer.json` for the split that
strips dev-only wiring which is load-bearing inside the monorepo but wrong in the published package:

1. The top-level `version` field is removed so Packagist derives the version from the git tag rather
   than a hardcoded value (a stale `version` field breaks tag-derived/lockstep versioning).
2. `repositories` entries whose `type` is `path` are removed — their `../sibling` urls do not exist
   for consumers and otherwise print `The url supplied for the path (../<sibling>) repository does
   not exist` on every `composer require`. Non-`path` repositories are preserved, and the
   `repositories` key is dropped entirely when nothing remains.

Anything else in `composer.json` is left untouched. The strip is applied to the **split artifact
only** — the monorepo's own `packages/<pkg>/composer.json` on disk is never modified. Mechanically,
the cleaned content is committed in a detached worktree *after* `git subtree split` and *before* the
pushes, so both `main` and any `--tag` ref point at the cleaned commit.

Pass `--no-clean` to opt out and publish each `composer.json` verbatim (raw passthrough):

```bash
# Publish composer.json exactly as it appears in the monorepo
php artisan kibble:split adverbs --no-clean
```

### Lockstep release tagging

Pass `--tag` to also tag every split repository with a release version, following the Laravel
`illuminate/*` lockstep model — every split repo is tagged with the same version on every release,
even packages with no changes:

```bash
# Force-sync main AND create the tag on every split repo
php artisan kibble:split --tag=v1.2.0

# Tag a single package
php artisan kibble:split adverbs --tag=v1.2.0
```

Notes:

- **Idempotent re-runs.** The tag is pushed with `--force`. `git subtree split` synthesizes new
  commit SHAs on each run, so a re-run of a failed or partial release simply re-points the tag at
  the new split commit rather than erroring that the tag already exists. (Re-publishing an
  already-released version is still discouraged, but the mirror model permits it.)
- **No local tag is created.** The tag is pushed via a ref-spec (`split-branch:refs/tags/<tag>`),
  so no tag is written into the monorepo checkout. This avoids colliding with the monorepo's own
  release tag that triggered the split in CI.
- **Partial failures.** The command stops on the first failed sub-command, which can leave a
  release partially tagged. Because tag pushes are force-idempotent, simply re-run the same command
  to finish the release.
- **CI requirements.** `git subtree split` needs full history, so check out with `fetch-depth: 0`.
  Split repositories must already exist. Outside the local environment, pushes authenticate with the
  `GH_USERNAME` / `GH_TOKEN` credentials (see Configuration).

Example release workflow step (run on a monorepo tag push):

```yaml
- uses: actions/checkout@v4
  with:
    fetch-depth: 0
- run: composer install --no-interaction --prefer-dist
- run: php artisan kibble:split --tag=${{ github.ref_name }}
```

## Memberware

This package is part of our internal toolkit and is optimized for our own purposes. We do not accept issues or PRs
in this repository. 

