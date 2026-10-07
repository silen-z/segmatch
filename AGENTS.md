# AGENTS.md

Segmatch is a segment-tree PHP router (PHP 8.4). For what it does and how to use it, see
[README.md](README.md) — this file is about working *on* the project: environment, QA, static
analysis, benchmarks, and conventions not written down elsewhere.

## Project layout

- `src/` — the library. `Router`/`Matcher`/`Compiler`/`RouteTable` are the core; `Http/` is the
  HTTP declaration layer (`Routes`, `LazyRoutes`, `HandlerBuilder`, `UrlGenerator`); `Cache/` is
  `RouteCache` + `FileCache`; `OpenApi/` is `PathsGenerator`.
- `tests/` — PHPUnit, mirrors `src/`'s structure. Fixtures live in `tests/Fixtures/`,
  `tests/Http/Fixtures/`, `tests/Support/`.
- `benchmarks/` — PHPBench, see [Benchmarks](#benchmarks) below.
- `examples/openapi.php` — a runnable end-to-end example; keep it in sync if the `Router`/`Routes`
  API it calls changes (it's not covered by the test suite or static analysis' type-checking in the
  same way production code is, so it tends to drift silently).

## Development environment

There's no PHP on the host by assumption — use the Docker setup in `docker/`:

```bash
docker compose up -d
docker compose exec php composer install
docker compose exec php composer qa
```

The image is plain `php:8.4-cli` with opcache and composer, nothing project-specific beyond that.
If Docker isn't available, any PHP ≥8.4 CLI binary works the same way — there's nothing
container-specific in how the project runs, this is just the one environment that's guaranteed to
have the right PHP version and extensions.

## QA and static analysis

```bash
composer qa    # mago format --check && mago lint && mago analyze && composer test
composer test  # phpunit
```

There's no CI configured (no `.github/workflows`) — `composer qa` must be run manually before
committing, and is the thing to run after any change.

Static analysis is [mago](https://mago.carthage.software/), configured in `mago.toml`:

- `format` is non-negotiable style; `lint` is structural rules (complexity thresholds, method
  counts, PHPUnit-specific checks via the `phpunit` integration); `analyze` is the type-checker.
- A few classes get raised thresholds or exclusions in `mago.toml`, each with a comment explaining
  why (e.g. `Matcher::match()` is deliberately one large inlined loop since it's the hot path, and
  `Http\Routes`/`Http\LazyRoutes` are excused from `too-many-methods` since they genuinely have one
  helper per HTTP verb). Don't suppress a new class's findings without the same kind of explanation inline.
- **`@mago-expect analysis:<rule>` / `@mago-expect lint:<rule>`** comments suppress a specific
  finding on the statement directly below them. Used throughout the codebase, always paired with a
  one-line comment explaining *why* the code is correct despite being statically imprecise. Two
  recurring cases:
  - Genuinely arbitrary user data (route/middleware metadata) is typed `mixed` by necessity, so
    assigning it to a variable triggers `mixed-assignment`.
  - A test deliberately passes a wrong-shaped value to verify something rejects it at runtime (e.g.
    `LazyRoutes` rejecting an instance it can't cache) — the "error" is the point of the test.
  Don't add one to paper over an actual bug, and prefer fixing the underlying type signature (as in
  the `declared()`/`declaredEagerly()`/`declaredLazily()` split in `HandlerBuilderModesTest.php`)
  over suppressing when the mismatch is fixable instead of inherent.
- A finding that looks wrong is worth double-checking against `git stash` / a clean checkout before
  assuming it's new — `mago.toml`'s exclude lists have drifted behind new files before (`LazyRoutes`
  wasn't in the `too-many-methods` exclude list for a while after it was split out of `Routes`).

## Benchmarks

```bash
composer bench                                               # everything
vendor/bin/phpbench run --group=match --report=aggregate     # one group
```

They exist to answer "is this still fast, and how does it compare to FastRoute" — every change to
`Matcher`, `Compiler` or `Flattener` is a candidate for a before/after run, not just the test suite.
Five groups, each isolating one phase so a regression points at a specific place:

- `compile`: building the compiled table from declarations (`Compiler`/`Flattener`).
- `load`: turning an existing cache file into a matcher — pure cache-read/require cost, no matching.
- `match`: steady-state lookups against an already-loaded matcher, one benchmark per interesting
  route shape.
- `match-mixed`: steady-state lookups cycling through every route of a fixture plus 404s, closer to
  real traffic than one-shape-at-a-time.
- `load-match`: load + match together, approximating a cold PHP-FPM request end to end.

`benchmarks/fixtures/*.php` are named route shapes (`small`, `medium`, `large`, `branching`, `deep`,
`parameters`, `resources`) each with a one-line comment on what it's shaped like and why (e.g.
`branching.php`: "1000 static-heavy routes: 500 siblings under the root, each with one child").
`benchmarks/Routers.php` builds both this router and FastRoute from the same fixture;
`benchmarks/Fixtures.php` loads them. `tests/BenchmarkFixturesTest.php` is the correctness
backstop — it isn't a benchmark itself, it asserts both routers agree on every benchmark request,
so a fixture can't silently start comparing apples to oranges.

PHPBench's own config is `phpbench.json` (results under `var/phpbench`, not committed). On Windows,
it sets `runner.remote_script_path` to a relative directory, since PHPBench fails outright when the
temp directory path contains non-ASCII characters otherwise.

## Testing strategy

Beyond ordinary unit tests, two tests cross-check against an independent reference rather than
asserting specific expected values — worth knowing about since a change to matching behavior should
usually update both, not just the direct unit tests:

- `tests/MatcherPropertyTest.php` runs `Matcher` against `tests/Support/RouteOracle.php` — a
  deliberately naive, non-tree brute-force reference implementation of the same matching rules —
  across 400 seeded random route sets, requests and filters. If `Matcher`'s precedence or
  backtracking rules ever change, `RouteOracle` has to change the same way, or every seed fails.
- `tests/BenchmarkFixturesTest.php` runs the benchmark fixtures through both this router and
  FastRoute and asserts they agree — see [Benchmarks](#benchmarks). This isn't a benchmark itself,
  it's what keeps the benchmarks honest.

## Git conventions

- One logical change per commit; a long single-line subject (sometimes semicolon-joined clauses) is
  the norm here over a short title + bullet body — see `git log` for the house style.
- Always a new commit, never `--amend`, unless explicitly asked.
- Nothing gets pushed unless explicitly asked.

## Gaps in README.md worth knowing about

Fixed as part of the same pass that wrote this file: `Http\ErrorMiddleware` was entirely
undocumented in "Handling requests" (README now covers the default, `build()`'s `$errorMiddleware`
override, and that it wraps the root's own middleware too); `RouteDefinition::$pathTemplate` and
`$parameters` weren't shown alongside `$path`/`$metadata` in "Declared routes"; a stale
`Router::matcher()` mention survived from before that method became private; and the OpenAPI
section didn't mention that `zircote/swagger-php` is a regular (not dev-only) dependency, so it's
always installed, OpenAPI generation used or not.

Still true as of this writing:

- **No "Development" detail.** README's whole dev-workflow section is three `composer` commands
  and one line on what `qa` runs — it doesn't mention mago specifically, the `@mago-expect`
  convention, the testing-strategy oracle/cross-check pattern, or the Docker environment. This file
  is that detail; consider merging the two at some point instead of maintaining both.
- **The compiled cache format itself isn't documented in README** — only in `Compiler`'s own
  docblock (the `'metadata'`/`'routeMetadata'`/`'static'`/`'edges'`/... shape, and
  `Compiler::FORMAT_VERSION`'s role in cache invalidation across incompatible versions). Likely
  fine as internal detail rather than a real gap — same reasoning as not documenting FastRoute's own
  dispatch data format in its README — but noted in case someone building their own `RouteCache` or
  reading a compiled file by hand goes looking for it in the wrong place.
- **No installation/requirements section.** No `composer require` line, no stated PHP version
  requirement (it's `>=8.4` in `composer.json`), nothing on what's a regular vs dev dependency. Not
  added here since the package is `"license": "proprietary"` and may not be meant to be installed
  the usual Packagist way — worth asking before assuming a standard "Installation" section belongs.
