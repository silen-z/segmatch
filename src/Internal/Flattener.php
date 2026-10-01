<?php

declare(strict_types=1);

namespace silenz\PhpRouter\Internal;

use silenz\PhpRouter\Compiler;

use function count;
use function spl_object_id;

/**
 * Turns the intermediate tree into the flat runtime tables described by {@see Layout}.
 *
 * Every table is a sparse map keyed by node or route id, so things a node or route doesn't have
 * cost nothing in the cache file. That keeps the number of arrays, which dominates the cost of
 * loading the file, close to the number of things that actually exist.
 *
 * @internal
 *
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

        $compiled = [
            'version' => Layout::FORMAT_VERSION,
            'static' => $static,
            'edges' => [],
            'param' => [],
            'catch' => [],
            'catchRequired' => [],
            'routes' => [],
            'metadata' => [],
            'paramNames' => [],
        ];

        [$nodes, $ids] = self::number($root);
        foreach ($nodes as $id => $node) {
            $edges = [];
            foreach ($node->static as $segment => $child) {
                $edges[$segment] = $ids[spl_object_id($child)];
            }

            if ($edges !== []) {
                $compiled['edges'][$id] = $edges;
            }

            if ($node->param !== null) {
                $compiled['param'][$id] = $ids[spl_object_id($node->param)];
            }

            if ($node->catchRoutes !== []) {
                $compiled['catch'][$id] = self::ids($node->catchRoutes);
                if ($node->catchType === SegmentType::CatchAllOne) {
                    $compiled['catchRequired'][$id] = true;
                }
            }

            if ($node->routes !== []) {
                $compiled['routes'][$id] = self::ids($node->routes);
            }
        }

        foreach ($routes as $id => $route) {
            $compiled['metadata'][] = $route->metadata;

            $names = [];
            foreach ($route->segments as $segment) {
                if ($segment->type === SegmentType::Static) {
                    continue;
                }

                $names[] = $segment->value;
            }

            if ($names !== []) {
                $compiled['paramNames'][$id] = $names;
            }
        }

        return $compiled;
    }

    /**
     * Assigns node ids breadth-first, root = 0.
     *
     * @return array{list<BuildNode>, array<int, int>} the nodes in id order, and object id => node id
     */
    private static function number(BuildNode $root): array
    {
        $nodes = [$root];
        $ids = [spl_object_id($root) => 0];

        for ($i = 0; $i < count($nodes); $i++) {
            $node = $nodes[$i];
            $children = $node->static;
            if ($node->param !== null) {
                $children[] = $node->param;
            }

            foreach ($children as $child) {
                $ids[spl_object_id($child)] = count($nodes);
                $nodes[] = $child;
            }
        }

        return [$nodes, $ids];
    }

    /**
     * Route ids in their compact form: a single id (by far the common case), or a list when several
     * routes share a path.
     *
     * @param non-empty-list<int> $ids
     *
     * @return int|non-empty-list<int>
     */
    private static function ids(array $ids): int|array
    {
        return count($ids) === 1 ? $ids[0] : $ids;
    }
}
