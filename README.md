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
use SilenZ\Segmatch\CallableRouteTable;
use SilenZ\Segmatch\RouteDefinition;
use SilenZ\Segmatch\RouteMatch;
use SilenZ\Segmatch\Router;

$routes = new CallableRouteTable(
    static fn(): array => [
        new RouteDefinition('/', ['handler' => 'home']),
        new RouteDefinition('/api/users/{id}', ['handler' => 'users.show', 'middleware' => ['auth']]),
        new RouteDefinition('/assets/{path+}', ['handler' => 'assets']),
    ],
    cacheKey: 'routes-' . APP_VERSION,
);
$router = new Router($routes, cache: APP_DEBUG ? null : new FileCache(__DIR__ . '/var/cache'));

$result = $router->match('/api/users/42');
if ($result instanceof RouteMatch) {
    $result->route;  // ['handler' => 'users.show', 'middleware' => ['auth']]
    $result->params; // ['id' => '42']
}
```

- **Routes come from a `RouteTable`:** a cache key, and the route definitions behind it, as one
  unit — a cache key that doesn't change with what `definitions()` produces risks silently serving
  stale routes, so the two live together instead of being passed to `Router` as separate arguments.
  `CallableRouteTable` wraps a plain callable for quick setups and tests; `definitions()` returns an
  iterable of `RouteDefinition`s — an array, a generator (`yield`, `yield from` to combine sources),
  or an invokable object. A `RouteDefinition` parses its path when it's created, so a malformed path
  throws where it's declared. `Http\Routes` gives you one too (`$routes->table(...)`, see
  [HTTP routes](#http-routes)), or implement `RouteTable` directly for anything more involved.
- `match()` takes the path only (no query string) and returns a `RouteMatch` or a `NoMatch`.
  Parameter values are `rawurldecode`d.
- `Router` is a thin entry point over the lower-level pieces. `new Matcher(Compiler::compile($routes))`
  gives a matcher without any caching, and `$router->matcher()` returns the router's own one.

Metadata is written into the cache, so it may only contain scalars, `null`, enums and arrays of
those. Anything else is rejected at compile time.

### Caching

Caching works like FastRoute's cached dispatcher:

- **`definitions()` runs only on a cache miss.** It runs the first time the router is used and the
  cache has no entry for `cacheKey()`. On a warm request the routes are not declared at all; the
  compiled table comes straight from the cache. `cacheKey()` itself is called every time, so it must
  stay cheap — never do the work `definitions()` does to compute it.
- **Nothing is invalidated automatically.** Anything that changes which routes get compiled must
  change what `cacheKey()` returns: a deploy, or configuration that decides which routes exist. Put
  an application version or a hash of that configuration into it. Different keys are separate cache
  entries.
- **A `null` key, or no `$cache` at all, disables caching.** Routes are then compiled whenever a
  `Router` is first used, which is what you want in development — `CallableRouteTable`'s key
  defaults to `null` for exactly this reason.
- **Any storage works.** `Cache\RouteCache` is a two-method interface (`get(key)`, `set(key,
  compiled)`). `Cache\FileCache` stores each key as a PHP file in a directory, written atomically
  and loaded with `require`, so OPcache serves it from memory. A key made of letters, digits,
  `.`, `_` and `-` is the file name (`routes-v2` => `routes-v2.php`); other keys are made safe and
  get a short hash (`tenant/a` => `tenant_a~1f3c8a2b.php`). Entries written by an incompatible
  router version are ignored and recompiled.

### Several routes per path and filters

Several routes may share a path, typically one per HTTP method. A *filter* passed to `match()`
decides which of them applies:

```php
new RouteDefinition('/users', ['methods' => ['GET'], 'handler' => 'users.list']),
new RouteDefinition('/users', ['methods' => ['POST'], 'handler' => 'users.create']),

$result = $router->match($path, static fn(RouteMatch $match): bool => in_array($method, $match->route['methods'], true));

