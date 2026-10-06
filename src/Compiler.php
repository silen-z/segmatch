<?php

declare(strict_types=1);

namespace SilenZ\Segmatch;

use SilenZ\Segmatch\Exception\InvalidRouteException;
use SilenZ\Segmatch\Internal\BuildNode;
use SilenZ\Segmatch\Internal\Flattener;
use SilenZ\Segmatch\Internal\Segment;
use SilenZ\Segmatch\Internal\SegmentType;
use UnitEnum;

use function array_all;
use function array_slice;
use function count;
use function get_debug_type;
use function is_array;
use function is_scalar;
use function sprintf;

/**
 * Compiles route declarations into the flat runtime structure consumed by {@see Matcher}.
 *
 * The output is plain data: it can be passed to the matcher directly or written to a PHP file
 * by a {@see Cache\RouteCache} and loaded back from it.
 *
 * Compiled routes are a handful of flat tables. Tree tables are sparse maps keyed by node id (the
 * root is node 0); route tables are keyed by route id (declaration order):
 *
 *     'table'         => [...],                          // the route table's own metadata, never matched
 *     'static'        => ['/users' => 4, '/' => [0, 1]], // full path => route id(s), parameterless routes
 *     'edges'         => [0 => ['users' => 1]],          // node => static segment => child node
 *     'param'         => [1 => 2],                       // node => child node of its {param} edge
 *     'catch'         => [3 => [false, 7]],               // node => [needs a non-empty rest
 *                                                         // ({name+}, not {name*}), ...route ids]
 *     'routes'        => [2 => 5],                       // node => route id(s) ending there
 *     'metadata'      => [[...], ...],                   // route id => the route's metadata
 *     'paramNames'    => [5 => ['id']],                  // route id => parameter names, capture order
 *
 * 'table' is whatever {@see RouteTable::metadata()} gave (null by default): it belongs to the table
 * as a whole, not to any route, so no node points to it.
 *
 * Route id(s) are a single id, or a list in declaration order when several routes share a path.
 *
 * A child node id in 'edges' or 'param' is stored negative (`-id - 1`, never -1, which the matcher
 * reads as "none") when a miss past that edge still has somewhere left to go on the *source* node
 * (its {param} edge for 'edges', its catch-all for 'param'): that flags the matcher to push a
 * backtrack frame without a separate lookup.
 *
 * Compiling builds an intermediate tree of {@see BuildNode}s from the routes, moves the routes
 * without parameters out of it into the 'static' table, and has {@see Flattener} turn the rest into
 * the tables above.
 *
 * @psalm-type RouteIds = int|non-empty-list<int> a single route id, or several in declaration order
 * @psalm-type CatchEntry = list{bool, int, ...<int>} a catch-all edge, flattened: index 0 is whether
 *     it needs a non-empty rest ({name+}, not {name*}); every element after it is a route id, in
 *     declaration order
 * @psalm-type CompiledRoutes = array{
 *     version: int,
 *     table: mixed,
 *     static: array<array-key, RouteIds>,
 *     edges: array<int, array<array-key, int>>,
 *     param: array<int, int>,
 *     catch: array<int, CatchEntry>,
 *     routes: array<int, RouteIds>,
 *     metadata: list<mixed>,
 *     paramNames: array<int, non-empty-list<string>>
 * }
 */
final class Compiler
{
    /**
     * The version of the compiled structure above. Bumped whenever it changes, so entries cached by
     * an incompatible version are recompiled instead of misread.
     */
    public const int FORMAT_VERSION = 10;

    /**
     * Compiles the table's definitions in declaration order, which decides between routes sharing a
     * path, and its metadata, kept as-is for {@see Matcher::tableMetadata()}. Anything but a
     * {@see RouteDefinition} among the definitions is rejected, and both must be plain data. The
     * table's cache key plays no part.
     *
     * @return CompiledRoutes
     *
     * @throws InvalidRouteException
     */
    public static function compile(RouteTable $table): array
    {
        $routes = [];
        // Checked anyway: the definitions' type is only documented, and a closure may return anything.
        /** @var iterable<mixed> $definitions */
        $definitions = $table->definitions();
        // @mago-expect analysis:mixed-assignment
        foreach ($definitions as $route) {
            if (!$route instanceof RouteDefinition) {
                throw new InvalidRouteException(sprintf(
                    'Routes must be given as %s instances, got %s.',
                    RouteDefinition::class,
                    get_debug_type($route),
                ));
            }

            self::assertExportable($route->metadata, sprintf('route "%s"', $route->path));
            $routes[] = $route;
        }

        // @mago-expect analysis:mixed-assignment
        $tableMetadata = $table->metadata();
        self::assertExportable($tableMetadata, 'the route table');

        $root = self::tree($routes);
        // Must run before the flattener assigns node ids: it prunes the tree.
        $static = self::staticTable($root, $routes);

        return Flattener::flatten($root, $routes, $static, $tableMetadata);
    }

    /**
     * Metadata is written into the cache file, so it must survive a round trip through PHP source.
     *
     * @throws InvalidRouteException
     */
    private static function assertExportable(mixed $value, string $owner): void
    {
        if ($value === null || is_scalar($value) || $value instanceof UnitEnum) {
            return;
        }

        if (!is_array($value)) {
            throw new InvalidRouteException(sprintf(
                'Metadata of %s contains a value of type %s; only scalars, null, enums and arrays are supported.',
                $owner,
                get_debug_type($value),
            ));
        }

        // Metadata is arbitrary user data, so its items are mixed by definition.
        // @mago-expect analysis:mixed-assignment
        foreach ($value as $item) {
            self::assertExportable($item, $owner);
        }
    }

    /**
     * Builds the intermediate tree from the routes.
     *
     * Several routes may share a path; they are kept in declaration order and a match-time filter
     * chooses between them. The only conflict left is mixing `{name*}` and `{name+}` on one node.
     *
     * @param list<RouteDefinition> $routes
     *
     * @throws InvalidRouteException
     */
    private static function tree(array $routes): BuildNode
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
                ? ($node->static[$segment->value] ??= new BuildNode())
                : ($node->param ??= new BuildNode());
        }

        return $node;
    }

    /**
     * Moves routes without parameters out of the tree into a table keyed by the full path, so the
     * matcher answers them with one hash lookup.
     *
     * This does not change matching results. A request equal to such a path would follow static edges
     * all the way down (static wins at every node) and stop on exactly those routes; and since their
     * node is only reachable through static edges, no other request can end there. Tree nodes that only
     * existed for these routes are pruned afterwards. If a filter rejects every route found here, the
     * matcher continues in the tree, exactly as it would backtrack from that node.
     *
     * Mutates the tree: extracted routes are removed from it and empty subtrees are pruned.
     *
     * @param list<RouteDefinition> $routes
     *
     * @return array<array-key, non-empty-list<int>> full path => route ids in declaration order
     */
    private static function staticTable(BuildNode $root, array $routes): array
    {
        $static = [];
        foreach ($routes as $index => $route) {
            if (!array_all($route->segments, static fn(Segment $s): bool => $s->type === SegmentType::Static)) {
                continue;
            }

            $static[$route->path][] = $index;
        }

        self::removeRoutes($root);
        self::prune($root);

        return $static;
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
