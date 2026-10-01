<?php

declare(strict_types=1);

namespace silenz\PhpRouter\Internal;

use silenz\PhpRouter\Exception\InvalidRouteException;

use function array_slice;
use function count;
use function in_array;
use function sprintf;

/**
 * Builds the compiler's intermediate tree from declarations and validates it.
 *
 * @internal
 */
final class TreeBuilder
{
    /**
     * @param list<RouteDefinition> $routes
     * @param list<GroupDefinition> $groups
     *
     * @throws InvalidRouteException
     */
    public static function build(array $routes, array $groups): BuildNode
    {
        $root = new BuildNode();

        /** @var array<int, list<BuildNode>> $groupPaths */
        $groupPaths = [];
        foreach ($groups as $index => $group) {
            $groupPaths[$index] = self::insertGroup($root, $group, $index, $groups);
        }

        /** @var array<int, list<BuildNode>> $routePaths */
        $routePaths = [];
        foreach ($routes as $index => $route) {
            $routePaths[$index] = self::insertRoute($root, $route, $index, $routes);
        }

        // Ownership can only be checked once every group is in place, because a group may be declared
        // after the routes or groups that lie below its prefix.
        foreach ($groups as $index => $group) {
            self::assertOwnership(
                $groupPaths[$index],
                $group->parents,
                $groups,
                sprintf('Group prefix "%s"', $group->prefix),
            );
        }

        foreach ($routes as $index => $route) {
            self::assertOwnership($routePaths[$index], $route->groups, $groups, sprintf('Route "%s"', $route->path));
        }

        return $root;
    }

    /**
     * @param list<GroupDefinition> $groups
     *
     * @return list<BuildNode> the nodes above the group's own node
     *
     * @throws InvalidRouteException
     */
    private static function insertGroup(BuildNode $root, GroupDefinition $group, int $index, array $groups): array
    {
        $path = self::walk($root, $group->segments);
        $node = $path[count($path) - 1];
        if ($node->group !== null) {
            throw new InvalidRouteException(sprintf(
                'Group prefix "%s" conflicts with group prefix "%s".',
                $group->prefix,
                $groups[$node->group]->prefix,
            ));
        }

        $node->group = $index;

        return array_slice($path, offset: 0, length: -1);
    }

    /**
     * @param list<RouteDefinition> $routes
     *
     * @return list<BuildNode> every node the matcher enters on the way to this route
     *
     * @throws InvalidRouteException
     */
    private static function insertRoute(BuildNode $root, RouteDefinition $route, int $index, array $routes): array
    {
        $segments = $route->segments;
        $last = $segments[count($segments) - 1];

        if ($last->type->isCatchAll()) {
            return self::insertCatchAllRoute($root, $route, $last, $index, $routes);
        }

        $path = self::walk($root, $segments);
        $node = $path[count($path) - 1];
        if ($node->route !== null) {
            throw new InvalidRouteException(sprintf(
                'Route "%s" conflicts with route "%s".',
                $route->path,
                $routes[$node->route]->path,
            ));
        }

        $node->route = $index;

        return $path;
    }

    /**
     * A catch-all is terminal, so it is stored on the node it hangs off instead of getting a node.
     *
     * @param list<RouteDefinition> $routes
     *
     * @return list<BuildNode>
     *
     * @throws InvalidRouteException
     */
    private static function insertCatchAllRoute(
        BuildNode $root,
        RouteDefinition $route,
        Segment $catchAll,
        int $index,
        array $routes,
    ): array {
        $path = self::walk($root, array_slice($route->segments, offset: 0, length: -1));
        $node = $path[count($path) - 1];
        if ($node->catchRoute !== null) {
            throw new InvalidRouteException(sprintf(
                'Route "%s" conflicts with catch-all route "%s".',
                $route->path,
                $routes[$node->catchRoute]->path,
            ));
        }

        $node->catchRoute = $index;
        $node->catchType = $catchAll->type;

        return $path;
    }

    /**
     * Follows (and creates) the static and parameter edges for the given segments.
     *
     * @param list<Segment> $segments
     *
     * @return non-empty-list<BuildNode> the visited nodes, starting with $root
     */
    private static function walk(BuildNode $root, array $segments): array
    {
        $node = $root;
        $path = [$root];

        foreach ($segments as $segment) {
            $node = $segment->type === SegmentType::Static
                ? self::staticChild($node, $segment->value)
                : self::paramChild($node);
            $path[] = $node;
        }

        return $path;
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

    /**
     * Rejects declarations that pass through a group prefix without being declared inside that group;
     * the matcher would otherwise attach the group's metadata to them.
     *
     * @param list<BuildNode> $path
     * @param list<int> $declaredGroups
     * @param list<GroupDefinition> $groups
     *
     * @throws InvalidRouteException
     */
    private static function assertOwnership(array $path, array $declaredGroups, array $groups, string $subject): void
    {
        foreach ($path as $node) {
            if ($node->group === null || in_array($node->group, $declaredGroups, strict: true)) {
                continue;
            }

            throw new InvalidRouteException(sprintf(
                '%s lies under group prefix "%s" but is not declared inside that group.',
                $subject,
                $groups[$node->group]->prefix,
            ));
        }
    }
}
