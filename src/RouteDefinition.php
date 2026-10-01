<?php

declare(strict_types=1);

namespace SilenZ\Segmatch;

use SilenZ\Segmatch\Exception\InvalidRouteException;
use SilenZ\Segmatch\Internal\PathParser;
use SilenZ\Segmatch\Internal\Segment;

/**
 * One route: a full path and opaque metadata.
 *
 *     new RouteDefinition('/users/{id}', ['handler' => 'users.show']);
 *
 * The path is parsed right away, so a malformed path throws where the route is declared. Conflicts
 * between routes are reported when they are compiled. The router never interprets the metadata; it
 * must survive the compiled PHP file, so it may only consist of scalars, null, enums and arrays of
 * those.
 */
final readonly class RouteDefinition
{
    /**
     * @internal the parsed path, for the compiler
     *
     * @var non-empty-list<Segment>
     */
    public array $segments;

    /**
     * @param string $path full route path starting with "/", e.g. "/users/{id}" or "/assets/{path+}"
     *
     * @throws InvalidRouteException when the path is malformed
     */
    public function __construct(
        public string $path,
        public mixed $metadata = null,
    ) {
        $this->segments = PathParser::parse($path);
    }
}
