<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Internal;

use SilenZ\Segmatch\Exception\InvalidRouteException;
use SilenZ\Segmatch\RouteDefinition;

use function array_slice;
use function count;
use function sprintf;

/**
 * Builds the compiler's intermediate tree from route declarations.
 *
 * Several routes may share a path; they are kept in declaration order and a match-time filter
 * chooses between them. The only conflict left is mixing `{name*}` and `{name+}` on one node.
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

        if (!$last->type->isCatchAll()) {
            self::walk($root, $segments)->routes[] = $index;

            return;
        }

        // A catch-all is terminal, so it is stored on the node it hangs off instead of getting a node.
        $node = self::walk($root, array_slice($segments, offset: 0, length: -1));
        if ($node->catchType !== null && $node->catchType !== $last->type) {
            throw new InvalidRouteException(sprintf(
                'Catch-all route "%s" conflicts with catch-all route "%s"; routes sharing a catch-all must all use either {name*} or {name+}.',
                $route->path,
                $routes[$node->catchRoutes[0]]->path,
            ));
        }

        $node->catchRoutes[] = $index;
        $node->catchType = $last->type;
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