if ($result instanceof RouteMatch) {
    // dispatch $result->route with $result->params
} elseif ($result->rejected === []) {
    // 404: no route has this path
} else {
    // 405: the path exists for other methods; build Allow from $result->rejected
}
```

- **Candidates are offered in order.** The filter receives each candidate as a `RouteMatch` (its
  metadata and decoded parameters), in precedence order and, for routes sharing a path, in
  declaration order. The first accepted route wins.
- **A rejected route behaves as if it didn't exist.** Matching continues and may backtrack: with
  `POST /foo/bar` and `GET /foo/{id}`, a `GET /foo/bar` matches the second route.
- **Rejections are reported.** If nothing is accepted, `NoMatch::$rejected` lists the metadata of
  every rejected candidate, which is what a 405 response needs.
- **Without a filter**, the first declared route of the best path wins.

Filters decide whether a route *applies to the request*: HTTP method, host, content type, parameter
format (`{id}` must be numeric), a feature switch. They must not check *who is asking*.
Authentication and permissions belong to middleware after matching, because a rejected route
falls through to other routes (or a 404) instead of producing a 401 or 403. Filters may run several
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
  a filter chooses between them.
- Catch-alls hanging off the same node must all be `{name*}` or all be `{name+}`.

## HTTP routes

`Http\Routes` is a higher-level declaration API with HTTP methods, groups and middleware. A `group()`
is itself a `Routes`, scoped by a prefix, its own middleware and its own tags; declaring runs
immediately, like any other PHP code. `->table($cacheKey)` gives the `RouteTable` `Router` takes, so
caching works as described above — only turning the declared routes into the compiled matching
structure is lazy and cache-gated, not declaring them:

```php
use SilenZ\Segmatch\Cache\FileCache;
use SilenZ\Segmatch\Http\Routes;
use SilenZ\Segmatch\Router;

$routes = new Routes();
$routes->get('/', HomeController::class);
$routes->map(['GET', 'POST'], '/contact', ContactController::class);
$routes->any('/webhooks/{provider}', WebhookController::class);

$api = $routes->group('/api')->middleware('api');
$api->group()->middleware('guest')->post('/login', [AuthController::class, 'login'])->name('login');

$authed = $api->group()->middleware('auth');
$authed->get('/users/{id}', [UserController::class, 'show'])->name('users.show');
$authed->put('/users/{id}', [UserController::class, 'update']);
$authed->group('/admin')->middleware('admin')->get('/stats', [AdminController::class, 'stats'])->middleware('audit');

