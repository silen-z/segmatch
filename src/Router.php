<?php

declare(strict_types=1);

namespace SilenZ\Segmatch;

use SilenZ\Segmatch\Cache\RouteCache;

/**
 * Entry point: declares the routes lazily, caches them and matches paths.
 *
 * Like FastRoute's cached dispatcher, {@see RouteTable::definitions()} only runs when the cache has no
 * entry for {@see RouteTable::cacheKey()}. Nothing is invalidated automatically, so anything that
 * changes which routes get compiled (a deploy, configuration deciding which routes exist) must change
 * what `cacheKey()` returns, e.g. by putting an application version or a configuration hash into it.
 * A `cacheKey()` of `null`, or no `$cache` at all, compiles on every request instead.
 *
 * @psalm-import-type CompiledRoutes from Compiler
 */
final class Router
{
    private ?Matcher $matcher = null;

    public function __construct(
        private readonly RouteTable $routes,
        private readonly ?RouteCache $cache = null,
    ) {}

    /**
     * @param string $path request path without query string, starting with "/"
     * @param null|callable(RouteMatch): bool $filter see {@see Matcher::match()}
     */
    public function match(string $path, ?callable $filter = null): RouteMatch|NoMatch
    {
        return $this->matcher()->match($path, $filter);
    }

    /**
     * The metadata of the route table as a whole, as {@see RouteTable::metadata()} gave it when the
     * routes were compiled — from the cache like everything else, so without declaring the routes
     * again. Never returned by matching: it belongs to no route.
     */
    public function metadata(): mixed
    {
        return $this->matcher()->metadata();
    }

    /**
     * The metadata of every route, by route id (declaration order), e.g. for building an index of
     * the routes by name.
     *
     * @return list<mixed>
     */
    public function routes(): array
    {
        return $this->matcher()->routeMetadata();
    }

    /**
     * The {@see RouteTable} this router was built from: its declarations uncompiled, its registry, and
     * its cache key, none of which need compiling or a cache lookup — unlike {@see matcher()}'s own
     * {@see Matcher::metadata()}, read from the cache on a hit like the routes themselves.
     */
    public function table(): RouteTable
    {
        return $this->routes;
    }

    /**
     * The matcher for the routes, loaded from the cache or compiled on first use.
     */
    private function matcher(): Matcher
    {
        return $this->matcher ??= new Matcher($this->load());
    }

    /**
     * @return CompiledRoutes
     */
    private function load(): array
    {
        $key = $this->routes->cacheKey();
        if ($this->cache === null || $key === null) {
            return Compiler::compile($this->routes);
        }

        $cached = $this->cache->get($key);
        if ($cached !== null && ($cached['version'] ?? null) === Compiler::FORMAT_VERSION) {
            /** @var CompiledRoutes $cached */
            return $cached;
        }

        $compiled = Compiler::compile($this->routes);
        $this->cache->set($key, $compiled);

        return $compiled;
    }
}
