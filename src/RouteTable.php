<?php

declare(strict_types=1);

namespace SilenZ\Segmatch;

use Closure;

use function is_callable;

/**
 * Where {@see Router} gets its routes from: a cache key, the route definitions behind it and the
 * table's own metadata, as one unit.
 *
 *     new RouteTable(static fn(): array => [new RouteDefinition('/', 'home')], 'routes-' . APP_VERSION);
 *
 * Like FastRoute's cached dispatcher, the definitions only need producing when the cache has no entry
 * for {@see cacheKey()}: given as a callable, they're only declared then, on every call to
 * {@see definitions()}. The key lives on the same object, instead of being passed to `Router` as a
 * separate argument, so the two can't drift apart the way two independent values could: whatever
 * decides the key is right there next to whatever decides the definitions.
 *
 * Nothing is invalidated automatically, so anything that changes which routes get compiled (a deploy,
 * configuration deciding which routes exist) must change the cache key, e.g. by including an
 * application version or a configuration hash in it.
 */
final readonly class RouteTable
{
    /** @var (Closure(): iterable<int, RouteDefinition>)|iterable<int, RouteDefinition> */
    private Closure|iterable $definitions;

    /**
     * @param (callable(): iterable<int, RouteDefinition>)|iterable<int, RouteDefinition> $definitions
     *     the routes, in declaration order: a callable (a closure, an invokable object, a function or
     *     method name) is only called when they're needed, i.e. on a cache miss, which is what keeps
     *     declaring them out of a warm request; an array is used as given. Give a generator as a
     *     callable producing it, since one can only be iterated once.
     * @param ?string $cacheKey identifies these routes in the cache, or `null` (the default) to never
     *     cache them — compiling on every request instead, e.g. in development, regardless of whether
     *     `Router` was given a cache. A plain value, since it's read on every request
     * @param mixed $metadata metadata of these routes as a whole rather than of any one route, e.g.
     *     what applies to every request whether a route matches or not: plain data like a route's own,
     *     cached with the routes, or a closure producing it, called like the definitions' only on a
     *     cache miss. Only a `Closure` counts as lazy here, not any callable: plain metadata like
     *     `'trim'` or `['Foo', 'bar']` would pass for one. Read back with
     *     {@see Matcher::tableMetadata()} or {@see Router::tableMetadata()}
     */
    public function __construct(
        callable|iterable $definitions,
        private ?string $cacheKey = null,
        private mixed $metadata = null,
    ) {
        // A list of RouteDefinitions is never callable, so a callable is always the lazy form.
        $this->definitions = is_callable($definitions) ? $definitions(...) : $definitions;
    }

    public function cacheKey(): ?string
    {
        return $this->cacheKey;
    }

    /**
     * The routes themselves, produced anew by every call when given as a callable.
     *
     * @return iterable<int, RouteDefinition>
     */
    public function definitions(): iterable
    {
        return $this->definitions instanceof Closure ? ($this->definitions)() : $this->definitions;
    }

    /**
     * The table's own metadata, produced anew by every call when given as a closure; `null` by default.
     */
    public function metadata(): mixed
    {
        return $this->metadata instanceof Closure ? ($this->metadata)() : $this->metadata;
    }
}