$router = new Router(
    $routes->table('routes-' . APP_VERSION),
    cache: new FileCache(__DIR__ . '/var/cache'),
);
```

- **Verb helpers:** `get()`, `post()`, `put()`, `patch()`, `delete()` and `options()` declare a
  route for one method; `map()` for several; `any()` for every method.
- **Route builder:** each call returns a `Route`, refined with `->name()`, `->middleware()`,
  `->tag()` and `->filter()`.
- **Groups:** `group()` takes an optional prefix and returns a nested `Routes`; `->middleware()`,
  `->tag()` and declaring routes on it may happen in any order, since accumulation only happens once
  the tree is resolved into definitions (`compiled()` or `table()`). Groups with no prefix only add
  middleware. Groups may share a prefix or nest freely, and a route only gets middleware from the
  groups it's declared in.
- **Middleware order:** enclosing groups' middleware first, outermost first, then the route's own.
  `/api/admin/stats` above gets `['api', 'auth', 'admin', 'audit']`.
- **Tags:** `->tag('public', ...)` on a route or a group labels routes for your own code. Group
  tags are inherited, outermost first, without duplicates. The router never interprets tags.
- **Definitions as a class:** nothing stops you from grouping declarations into an invokable class
  and calling it yourself, e.g. `(new AppRoutes())($routes)`. You aren't limited to `Http\Routes`
  either — `CallableRouteTable` wraps any callable returning `RouteDefinition`s directly, or
  implement `RouteTable` yourself for anything more involved (see [Caching](#caching)).
- **A handler, middleware entry or filter may be a real instance or closure,** not just a class name:
  anything that isn't already cacheable plain data (scalars, null, enums, arrays of those) is
  transparently wrapped into the tree's `Http\Registry` instead, so routes can still be cached without
  giving up configured instances:

  ```php
  $routes->get('/reports', new ReportController($reportRepository));
  $routes->group('/api')->middleware(new Cors($corsConfig))->get(...);
  ```

  `Http\HandlerResolver` needs the same tree's registry to resolve those ids back, so it takes
  `$routes` itself, not just a `Router` built from it — `$router` must still be built from the same
  `$routes->table(...)`, since pairing a `Router` with a different declaration's registry would
  resolve the wrong instance, or none at all:

  ```php
  $resolver = new HandlerResolver($router, $responseFactory, $container, routes: $routes);
  ```

  `->handler()` builds both together from one `Routes`, for the common case of one tree answering its
  own requests, so this can't be gotten wrong:

  ```php
  $response = $routes->handler($request, $responseFactory, $container)->handle($request);
  ```

  Unlike the compiled routes, the registry is never cached — it's rebuilt fresh every time `$routes`
  is declared, which is why `Http\Routes` always declares eagerly (see above): the ids baked into a
  cached route's metadata only make sense together with the registry of the same, current declaration.
  `Http\HandlerResolver::addMiddleware()` (see [Handling requests](#handling-requests)) is still the
  right place for middleware that must apply even when nothing matches, like CORS on a 404 — that's a
  different concern from a route's own handler, middleware or filters, and not something `->resolve()`
  exposes; use `HandlerResolver` directly for it.

### Filters: methods and your own conditions

A route's HTTP methods are stored with it directly, not as a filter (`any()` routes get none).
`->filter(MyFilter::class)` adds a condition of your own on top.

There's deliberately no built-in parameter validation such as regex constraints: check parameter
values in the controller. If a route really must be skipped for some values, so that another
route can take the request, write a filter for it.

`Http\HandlerResolver` (see [Handling requests](#handling-requests)) is how you match: it checks
the route's methods with `Http\MethodNotAllowed` — generic, container-free — and resolves and runs
its filters, the only place that knows about the container.

- **A rejected route doesn't exist for that request.** Matching continues, so a request falls
  through to another route: `GET /users/new` skips `POST /users/new` and reaches
  `GET /users/{id}`.
- **A 405's allowed methods count only routes rejected solely because of their method.** A route
  whose own filter fails, such as a feature switch, doesn't make a 405.
- **A custom filter implements `Http\Filter`:** one method,
  `accepts(ServerRequestInterface $request, RouteMatch $match): bool`. There's no separate
  configuration parameter — a filter that needs configuration takes it as a constructor argument
  instead, e.g. `new FeatureFilter('beta')`. Anything request-specific the filter needs goes into
  the request's PSR-7 attributes (`$request->getAttribute(...)`), loaded once before matching;
  `$match->params` gives the candidate's URL-decoded parameters when what decides a route exists is
  baked into the path itself, e.g. a version or tenant segment.
- **Filters are resolved per match, not stored statically.** `$container?->get($filterClass) ?? new
  $filterClass()`, the same way as middleware and handlers — a filter with constructor dependencies
  needs a container; a plain one doesn't. `->filter(new MyFilter($dependency))` skips the container
  entirely by giving a ready instance instead of a class name. A class name is therefore only right
  for a filter that behaves the same everywhere, or varies by request rather than by route; one
  filter class that needs different configuration per route, like a feature name, needs a separate
  instance per route (`new FeatureFilter('beta')`, `new FeatureFilter('bulk-edit')`) — a container
  resolving a shared class name has no way to tell routes apart.
- **Filters decide whether a route applies, never who is asking.** Authentication and permissions
  belong to middleware, which runs after matching.

Each route's metadata, as returned in `RouteMatch::$route`:

```php
[
    'handler' => [UserController::class, 'show'],
    'middleware' => ['api', 'auth'],
    'name' => 'users.show',                      // only when named
    'path' => '/api/users/{id}',                 // only when named, for URL generation
    'tags' => ['public'],                        // only when tagged
    'methods' => ['GET'],                        // only for routes with methods (not any())
    'filters' => [FeatureFilter::class],         // only when there are any, checked in this order
]
```

`handler`, each `middleware` entry and each `filters` entry is a class name, container identifier, or
`Http\Registry` id standing in for a real instance or closure given instead.

Tags let cross-cutting code act on routes without splitting them into more groups. For example, one
auth middleware for the whole site that lets public routes through:

```php
$public = $r->group()->middleware(AuthMiddleware::class);
$public->get('/login', LoginForm::class)->tag('public');
$public->get('/account', ShowAccount::class);

