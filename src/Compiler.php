<?php

declare(strict_types=1);

namespace silenz\PhpRouter;

use silenz\PhpRouter\Exception\InvalidRouteException;
use silenz\PhpRouter\Internal\Flattener;
use silenz\PhpRouter\Internal\TreeBuilder;
use UnitEnum;

use function get_debug_type;
use function is_array;
use function is_scalar;
use function sprintf;

/**
 * Compiles route declarations into the flat runtime structure consumed by {@see Matcher}.
 *
 * The output is plain data: it can be passed to the matcher directly or written to a PHP file
 * by {@see RouteCache} and loaded back with `require`.
 *
 * @psalm-type CompiledNode = array{0: array<array-key, int>, 1: int, 2: int, 3: int, 4: int, 5: int}
 * @psalm-type CompiledRoute = array{0: mixed, 1: list<string>}
 * @psalm-type CompiledRoutes = array{version: int, nodes: list<CompiledNode>, routes: list<CompiledRoute>, groups: list<mixed>}
 */
final class Compiler
{
    /**
     * @return CompiledRoutes
     *
     * @throws InvalidRouteException
     */
    public static function compile(RouteCollector $collector): array
    {
        $groups = $collector->groups();
        $routes = $collector->routes();

        foreach ($groups as $group) {
            self::assertExportable($group->metadata, sprintf('group "%s"', $group->prefix));
        }

        foreach ($routes as $route) {
            self::assertExportable($route->metadata, sprintf('route "%s"', $route->path));
        }

        return Flattener::flatten(TreeBuilder::build($routes, $groups), $routes, $groups);
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
