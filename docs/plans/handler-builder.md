# Rename Dispatcher to HandlerBuilder; expose a handlerFor() builder; fold OPTIONS into it

## Context

`Http\Dispatcher` currently has three public entry points: `match()` (pure FastRoute-style
matching), `handle()` (matches, then builds and runs a `Relay` pipeline, returning a
`ResponseInterface` directly), and `allowedMethods()` (a separate computation for OPTIONS/CORS
responses, used nowhere in this repo outside tests).

Across this conversation we landed on a cleaner shape:

- `handle()` should delegate to a new method that *returns* a `RequestHandlerInterface` (a `Relay`,
  or a trivial fixed-response handler) instead of invoking it inline. This makes "build the right
  handler for this request" a first-class, reusable operation — that's the `HandlerBuilder` name.
- The route's own middleware/handler still run as one single `Relay` chain — no nested `Relay`
  inside another. This was a hard requirement: Relay's `Runner` iterates a queue fixed at
  construction (`vendor/relay/relay/src/Runner.php`) with no hook for a middleware to splice more
  entries into it, so the only way to get one real chain is to resolve the match *before* building
  the `Relay`, then hand it one flat array.
- `allowedMethods()` goes away entirely. It turns out it was never doing anything `match()` doesn't
  already do: `Matcher::matchAll()` is *literally* `match()` called with an always-rejecting guard
  (`src/Matcher.php:280`), so a real `OPTIONS` request run through the existing `match()` already
  produces a `MethodNotAllowed` with the correct, guard-aware `allowed` list — no second computation
  needed. The only new logic is a one-line status-code branch: 200 instead of 405 when the request
  method is `OPTIONS`.
- Per discussion, forcing this into a hardcoded `MiddlewareInterface` class added ceremony for no
  benefit (nothing ever calls `$next` on it, nothing ever reorders or swaps it) — it's a plain `if`
  in `handlerFor()`, the same shape as the existing 404 branch.
- Found's route metadata (tags, name, methods, middleware) stays available to the route's own
  middleware via a `$request->getAttribute(Found::class)`, not just the already-flattened param
  attributes — this is the "users interact with it via middleware" piece: a route-level middleware
  (e.g. a tag-based auth check) can read the full match off the request instead of needing a
  separate call.

Decided: rename `Dispatcher` → `HandlerBuilder`, and default the automatic `OPTIONS` response to
`200` with an `Allow` header.

## Target shape of `src/Http/HandlerBuilder.php` (renamed from `Dispatcher.php`)

```php
final readonly class HandlerBuilder implements RequestHandlerInterface
{
    public function __construct(
        private Router $router,
        private ?ContainerInterface $container = null,
        private ?ResponseFactoryInterface $responseFactory = null,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handlerFor($request)->handle($request);
    }

    public function handlerFor(ServerRequestInterface $request): RequestHandlerInterface
    {
        $result = $this->match($request);

        if ($result instanceof NotFound) {
            return $this->fixedResponse(404);
        }

        if ($result instanceof MethodNotAllowed) {
            $status = strtoupper($request->getMethod()) === 'OPTIONS' ? 200 : 405;
            return $this->fixedResponse($status, ['Allow' => implode(', ', $result->allowed)]);
        }

        return new Relay(
            [self::routeContext($result), ...$result->middleware, $result->handler],
            $this->resolve(...),
        );
    }

    public function match(ServerRequestInterface $request): Found|MethodNotAllowed|NotFound
    {
        // unchanged body
    }

    // allowedMethods() removed entirely.

    private static function routeContext(Found $result): callable
    {
        return static function (ServerRequestInterface $request, RequestHandlerInterface $next) use ($result): ResponseInterface {
            foreach ($result->params as $name => $value) {
                $request = $request->withAttribute($name, $value);
            }
            return $next->handle($request->withAttribute(Found::class, $result));
        };
    }

    private function fixedResponse(int $status, array $headers = []): RequestHandlerInterface
    {
        $response = $this->responseFactory()->createResponse($status);
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return new class($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    // resolve(), responseFactory(), allowed(), normalizedPath(), guardsAccept(): unchanged bodies,
    // just update the LogicException message in responseFactory() from "Dispatcher::handle()" to
    // "HandlerBuilder::handle()".
}
```

Notes:
- `match()` and its private helpers (`allowed()`, `guardsAccept()`, `normalizedPath()`, `resolve()`)
  are untouched — only `allowedMethods()` is deleted and `handle()`/`handlerFor()` are new/changed.