// in AuthMiddleware::process(), which Http\HandlerResolver runs inside each route's stack:
$found = $request->getAttribute(Found::class);
if (!in_array('public', $found->tags, true) && !$session->isLoggedIn()) {
    return new Response(401);
}
```

`Found` is only on the request inside the route's stack (see
[Handling requests](#handling-requests)), so this works as route or group middleware, not as
middleware that runs before `HandlerResolver`.

### Handling requests

`Http\HandlerResolver` wraps the router. `resolve()` returns the PSR-15 handler that answers a
request — the matched route's middleware and handler as one stack built with
[Relay](https://relayphp.com/), or a 404/405 handler — so you don't handle `RouteMatch`,
`NoMatch`, methods and filters yourself:

```php
use SilenZ\Segmatch\Http\HandlerResolver;

// $responseFactory builds the default 404/405 responses; $container resolves filters, middleware and handlers
$resolver = new HandlerResolver($router, $responseFactory, $container, routes: $routes);
$response = $resolver->resolve($request)->handle($request);
```

Or, for the common case of one `Http\Routes` tree answering its own requests, skip building `$router`
and `$resolver` separately:

```php
$response = $routes->handler($request, $responseFactory, $container)->handle($request);
```

- **`$request` is a PSR-7 `ServerRequestInterface`.** The path comes from
  `$request->getUri()->getPath()`.
- **`$responseFactory` is a PSR-17 `ResponseFactoryInterface`, required.** The default 404, 405 and
  OPTIONS handlers build their responses with it.
- **`$container` is a PSR-11 `ContainerInterface`, optional.** Filters, middleware and the handler
  are resolved with `$container->get(...)`, or a plain `new $entry()` without a container. Each
  middleware entry must resolve to a `Psr\Http\Server\MiddlewareInterface`, and the handler to a
  `Psr\Http\Server\RequestHandlerInterface`.
- **`$routes` is the `Http\Routes` that `$router` was built from, optional.** Needed whenever a
  handler, middleware entry or filter was declared as a real instance or closure — `HandlerResolver`
  resolves those from `$routes`'s registry, which must be the same, current declaration `$router`'s
  routes came from, never a cached one, since the registry itself is never cached (see
  [HTTP routes](#http-routes)).
- **The match is a request attribute.** PSR-15 handlers take only the request, so
  `$request->getAttribute(Found::class)` gives the route's own middleware and handler an
  `Http\Found`: its `params` (URL-decoded, by name), `name` and `tags`. Parameters are deliberately
  not separate attributes, so they can't collide with the application's own:

  ```php
  $id = $request->getAttribute(Found::class)->params['id'];
  ```

  Middleware can use it too, for example to skip authentication on routes tagged `public`, without
  matching again.
- **Requests no route takes get one of three answers:**

  | Case | Answer | To change it |
  | --- | --- | --- |
  | No route for the path | `Http\NotFoundHandler`: 404 | the constructor's `$notFoundHandler` |
  | Routes for the path, not the method | `Http\AllowedMethodsHandler`: 405 + `Allow` | middleware (below) |
  | The same, for an OPTIONS request | `Http\AllowedMethodsHandler`: 200 + `Allow` | middleware (below) |

  ```php
  $resolver = new HandlerResolver($router, $responseFactory, $container, notFoundHandler: new MyNotFoundPage($twig));
  ```

  For the latter two, middleware sees `$request->getAttribute(MethodNotAllowed::class)`, whose
  `allowed` lists the path's methods, e.g. `['GET', 'PUT', 'HEAD']` — HEAD is included whenever GET
  is.
- **HEAD matches GET routes automatically.** A route declared for HEAD itself still wins.
- **A HEAD response never has a body,** whoever answers — a GET route, a HEAD or `any()` route, or
  the not-found and method-not-allowed handlers: it's dropped, keeping status and headers, as
  RFC 9110 requires. The request is never rewritten: filters, middleware and the handler all see
  HEAD, so a handler can skip building a body it won't send. A handler for several methods should
  therefore branch on the method that changes things — `if ($method === 'POST')`, not
  `if ($method === 'GET') ... else` — or a HEAD request takes the POST path.
- **OPTIONS is answered automatically.** A route declared for OPTIONS, or with `any()`, takes the
  request; otherwise the OPTIONS handler does, where other methods would get a 405. Filters run
  against the OPTIONS request itself.
- **Request attributes for custom filters:** PSR-7's own `$request->withAttribute($name, $value)`,
  read back by the filter with `$request->getAttribute($name)`.
- **Middleware for every request** is added to the resolver, not to the routes:

  ```php
  $resolver->addMiddleware(RequestLog::class, new Cors($config)); // identifiers or instances
  ```

  It wraps whatever answers — a route, or the not-found, method-not-allowed or OPTIONS handler —
  outside the route's own middleware, the first added outermost. It runs after matching, so it sees
  `Found::class` or `MethodNotAllowed::class` (neither for a 404). Group middleware, by contrast,
  only runs for the routes in the group.

  It's also where the OPTIONS answer is changed, e.g. for CORS, which needs middleware anyway to
  add `Access-Control-Allow-Origin` to actual responses. On a preflight that no route takes, the
  middleware sees the allowed methods, filters already applied, and decorates the default 200:

  ```php
  $allowed = $request->getAttribute(MethodNotAllowed::class);
  if ($request->getMethod() === 'OPTIONS' && $allowed instanceof MethodNotAllowed) {
      $response = $response->withHeader('Access-Control-Allow-Methods', implode(', ', $allowed->allowed));
  }
  ```
- **Middleware that must run before matching** — anything that changes the request or sets the
  attributes filters read — goes in a stack around the resolver; its last entry is a one-liner,
  `return $resolver->resolve($request)->handle($request);`.

### URL generation

`Http\UrlGenerator` builds URLs for named routes:

```php
use SilenZ\Segmatch\Http\UrlGenerator;

