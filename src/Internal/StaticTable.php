<?php

declare(strict_types=1);

namespace silenz\PhpRouter\Internal;

/**
 * Moves routes without parameters out of the tree into a table keyed by the full path, so the
 * matcher answers them with one hash lookup.
 *
 * This does not change matching results. A request equal to such a path would follow static edges
 * all the way down (static wins at every node) and stop on exactly that route; and since a static
 * route's node is only reachable through static edges, no other request can end there. Tree nodes
 * that only existed for these routes are pruned afterwards.
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
     * @return array<array-key, int> full path => route id
     */
    public static function extract(BuildNode $root, array $routes): array
    {
        $static = [];
        /** @var array<int, true> $extracted */
        $extracted = [];
        foreach ($routes as $index => $route) {
            if (!self::isStatic($route)) {
                continue;
            }

            $static[$route->path] = $index;
            $extracted[$index] = true;
        }

        self::removeRoutes($root, $extracted);
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
     * Static routes are only reachable through static edges, so only those need to be visited.
     *
     * @param array<int, true> $extracted route indexes moved to the static table
     */
    private static function removeRoutes(BuildNode $node, array $extracted): void
    {
        foreach ($node->static as $child) {
            self::removeRoutes($child, $extracted);
        }

        if ($node->route !== null && ($extracted[$node->route] ?? false)) {
            $node->route = null;
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

        return $node->static === [] && $node->param === null && $node->catchRoute === null && $node->route === null;
    }
}
