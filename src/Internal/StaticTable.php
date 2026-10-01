<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Internal;

/**
 * Moves routes without parameters out of the tree into a table keyed by the full path, so the
 * matcher answers them with one hash lookup.
 *
 * This does not change matching results. A request equal to such a path would follow static edges
 * all the way down (static wins at every node) and stop on exactly those routes; and since their
 * node is only reachable through static edges, no other request can end there. Tree nodes that only
 * existed for these routes are pruned afterwards. If a guard rejects every route found here, the
 * matcher continues in the tree, exactly as it would backtrack from that node.
 *
 * @internal
 */
final class StaticTable
{
    /**
     * Mutates the tree: extracted routes are removed from it and empty subtrees are pruned.
     *
     * @param list<RouteDefinition> $routes
     *
     * @return array<array-key, non-empty-list<int>> full path => route ids in declaration order
     */
    public static function extract(BuildNode $root, array $routes): array
    {
        $static = [];
        foreach ($routes as $index => $route) {
            if (!self::isStatic($route)) {
                continue;
            }

            $static[$route->path][] = $index;
        }

        self::removeRoutes($root);
        self::prune($root);

        return $static;
    }

    private static function isStatic(RouteDefinition $route): bool
    {
        foreach ($route->segments as $segment) {
            if ($segment->type !== SegmentType::Static) {
                return false;
            }
        }

        return true;
    }

    /**
     * A node reached through static edges only can only carry routes without parameters, all of which
     * are now in the static table. Catch-alls hanging off such a node have a parameter and stay.
     */
    private static function removeRoutes(BuildNode $node): void
    {
        $node->routes = [];
        foreach ($node->static as $child) {
            self::removeRoutes($child);
        }
    }

    /**
     * Drops subtrees that no longer lead to any route.
     *
     * @return bool whether $node itself is now empty
     */
    private static function prune(BuildNode $node): bool
    {
        foreach ($node->static as $segment => $child) {
            if (!self::prune($child)) {
                continue;
            }

            unset($node->static[$segment]);
        }

        if ($node->param !== null && self::prune($node->param)) {
            $node->param = null;
        }

        return $node->static === [] && $node->param === null && $node->catchRoutes === [] && $node->routes === [];
    }
}