- The anonymous-class-implementing-an-interface pattern for `fixedResponse()` already exists in this
  codebase's tests (e.g. `tests/RouterTest.php:23`, `tests/Http/UrlGeneratorTest.php:89`), so this
  isn't a new idiom.
- `Found::class` is the request attribute key (standard PSR-7 convention: key by the FQCN to avoid
  collisions), carrying the full `Found` (handler, params, middleware, name, methods, tags) to any
  middleware in the route's own chain.

## Files to change

1. **`src/Http/Dispatcher.php` → `src/Http/HandlerBuilder.php`**: rewrite per above; update the
   class-level docblock (currently describes `dispatch`/`match`/`handle` as FastRoute-style
   matching + PSR-15 running) to describe the builder role and the `Found::class` attribute.
2. **`tests/Http/DispatcherTest.php` → `tests/Http/HandlerBuilderTest.php`**: rename class, update
   `Dispatcher` → `HandlerBuilder` references. Drop `testAllowedMethods`,
   `testAllowedMethodsComeFromRoutesRejectedOnlyForTheirMethod`, and
   `testRouteRejectedForAnotherReasonDoesNotCountAsAllowed` (`allowedMethods()`-specific) — their
   underlying `MethodNotAllowed`/guard-rejection assertions already exist via `match()` elsewhere in
   this file; only the OPTIONS-response behavior they also covered moves to `HandleTest.php`. All
   other tests are `match()`-based and unaffected beyond the rename.
3. **`tests/Http/HandleTest.php`**: update `Dispatcher` → `HandlerBuilder`. Add:
   - An OPTIONS test per removed `testAllowedMethods*` case, now asserting
     `$handlerBuilder->handle(new ServerRequest('OPTIONS', $path))` returns `200` with the right
     `Allow` header (covering: plain path, HEAD-implied-by-GET, guard-rejected routes via
     `FeatureGuard`, numeric-guard-rejected routes).
   - A test that `handlerFor()` returns a `RequestHandlerInterface` for `Found`/`NotFound`/
     `MethodNotAllowed`, and that calling `handle()` on it matches what `handle()` on the builder
     itself returns.
   - A test that route-level middleware can read `$request->getAttribute(Found::class)` and see the
     match's `name`/`tags`/`methods` (extend `TagMiddleware` fixture or add a small one).
4. **`tests/Http/UrlGeneratorTest.php`**: update the `Dispatcher` import/instantiation to
   `HandlerBuilder` (`match()` usage is unchanged).
5. **`tests/Http/Fixtures/ShowHandler.php`**: update the docblock reference from
   `\SilenZ\Segmatch\Http\Dispatcher::handle()` to `\SilenZ\Segmatch\Http\HandlerBuilder::handle()`.
6. **`README.md`**: rename `Http\Dispatcher` → `Http\HandlerBuilder` throughout. In the
   "Dispatching"/"Handling: a PSR-15 stack via Relay" sections:
   - Document `handlerFor()` as the builder method, `handle()` as the one-line PSR-15 convenience
     over it.
   - Remove the `allowedMethods()` bullet/example; replace with: OPTIONS is now handled
     automatically by `handle()`/`handlerFor()` — a `MethodNotAllowed` on an `OPTIONS` request
     becomes a `200` with the computed `Allow` header instead of `405`, with no separate method to
     call.
   - Document the `Found::class` request attribute as the way a route's own middleware introspects
     the match (tags, name, methods) without a separate call, alongside the existing per-param
     attributes.
   - Leave `match()`'s own documentation and example (the `match (true) { ... }` snippet) unchanged
     — it's untouched by this refactor and remains the bring-your-own-pipeline entry point.

Not in scope: `Router`, `Matcher`, `Methods`, `Found`/`NotFound`/`MethodNotAllowed`, `Router::matchAll()`
— none of these change.

## Verification

- `vendor/bin/phpunit` (or whatever the project's test command is — check `composer.json`'s
  `scripts` section) should pass with the renamed/new tests above.
- Spot-check by hand: a route with `->middleware(['auth'])` and a quick fixture middleware that does
  `$request->getAttribute(Found::class)?->tags` confirms the attribute is visible inside the route's
  own chain, not just to the terminal handler.
- Confirm `composer.json`'s version (`0.1.0`, pre-1.0) needs no special handling — this is a breaking
  rename but the package hasn't reached 1.0 yet, so no migration shim is planned unless you want one.
