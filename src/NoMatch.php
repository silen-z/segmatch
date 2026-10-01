<?php

declare(strict_types=1);

namespace SilenZ\Segmatch;

/**
 * Result of a {@see Matcher::match()} that found no acceptable route.
 *
 * When `$rejected` is empty, no route has this path (404). Otherwise routes with this path exist but
 * the guard rejected all of them; a caller whose guard checks the HTTP method can answer 405 and
 * build the `Allow` header from them.
 */
final readonly class NoMatch
{
    /**
     * @param list<RouteMatch> $rejected the routes the guard rejected (metadata and parameters), in the
     *     order they were tried
     */
    public function __construct(
        public array $rejected = [],
    ) {}
}
