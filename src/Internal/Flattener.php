<?php

declare(strict_types=1);

namespace silenz\PhpRouter\Internal;

use silenz\PhpRouter\Compiler;

use function count;
use function spl_object_id;

/**
 * Turns the intermediate tree into the flat runtime tables described by {@see Layout}.
 *
 * @internal
 *
 * @psalm-import-type CompiledNode from Compiler
 * @psalm-import-type CompiledRoute from Compiler
 * @psalm-import-type CompiledRoutes from Compiler
 */
final class Flattener
{
    /**
     * @param list<RouteDefinition> $routes
     *
     * @return CompiledRoutes
     */
    public static function flatten(BuildNode $root, array $routes): array
    {
        // Must run before node ids are assigned: it prunes the tree.
        $static = [];
        foreach (StaticTable::extract($root, $routes) as $path => $ids) {
            $static[$path] = self::ids($ids);
        }

        return [
            'version' => Layout::FORMAT_VERSION,
            'static' => $static,
            'nodes' => self::nodes($root),
            'routes' => self::routes($routes),
        ];
    }

    /**
     * Assigns node ids breadth-first (root = 0) and emits one compiled node per id.
     *
     * @return list<CompiledNode>
     */
    private static function nodes(BuildNode $root): array
    {
        $queue = [$root];
        $ids = [spl_object_id($root) => 0];

        for ($i = 0; $i < count($queue); $i++) {
            $node = $queue[$i];
            $children = $node->static;
            if ($node->param !== null) {
                $children[] = $node->param;
            }

            foreach ($children as $child) {
                $ids[spl_object_id($child)] = count($queue);
                $queue[] = $child;
            }
        }

        $nodes = [];
        foreach ($queue as $node) {
            $static = [];
            foreach ($node->static as $segment => $child) {
                $static[$segment] = $ids[spl_object_id($child)];
            }

            $nodes[] = [
                Layout::NODE_STATIC => $static,
                Layout::NODE_PARAM => $node->param !== null ? $ids[spl_object_id($node->param)] : Layout::NONE,
                Layout::NODE_CATCH => self::ids($node->catchRoutes),
                Layout::NODE_CATCH_MIN => $node->catchType === SegmentType::CatchAllOne ? 1 : 0,
                Layout::NODE_ROUTE => self::ids($node->routes),
            ];
        }

        return $nodes;
    }

    /**
     * Route ids in their compact form: NONE, a single id (by far the common case), or a list when
     * several routes share a path. Fewer arrays make the cache file cheaper to load.
     *
     * @param list<int> $ids
     *
     * @return int|non-empty-list<int>
     */
    private static function ids(array $ids): int|array
    {
        if ($ids === []) {
            return Layout::NONE;
        }

        return count($ids) === 1 ? $ids[0] : $ids;
    }

    /**
     * Route ids are declaration order; parameter names are kept here so they stay out of traversal.
     *
     * @param list<RouteDefinition> $routes
     *
     * @return list<CompiledRoute>
     */
    private static function routes(array $routes): array
    {
        $compiled = [];
        foreach ($routes as $route) {
            $params = [];
            foreach ($route->segments as $segment) {
                if ($segment->type === SegmentType::Static) {
                    continue;
                }

                $params[] = $segment->value;
            }

            $compiled[] = [
                Layout::ROUTE_METADATA => $route->metadata,
                Layout::ROUTE_PARAMS => $params,
            ];
        }

        return $compiled;
    }
}