$urls = new UrlGenerator($router);
$urls->url('users.show', ['id' => 42]);                // "/api/users/42"
$urls->url('users.show', ['id' => 42, 'tab' => 'x']);  // "/api/users/42?tab=x" (extra params become the query)
$urls->url('users.show', []);                          // throws: missing parameter "id"
```

- **Every placeholder is required.** Values are `rawurlencode`d, so the generated URL matches its
  route again with the same parameters. A catch-all keeps its slashes:
  `['path' => 'docs/a b.pdf']` gives `/files/docs/a%20b.pdf`.
- **Empty values:** `{id}` and `{path+}` can't be empty. An empty `{path*}` drops its slash:
  `/assets`, not `/assets/`.
- **Values** may be strings, ints or `Stringable`s. Query values are anything `http_build_query()`
  takes.
- **Errors** throw `Exception\UrlGenerationException`: an unknown name, or a missing, empty or
  unsupported parameter.
- **Works from the cache.** Named routes keep their path in the metadata, so URLs can be generated
  without declaring the routes. The index of names is built on the first `url()` call.

Compile-time errors include duplicate route names, empty names or tags, invalid prefixes or
methods, and filter classes that don't implement `Http\Filter`.

### Declared routes, and generating OpenAPI

`Router::definitions()` returns the routes as declared — full paths and metadata, uncompiled, never
read from or written to the cache. It's for tooling that needs the declarations themselves, not for
matching requests:

```php
foreach ($router->definitions() as $definition) {
    $definition->path;     // "/api/users/{id}"
    $definition->metadata; // ['handler' => ..., 'name' => 'users.show', 'methods' => ['GET'], ...]
}
```

`OpenApi\PathsGenerator` builds the `paths` object of an OpenAPI document from exactly that:

```php
use SilenZ\Segmatch\OpenApi\PathsGenerator;

$paths = PathsGenerator::generate($router->definitions());
$document = ['openapi' => '3.1.0', 'info' => [...], ...$paths];
```

- **It covers only what segmatch knows:** paths, methods, path parameters, names (as
  `operationId`) and tags. Request/response bodies, security schemes, `info` and `servers` aren't
  its business — merge them into the document yourself, keyed off `operationId` or route name.
- **Every operation gets a placeholder `200` response**, since `responses` is a required field of an
  OpenAPI operation and segmatch has no notion of what a route responds with. Replace it yourself.
- **Catch-alls become a single `{name}` path parameter.** OpenAPI has no "rest of the path"
  placeholder, so a `{name*}`/`{name+}`'s actual multi-segment behavior isn't represented.
- **`any()` routes list every HTTP method OpenAPI supports**, since no methods were declared to
  narrow it down.

## Architecture

```
RouteDefinitions ──► TreeBuilder ──► Flattener ──► RouteCache ──► Matcher
 paths + metadata    tree + checks   tables        e.g. FileCache  static hash lookup, then tree loop + backtracking

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
