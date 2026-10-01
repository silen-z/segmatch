# php-router

A segment-tree router for PHP 8.4. Route declarations are compiled into a flat table of integer
node IDs, which is stored as a plain `return [...]` PHP file and matched by one generic loop.

The router only answers *which route matched, which groups did the path pass through, and what
were the parameters*. It never interprets the metadata attached to routes and groups, so handlers,
HTTP methods and middleware stay the application's business.

## Usage

```php
use silenz\PhpRouter\RouteCache;
use silenz\PhpRouter\RouteCollector;

$matcher = (new RouteCache(__DIR__ . '/var/routes.php'))->load(
    static function (RouteCollector $r): void {
        $r->add('/', ['handler' => 'home']);

        $r->group('/api', static function (RouteCollector $r): void {
            $r->add('/users/{id}', ['methods' => ['GET'], 'handler' => 'users.show']);

            $r->group('/admin', static function (RouteCollector $r): void {
                $r->add('/stats', ['handler' => 'admin.stats']);
            }, ['middleware' => ['admin']]);
        }, ['middleware' => ['auth']]);

        $r->add('/assets/{path+}', ['handler' => 'assets']);
    },
    sources: [__FILE__], // recompile when this file changes
);

$match = $matcher->match('/api/admin/stats');
$match->route;  // ['handler' => 'admin.stats']
$match->groups; // [['middleware' => ['auth']], ['middleware' => ['admin']]], outermost first
$match->params; // []
```

`match()` returns `null` when no route matches. It takes the path only (no query string), and
parameter values are `rawurldecode`d.

Without a cache, use `new Matcher(Compiler::compile($collector))` directly.

### Metadata

- **Route metadata** is one record per route, for example a handler, allowed methods or
  route-level middleware.
- **Group metadata** is one record per group, typically middleware. A group with `null` metadata
  only shares a prefix and does not show up in `RouteMatch::$groups`.

Metadata is written into the cache file, so it may only contain scalars, `null`, enums and arrays
of those. Anything else is rejected at compile time.

## Path syntax

| Segment    | Matches                                                                   |
|------------|---------------------------------------------------------------------------|
| `users`    | exactly that segment                                                      |
| `{id}`     | one non-empty segment                                                     |
| `{path*}`  | the rest of the path, zero or more segments: `/assets`, `/assets/`, `/assets/a/b` |
| `{path+}`  | the rest of the path, which must be non-empty: `/assets/a/b`, not `/assets/` |

- Placeholders always cover a whole segment. `/file.{ext}` is rejected.
- A catch-all must be the last segment.
- Matching is case-sensitive.
- Trailing slashes matter: `/foo` and `/foo/` are different routes. Empty segments are only
  allowed as a trailing slash.
- When several routes fit, the order is **static > parameter > catch-all**. If a branch fails
  deeper down, matching backtracks: with `/foo/bar` and `/foo/{id}/baz` declared, `/foo/bar/baz`
  matches the second route.
- An exact route takes precedence over a `{path*}` catch-all hanging off the same node.

### Declaration rules (enforced at compile time)

- No duplicate routes. `/foo/{id}` and `/foo/{name}` count as duplicates. Two different routes
  may still share a parameter position under different names.
- One catch-all per node.
- Group prefixes must be non-empty, start with `/`, not end with `/`, and not end with a
  catch-all.
- Each group prefix may be used by only one group.
- Every route or group below a group prefix must be declared inside that group. Otherwise the
  group's metadata would leak onto routes that don't belong to it.
- Inside a group, `add('', ...)` declares a route on the prefix itself, and `add('/', ...)`
  declares the prefix with a trailing slash.

## Architecture

```
RouteCollector ──► TreeBuilder ──► Flattener ──► RouteCache (PHP file) ──► Matcher
 declarations      tree + checks    node IDs      return [...]              loop + backtracking
```

Each compiled node is a list `[static map, param child, catch-all route, catch-all min,
route, group]`, where `-1` means "none". The field positions are named in `Internal\Layout`.
Parameter names and metadata live in separate tables, so the traversal loop only deals with
integers and segment strings.

## Development

```bash
composer test
```

```bash
composer qa
```

`composer qa` runs `mago format --check`, `mago lint`, `mago analyze` and then the tests.

```bash
composer bench
```

The benchmarks (PHPBench) compare this router with FastRoute in three groups:

- `match`: steady-state lookups
- `compile`: building from declarations
- `load`: turning an existing cache file into a matcher

Run one group with `vendor/bin/phpbench run --group=match --report=aggregate`. The fixtures are in
`benchmarks/fixtures/`, and `tests/BenchmarkFixturesTest.php` checks that both routers return the
same result for every benchmark request.

On Windows, `phpbench.json` sets `runner.remote_script_path` to a relative directory. Without it,
PHPBench fails when the temp directory path contains non-ASCII characters.
