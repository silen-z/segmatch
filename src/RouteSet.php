<?php

declare(strict_types=1);

namespace SilenZ\Segmatch;

use SilenZ\Segmatch\Exception\InvalidRouteException;
use SilenZ\Segmatch\Internal\PathParser;
use SilenZ\Segmatch\Internal\RouteDefinition;

/**
 * The routes to compile: full paths with opaque metadata.
 *
 * This is deliberately the whole declaration API. Prefixes, groups, HTTP method helpers and the like
 * belong to a higher-level layer that resolves them into full paths and merged metadata before
 * calling {@see add()}.
 *
 * The router never interprets metadata. It must survive the compiled PHP file, so it may only
 * consist of scalars, null, enums and arrays of those.
 */
final class RouteSet
{
    /** @var list<RouteDefinition> */
    private array $routes = [];

    /**
     * @param string $path full route path starting with "/", e.g. "/users/{id}" or "/assets/{path+}"
     *
     * @throws InvalidRouteException when the path is malformed; conflicts are reported by {@see Compiler}
     */
    public function add(string $path, mixed $metadata): void
    {
        $this->routes[] = new RouteDefinition($path, PathParser::parse($path), $metadata);
    }

    /**
     * @internal
     *
     * @return list<RouteDefinition>
     */
    public function routes(): array
    {
        return $this->routes;
    }
}
