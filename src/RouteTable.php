<?php

declare(strict_types=1);

namespace SilenZ\Segmatch;

/**
 * Where {@see Router} gets its routes from: a cache key and the route definitions behind it, as one
 * unit. Extend it (or use {@see CallableRouteTable}, which is exactly a callable and a key) for
 * anything more involved than a plain list.
 *
 * Like FastRoute's cached dispatcher, {@see definitions()} only runs when the cache has no entry for
 * {@see cacheKey()}. The two live on one object, instead of being passed to `Router` as two separate
 * arguments, so they can't drift apart the way two independent values could: whatever decides
 * `cacheKey()` is right there next to whatever decides `definitions()`.
 *
 * Nothing is invalidated automatically, so anything that changes which routes get compiled (a deploy,
 * configuration deciding which routes exist) must change what `cacheKey()` returns, e.g. by including
 * an application version or a configuration hash in it.
 */
abstract class RouteTable
{
    /**
     * Identifies these routes in the cache, or `null` to never cache them — compiling on every
     * request instead, e.g. in development, regardless of whether `Router` was given a cache. Called
     * on every request Router answers (cache hit or not), so it must be cheap — never do the work
     * {@see definitions()} does to compute it.
     */
    abstract public function cacheKey(): ?string;

    /**
     * The routes themselves (an array or a generator). Only called when the cache has no entry for
     * {@see cacheKey()}, so, unlike it, this may be as expensive as declaring the routes needs to be.
     *
     * @return iterable<int, RouteDefinition>
     */
    abstract public function definitions(): iterable;
}
