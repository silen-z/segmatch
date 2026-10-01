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
     * @param list<GroupDefinition> $groups
     *
     * @return CompiledRoutes
     */
    public static function flatten(BuildNode $root, array $routes, array $groups): array
    {
        /** @var array<int, int> $scopeIds group index => scope id */
        $scopeIds = [];
        $compiledGroups = [];
        foreach ($groups as $index => $group) {
            if ($group->metadata === null) {
                continue;
            }

            $scopeIds[$index] = count($compiledGroups);
            $compiledGroups[] = $group->metadata;
        }

        return [
            'version' => Layout::FORMAT_VERSION,
            'nodes' => self::nodes($root, $scopeIds),
            'routes' => self::routes($routes),
            'groups' => $compiledGroups,
        ];
    }

    /**
     * Assigns node ids breadth-first (root = 0) and emits one compiled node per id.
     *
     * @param array<int, int> $scopeIds
     *
     * @return list<CompiledNode>
     */
    private static function nodes(BuildNode $root, array $scopeIds): array
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
                Layout::NODE_CATCH => $node->catchRoute ?? Layout::NONE,
                Layout::NODE_CATCH_MIN => $node->catchType === SegmentType::CatchAllOne ? 1 : 0,
                Layout::NODE_ROUTE => $node->route ?? Layout::NONE,
                Layout::NODE_SCOPE => $node->group !== null ? $scopeIds[$node->group] ?? Layout::NONE : Layout::NONE,
            ];
        }

        return $nodes;
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
