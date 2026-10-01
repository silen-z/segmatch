# php-router

A segment-tree router for PHP 8.4. Route declarations are compiled into a flat table of integer
node IDs, which is stored as a plain `return [...]` PHP file and matched by one generic loop.

The router only answers *which route matched, and what were the parameters*. It never interprets
the metadata attached to a route, so handlers, HTTP methods and middleware stay the application's
business.

This package is deliberately a small core: full paths in, metadata out. Prefixes, groups and
`get()`/`post()` helpers belong to a higher-level layer built on top of it (see
[Building a higher-level API](#building-a-higher-level-api)).

## Usage

```php
use silenz\PhpRouter\NoMatch;
use silenz\PhpRouter\RouteCache;
use silenz\PhpRouter\RouteMatch;
use silenz\PhpRouter\RouteSet;

$matcher = new RouteCache(__DIR__ . '/var/routes-' . APP_VERSION . '.php', enabled: !APP_DEBUG)->load(
    static function (RouteSet $routes): void {
        $routes->add('/', ['handler' => 'home']);
        $routes->add('/api/users/{id}', ['handler' => 'users.show', 'middleware' => ['auth']]);
        $routes->add('/assets/{path+}', ['handler' => 'assets']);
    },
);

$result = $matcher->match('/api/users/42');
if ($result instanceof RouteMatch) {
    $result->route;  // ['handler' => 'users.show', 'middleware' => ['auth']]
    $result->params; // ['id' => '42']
}
```

- `match()` takes the path only (no query string) and returns a `RouteMatch` or a `NoMatch`.
  Parameter values are `rawurldecode`d.
- Without a cache, use `new Matcher(Compiler::compile($routes))` directly.

Metadata is written into the cache file, so it may only contain scalars, `null`, enums and arrays
of those. Anything else is rejected at compile time.

### Caching

`RouteCache` works like FastRoute's cached dispatcher:

- **The cache key is the cache file.** If it exists, it is used as is and the callback passed to
  `load()` never runs. On a warm request the routes are not declared at all, just `require`d.
- **Nothing is invalidated automatically.** Anything that changes which routes get compiled must
  change the key: a deploy, or configuration that decides which routes exist. Put an application
  version or a hash of that configuration into the key.
- **`enabled: false` turns the cache off**, for development. Routes are then compiled on every
  `load()`, and nothing is read or written.
- **For custom cache handling**, `read()` and `write()` are public.

### Several routes per path and guards

Several routes may share a path, typically one per HTTP method. A *guard* passed to `match()`
decides which of them applies:

```php
$routes->add('/users', ['methods' => ['GET'], 'handler' => 'users.list']);
$routes->add('/users', ['methods' => ['POST'], 'handler' => 'users.create']);

$result = $matcher->match($path, static fn(array $route, array $params): bool => in_array($method, $route['methods'], true));

if ($result instanceof RouteMatch) {
    // dispatch $result->route with $result->params
} elseif ($result->rejected === []) {
    // 404: no route has this path
} else {
    // 405: the path exists for other methods; build Allow from $result->rejected
}
```

- **Candidates are offered in order.** The guard receives each candidate's metadata and decoded
  parameters, in precedence order and, for routes sharing a path, in declaration order. The first
  accepted route wins.
- **A rejected route behaves as if it didn't exist.** Matching continues and may backtrack: with
  `POST /foo/bar` and `GET /foo/{id}`, a `GET /foo/bar` matches the second route.
- **Rejections are reported.** If nothing is accepted, `NoMatch::$rejected` lists the metadata of
  every rejected candidate, which is what a 405 response needs.
- **Without a guard**, the first declared route of the best path wins.

Guards decide whether a route *applies to the request*: HTTP method, host, content type, parameter
format (`{id}` must be numeric), a feature switch. They must not check *who is asking*.
Authentication and permissions belong to middleware after matching, because a rejected route
falls through to other routes (or a 404) instead of producing a 401 or 403. Guards may run several
times per match, so keep them cheap and free of side effects: load any configuration once, before
matching, and let the closure capture it.
## Path syntax

| Segment    | Matches                                                                   |
|------------|---------------------------------------------------------------------------|
| `users`    | exactly that segment                                                      |
| `{id}`     | one non-empty segment                                                     |
| `{path*}`  | the rest of the path, zero or more segments: `/assets`, `/assets/`, `/assets/a/b` |
| `{path+}`  | the rest of the path, which must be non-empty: `/assets/a/b`, not `/assets/` |

- Paths start with `/`.
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

- Routes may share a path, also with different parameter names (`/foo/{id}` and `/foo/{name}`);
  a guard chooses between them.
- Catch-alls hanging off the same node must all be `{name*}` or all be `{name+}`.

## Building a higher-level API

A wrapper only has to turn its own declarations into full paths and final metadata. For example,
groups with prefixes and inherited middleware:

```php
final class Router
{
    private string $prefix = '';
    private array $middleware = [];

    public function __construct(private RouteSet $routes) {}

    public function get(string $path, string $handler): void
    {
        $this->routes->add($this->prefix . $path, [
            'methods' => ['GET'],
            'handler' => $handler,
            'middleware' => $this->middleware,
        ]);
    }

    public function group(string $prefix, Closure $define, array $middleware = []): void
    {
        [$outerPrefix, $outerMiddleware] = [$this->prefix, $this->middleware];
        $this->prefix .= $prefix;
        $this->middleware = [...$this->middleware, ...$middleware];
        try {
            $define($this);
        } finally {
            [$this->prefix, $this->middleware] = [$outerPrefix, $outerMiddleware];
        }
    }
}

$matcher = new RouteCache($file)->load(static function (RouteSet $routes): void {
    $r = new Router($routes);
    $r->group('/api', static function (Router $r): void {
        $r->get('/login', 'auth.login');
        $r->group('', static fn(Router $r) => $r->get('/users/{id}', 'users.show'), ['auth']);
    }, ['api']);
});
```

Because group metadata is resolved into each route's metadata, groups can have no prefix, share a
prefix, or nest freely, and a route never picks up metadata from a group it wasn't declared in.

## Architecture

```
RouteSet ──► TreeBuilder ──► Flattener ──► RouteCache (PHP file) ──► Matcher
 paths        tree + checks    tables        return [...]              hash lookup / loop + backtracking
```

- **Routes without parameters** are answered from a static table keyed by the full path, a single
  hash lookup.
- **Everything else** goes through the tree. Each compiled node is a list `[static map, param
  child, catch-all routes, catch-all min, routes]`, with route-ID lists in declaration order and
  `-1` for "no param child". The field positions
  are named in `Internal\Layout`.
- **A route's metadata and parameter names** live in a separate route table, so the traversal
  loop only deals with integers and segment strings.

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

The benchmarks (PHPBench) compare this router with FastRoute in five groups:

- `match`: steady-state lookups, one benchmark per interesting case
- `match-mixed`: steady-state lookups cycling through every route of a fixture plus 404s
- `load-match`: loading the cache file and matching one path, as on a cold PHP-FPM request
- `load`: turning an existing cache file into a matcher
- `compile`: building from declarations

Run one group with `vendor/bin/phpbench run --group=match --report=aggregate`. The fixtures are in
`benchmarks/fixtures/`, and `tests/BenchmarkFixturesTest.php` checks that both routers return the
same result for every benchmark request.

On Windows, `phpbench.json` sets `runner.remote_script_path` to a relative directory. Without it,
PHPBench fails when the temp directory path contains non-ASCII characters.
