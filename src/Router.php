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
     * The routes as declared: full paths and metadata, uncompiled and never read from or written to
     * the cache. Calls {@see RouteTable::definitions()} every time, unlike {@see matcher()}; use it for
     * tooling that needs the declarations themselves, e.g. an index of routes by name, or generating
     * documentation, not for matching requests.
     *
     * @return iterable<int, RouteDefinition>
     */
    public function definitions(): iterable
    {
        return $this->routes->definitions();
    }

    /**
     * {@see RouteTable::metadata()}, loaded from the cache or compiled on first use like the routes.
     */
    public function tableMetadata(): mixed
    {
        return $this->matcher()->tableMetadata();
    }

    /**
     * {@see RouteTable::registry()} — never lazy, so unlike {@see tableMetadata()} this needs no
     * compiling or cache lookup.
     */
    public function registry(): InstanceRegistry
    {
        return $this->routes->registry();
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
