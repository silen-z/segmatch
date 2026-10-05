<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Internal;

use SilenZ\Segmatch\Compiler;
use SilenZ\Segmatch\RouteDefinition;

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
 * @psalm-import-type RouteIds from Compiler
 */
final class Flattener
{
    /**
     * @param list<RouteDefinition> $routes
     * @param mixed $tableMetadata the table's own metadata, {@see \SilenZ\Segmatch\RouteTable::metadata()}
     *
     * @return CompiledRoutes
     */
    public static function flatten(BuildNode $root, array $routes, mixed $tableMetadata): array
    {
        // Must run before node ids are assigned: it prunes the tree.
        $static = [];
        foreach (StaticTable::extract($root, $routes) as $path => $ids) {
            $static[$path] = self::ids($ids);
        }

        $compiled = [
            'version' => Layout::FORMAT_VERSION,
            'table' => $tableMetadata,
            'static' => $static,
            'edges' => [],
            'param' => [],
            'catch' => [],
            'routes' => [],
            'metadata' => [],
            'paramNames' => [],
        ];

        [$nodes, $ids] = self::number($root);
        foreach ($nodes as $id => $node) {
            $hasParam = $node->param !== null;
            $hasCatch = $node->catchRoutes !== [];

            // A static match on this node may still need to fall back to its {param} or catch-all
            // edge, so the matcher must push a backtrack frame. Flagging that in the sign of the
            // child id itself (see Layout) spares it a lookup into a separate table for every segment.
            $edges = [];
            foreach ($node->static as $segment => $child) {
                $childId = $ids[spl_object_id($child)];
                $edges[$segment] = $hasParam || $hasCatch ? self::encode($childId) : $childId;
            }

            if ($edges !== []) {
                $compiled['edges'][$id] = $edges;
            }

            if ($node->param !== null) {
                $childId = $ids[spl_object_id($node->param)];
                // Same trick: a miss past the {param} child may still fall back to this node's
                // catch-all edge.
                $compiled['param'][$id] = $hasCatch ? self::encode($childId) : $childId;
            }

            if ($node->catchRoutes !== []) {
                $compiled['catch'][$id] = [$node->catchType === SegmentType::CatchAllOne, ...$node->catchRoutes];
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
     * @return RouteIds
     */
    private static function ids(array $ids): int|array
    {
        return count($ids) === 1 ? $ids[0] : $ids;
    }

    /**
     * Flags a child node id as "needs a backtrack frame" by storing it negative. Child ids are
     * always >= 1 (node 0 is the root and never a child), so `-id - 1` is always <= -2 and never
     * collides with {@see Layout::NONE} (-1).
     */
    private static function encode(int $childId): int
    {
        return -$childId - 1;
    }
}
