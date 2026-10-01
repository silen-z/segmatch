<?php

declare(strict_types=1);

namespace silenz\PhpRouter\Internal;

use silenz\PhpRouter\Exception\InvalidRouteException;

use function array_slice;
use function count;
use function sprintf;

/**
 * Builds the compiler's intermediate tree from route declarations and rejects conflicting routes.
 *
 * Groups do not appear in the tree: their metadata belongs to the routes declared inside them.
 *
 * @internal
 */
final class TreeBuilder
{
    /**
     * @param list<RouteDefinition> $routes
     *
     * @throws InvalidRouteException
     */
    public static function build(array $routes): BuildNode
    {
        $root = new BuildNode();
        foreach ($routes as $index => $route) {
            self::insertRoute($root, $route, $index, $routes);
        }

        return $root;
    }

    /**
     * @param list<RouteDefinition> $routes
     *
     * @throws InvalidRouteException
     */
    private static function insertRoute(BuildNode $root, RouteDefinition $route, int $index, array $routes): void
    {
        $segments = $route->segments;
        $last = $segments[count($segments) - 1];

        if ($last->type->isCatchAll()) {
            self::insertCatchAllRoute($root, $route, $last, $index, $routes);

            return;
        }

        $node = self::walk($root, $segments);
        if ($node->route !== null) {
            throw new InvalidRouteException(sprintf(
                'Route "%s" conflicts with route "%s".',
                $route->path,
                $routes[$node->route]->path,
            ));
        }

        $node->route = $index;
    }

    /**
     * A catch-all is terminal, so it is stored on the node it hangs off instead of getting a node.
     *
     * @param list<RouteDefinition> $routes
     *
     * @throws InvalidRouteException
     */
    private static function insertCatchAllRoute(
        BuildNode $root,
        RouteDefinition $route,
        Segment $catchAll,
        int $index,
        array $routes,
    ): void {
        $node = self::walk($root, array_slice($route->segments, offset: 0, length: -1));
        if ($node->catchRoute !== null) {
            throw new InvalidRouteException(sprintf(
                'Route "%s" conflicts with catch-all route "%s".',
                $route->path,
                $routes[$node->catchRoute]->path,
            ));
        }

        $node->catchRoute = $index;
        $node->catchType = $catchAll->type;
    }

    /**
     * Follows (and creates) the static and parameter edges for the given segments.
     *
     * @param list<Segment> $segments
     *
     * @return BuildNode the node reached
     */
    private static function walk(BuildNode $root, array $segments): BuildNode
    {
        $node = $root;
        foreach ($segments as $segment) {
            $node = $segment->type === SegmentType::Static
                ? self::staticChild($node, $segment->value)
                : self::paramChild($node);
        }

        return $node;
    }

    private static function staticChild(BuildNode $node, string $segment): BuildNode
    {
        $node->static[$segment] ??= new BuildNode();

        return $node->static[$segment];
    }

    private static function paramChild(BuildNode $node): BuildNode
    {
        $node->param ??= new BuildNode();

        return $node->param;
    }
}
