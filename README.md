# Segmatch

A segment-tree router for PHP 8.4. Route declarations are compiled into a flat table of integer
node IDs, which is stored as a plain `return [...]` PHP file and matched by one generic loop.

The router only answers *which route matched, and what were the parameters*. It never interprets
the metadata attached to a route, so handlers, HTTP methods and middleware stay the application's
business.

The core is deliberately small: full paths in, metadata out. HTTP methods, groups and middleware
come from `Http\Routes`, a declaration layer built on top of it (see [HTTP routes](#http-routes)).

## Usage

```php
use SilenZ\Segmatch\Cache\FileCache;
use SilenZ\Segmatch\RouteMatch;
use SilenZ\Segmatch\Router;
use SilenZ\Segmatch\RouteSet;

$router = new Router(
    static function (RouteSet $routes): void {
        $routes->add('/', ['handler' => 'home']);
        $routes->add('/api/users/{id}', ['handler' => 'users.show', 'middleware' => ['auth']]);
        $routes->add('/assets/{path+}', ['handler' => 'assets']);
    },
    cache: APP_DEBUG ? null : new FileCache(__DIR__ . '/var/cache'),
    cacheKey: 'routes-' . APP_VERSION,
);

$result = $router->match('/api/users/42');
if ($result instanceof RouteMatch) {
    $result->route;  // ['handler' => 'users.show', 'middleware' => ['auth']]
    $result->params; // ['id' => '42']
}
```

- `match()` takes the path only (no query string) and returns a `RouteMatch` or a `NoMatch`.
  Parameter values are `rawurldecode`d.
- `Router` is a thin entry point over the lower-level pieces. `new Matcher(Compiler::compile($routes))`
  gives a matcher without any caching, and `$router->matcher()` returns the router's own one.

Metadata is written into the cache, so it may only contain scalars, `null`, enums and arrays of
those. Anything else is rejected at compile time.

### Caching

Caching works like FastRoute's cached dispatcher:

- **The route callback runs only on a cache miss.** It runs the first time the router is used and
  the cache has no entry for `cacheKey`. On a warm request the routes are not declared at all; the
  compiled table comes straight from the cache.
- **Nothing is invalidated automatically.** Anything that changes which routes get compiled must
  change `cacheKey`: a deploy, or configuration that decides which routes exist. Put an application
  version or a hash of that configuration into the key. Different keys are separate cache entries.
- **`cache: null` disables caching.** Routes are then compiled whenever a `Router` is first used,
  which is what you want in development.
- **Any storage works.** `Cache\RouteCache` is a two-method interface (`get(key)`, `set(key,
  compiled)`). `Cache\FileCache` stores each key as a PHP file in a directory, written atomically
  and loaded with `require`, so OPcache serves it from memory. A key made of letters, digits,
  `.`, `_` and `-` is the file name (`routes-v2` => `routes-v2.php`); other keys are made safe and
  get a short hash (`tenant/a` => `tenant_a~1f3c8a2b.php`). Entries written by an incompatible
  router version are ignored and recompiled.

### Several routes per path and guards

Several routes may share a path, typically one per HTTP method. A *guard* passed to `match()`
decides which of them applies:

```php
$routes->add('/users', ['methods' => ['GET'], 'handler' => 'users.list']);
$routes->add('/users', ['methods' => ['POST'], 'handler' => 'users.create']);

$result = $router->match($path, static fn(array $route, array $params): bool => in_array($method, $route['methods'], true));

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

## HTTP routes

`Http\Routes` is a higher-level declaration API with HTTP methods, groups and middleware. It's an
ordinary route callable for `Router`, so caching works as described above:

```php
use SilenZ\Segmatch\Cache\FileCache;
use SilenZ\Segmatch\Http\RouteCollector;
use SilenZ\Segmatch\Http\Routes;
use SilenZ\Segmatch\Router;

$router = new Router(
    Routes::define(static function (RouteCollector $r): void {
        $r->get('/', HomeController::class);
        $r->map(['GET', 'POST'], '/contact', ContactController::class);
        $r->any('/webhooks/{provider}', WebhookController::class);

        $r->group('/api')->middleware('api')->define(static function (RouteCollector $r): void {
            $r->group()->middleware('guest')->define(static function (RouteCollector $r): void {
                $r->post('/login', [AuthController::class, 'login'])->name('login');
            });

            $r->group()->middleware('auth')->define(static function (RouteCollector $r): void {
                $r->get('/users/{id}', [UserController::class, 'show'])->name('users.show')->where('id', '\d+');
                $r->put('/users/{id}', [UserController::class, 'update']);

                $r->group('/admin')->middleware('admin')->define(static function (RouteCollector $r): void {
                    $r->get('/stats', [AdminController::class, 'stats'])->middleware('audit');
                });
            });
        });
    }),
    cache: new FileCache(__DIR__ . '/var/cache'),
    cacheKey: 'routes-' . APP_VERSION,
);
```

- **Verb helpers:** `get()`, `post()`, `put()`, `patch()`, `delete()` and `options()` declare a
  route for one method; `map()` for several; `any()` for every method.
- **Route builder:** each call returns a `Route`, refined with `->name()`, `->middleware()`,
  `->where()` (a regex for one parameter, without delimiters or anchors) and `->guard()`.
- **Groups:** `group()` takes an optional prefix, and `->middleware()` and `->define()` may be
  called in any order. Groups with no prefix only add middleware. Groups may share a prefix or
  nest freely, and a route only gets middleware from the groups it's declared in.
- **Middleware order:** enclosing groups' middleware first, outermost first, then the route's own.
  `/api/admin/stats` above gets `['api', 'auth', 'admin', 'audit']`.
- **Definitions as a class:** the definition callable may be an invokable class
  (`Routes::define(new AppRoutes())`). `Router` also accepts any invokable that takes a `RouteSet`
  directly.
- **Handlers and middleware are stored in the cache,** so they must be plain data: class names,
  `[Class::class, 'method']` arrays, strings, enums. Not closures.

### Guards: methods, patterns and your own conditions

A route's conditions are stored with it as *guards*:
- its HTTP methods become a `MethodGuard` (`any()` routes get none),
- `where()` constraints become a `PatternGuard`,
- `->guard(MyGuard::class, $config)` adds your own.

Match with `Guards::for()`, which runs every candidate route's guards against the request:

```php
use SilenZ\Segmatch\Http\Guards;
use SilenZ\Segmatch\Http\Request;
use SilenZ\Segmatch\RouteMatch;

$request = new Request($method, ['features' => $enabledFeatures]);   // attributes are optional
$result = $router->match($path, Guards::for($request));              // or Guards::for($method)

if ($result instanceof RouteMatch) {
    // dispatch $result->route['handler'] through $result->route['middleware'] with $result->params
} elseif (($allow = Guards::allowedMethods($result, $request)) !== []) {
    // 405, with header Allow: implode(', ', $allow)
} else {
    // 404
}
```

- **A rejected route doesn't exist for that request.** Matching continues, so a request falls
  through to another route: `GET /users/john` skips `GET /users/{id}` with `where('id', '\d+')`
  and reaches `GET /users/{slug}`.
- **`allowedMethods()` counts only routes rejected solely because of their method.** A route
  whose pattern or feature switch fails doesn't make a 405.
- **A custom guard implements `Http\Guard`:** one static method,
  `accepts(mixed $config, Request $request, array $params): bool`. The route stores only the
  class name and the configuration, so both must be cacheable plain data. Anything request-specific
  the guard needs goes into `Request::$attributes`, loaded once before matching.
- **Guards decide whether a route applies, never who is asking.** Authentication and permissions
  belong to middleware, which runs after matching.

Each route's metadata, as returned in `RouteMatch::$route`:

```php
[
    'handler' => [UserController::class, 'show'],
    'middleware' => ['api', 'auth'],
    'name' => 'users.show',                      // only when named
    'guards' => [                                // only when there are any, checked in this order
        MethodGuard::class => ['GET'],
        PatternGuard::class => ['id' => '\d+'],
    ],
]
```

Compile-time errors include duplicate route names, invalid prefixes or methods, invalid `where()`
patterns, `where()` on a parameter the path doesn't have, and guard classes that don't implement
`Http\Guard`.
## Architecture

```
RouteSet ──► TreeBuilder ──► Flattener ──► RouteCache ──► Matcher
 paths       tree + checks   tables        e.g. FileCache  static hash lookup, then tree loop + backtracking

Router wires these together: on a cache miss it declares, compiles and stores the routes.
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
