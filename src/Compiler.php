<?php

declare(strict_types=1);

namespace SilenZ\Segmatch;

use SilenZ\Segmatch\Exception\InvalidRouteException;
use SilenZ\Segmatch\Internal\Flattener;
use SilenZ\Segmatch\Internal\TreeBuilder;
use UnitEnum;

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

        return Flattener::flatten(TreeBuilder::build($routes), $routes, $tableMetadata);
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
}
