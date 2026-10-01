<?php

declare(strict_types=1);

namespace silenz\PhpRouter\Internal;

use silenz\PhpRouter\Compiler;

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
 *
 * @psalm-import-type CompiledStatic from Compiler
 */
final class StaticTable
{
    /**
     * Mutates the tree: extracted routes are removed from it and empty subtrees are pruned.
     *
     * @param list<RouteDefinition> $routes
     * @param array<int, int> $scopeIds group index => scope id
     *
     * @return array<array-key, CompiledStatic>
     */
    public static function extract(BuildNode $root, array $routes, array $scopeIds): array
    {
        $static = [];
        /** @var array<int, true> $extracted */
        $extracted = [];
        foreach ($routes as $index => $route) {
            if (!self::isStatic($route)) {
                continue;
            }

            // Ownership validation guarantees the declared groups are exactly the groups on the path.
            $scopes = [];
            foreach ($route->groups as $group) {
                $scope = $scopeIds[$group] ?? null;
                if ($scope !== null) {
                    $scopes[] = $scope;
                }
            }

            $static[$route->path] = [
                Layout::STATIC_ROUTE => $index,
                Layout::STATIC_SCOPES => $scopes,
            ];
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
