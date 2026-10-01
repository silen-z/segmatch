<?php

declare(strict_types=1);

namespace silenz\PhpRouter;

use Closure;
use silenz\PhpRouter\Cache\RouteCache;
use silenz\PhpRouter\Internal\Layout;

/**
 * Entry point: declares the routes lazily, caches them and matches paths.
 *
 * Like FastRoute's cached dispatcher, the route definition callback only runs when the cache has no
 * entry for the key. Nothing is invalidated automatically, so anything that changes which routes get
 * compiled (a deploy, configuration deciding which routes exist) must change the cache key, e.g. by
 * putting an application version or a configuration hash into it. Pass `null` as the cache to compile
 * on every request instead, e.g. in development.
 *
 * @psalm-import-type CompiledRoutes from Compiler
 */
final class Router
{
    private ?Matcher $matcher = null;

    /**
     * @param Closure(RouteSet): void $routes declares the routes; only called when there is no usable cache entry
     * @param ?RouteCache $cache where compiled routes are kept; null disables caching
     * @param string $cacheKey identifies these routes in the cache
     */
    public function __construct(
        private readonly Closure $routes,
        private readonly ?RouteCache $cache = null,
        private readonly string $cacheKey = 'routes',
    ) {}

    /**
     * @param string $path request path without query string, starting with "/"
     * @param null|Closure(mixed, array<string, string>): bool $guard see {@see Matcher::match()}
     */
    public function match(string $path, ?Closure $guard = null): RouteMatch|NoMatch
    {
        return $this->matcher()->match($path, $guard);
    }

    /**
     * The matcher for the routes, loaded from the cache or compiled on first use.
     */
    public function matcher(): Matcher
    {
        return $this->matcher ??= new Matcher($this->load());
    }

    /**
     * @return CompiledRoutes
     */
    private function load(): array
    {
        $cached = $this->cache?->get($this->cacheKey);
        if ($cached !== null && ($cached['version'] ?? null) === Layout::FORMAT_VERSION) {
            /** @var CompiledRoutes $cached */
            return $cached;
        }

        $routes = new RouteSet();
        ($this->routes)($routes);
        $compiled = Compiler::compile($routes);
        $this->cache?->set($this->cacheKey, $compiled);

        return $compiled;
    }
}
