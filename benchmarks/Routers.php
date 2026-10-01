<?php

declare(strict_types=1);

namespace silenz\PhpRouter\Benchmarks;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector as FastRouteCollector;
use silenz\PhpRouter\Compiler;
use silenz\PhpRouter\Matcher;
use silenz\PhpRouter\RouteCache;
use silenz\PhpRouter\RouteCollector;

use function FastRoute\cachedDispatcher;
use function FastRoute\simpleDispatcher;
use function is_file;
use function preg_match;
use function preg_replace;
use function str_contains;
use function unlink;
use function usort;

/**
 * Builds equivalent routers from one fixture. Route metadata is the route's index in the fixture,
 * so results of both routers can be compared.
 */
final class Routers
{
    /**
     * @param list<string> $routes
     */
    public static function flatCollector(array $routes): RouteCollector
    {
        $collector = new RouteCollector();
        foreach ($routes as $index => $route) {
            $collector->add($route, $index);
        }

        return $collector;
    }

    /**
     * @param list<string> $routes
     */
    public static function flat(array $routes): Matcher
    {
        return new Matcher(Compiler::compile(self::flatCollector($routes)));
    }

    /**
     * Writes fresh cache files of both routers for a fixture.
     *
     * @return array{string, string} paths of this router's and FastRoute's cache file
     */
    public static function writeCaches(string $fixture): array
    {
        $routes = Fixtures::get($fixture)['routes'];
        $directory = __DIR__ . '/../var/bench-cache/';
        $flatFile = $directory . $fixture . '.flat.php';
        $fastRouteFile = $directory . $fixture . '.fast-route.php';

        new RouteCache($flatFile)->write(Compiler::compile(self::flatCollector($routes)));

        // FastRoute only writes its cache when the file does not exist yet.
        if (is_file($fastRouteFile)) {
            unlink($fastRouteFile);
        }
        self::fastRoute($routes, $fastRouteFile);

        return [$flatFile, $fastRouteFile];
    }

    /**
     * @param list<string> $routes
     */
    public static function fastRoute(array $routes, ?string $cacheFile = null): Dispatcher
    {
        $define = static function (FastRouteCollector $collector) use ($routes): void {
            foreach (self::fastRouteOrder($routes) as $index => $route) {
                $collector->addRoute('GET', self::toFastRoutePattern($route), $index);
            }
        };

        if ($cacheFile === null) {
            return simpleDispatcher($define);
        }

        return cachedDispatcher($define, ['cacheFile' => $cacheFile]);
    }

    /**
     * Translates `{name*}` / `{name+}` catch-alls into FastRoute's regex placeholders.
     */
    public static function toFastRoutePattern(string $route): string
    {
        return (string) preg_replace(['#/\{(\w+)\*\}$#', '#\{(\w+)\+\}$#'], ['[/{$1:.*}]', '{$1:.+}'], $route);
    }

    /**
     * FastRoute resolves overlaps by registration order and rejects static routes registered after a
     * variable route that shadows them. Registering static routes first, then parameter routes, then
     * catch-alls gives it the same static > parameter > catch-all precedence this router has.
     *
     * @param list<string> $routes
     *
     * @return array<int, string> original index => pattern
     */
    private static function fastRouteOrder(array $routes): array
    {
        $rank = static fn(string $route): int => match (true) {
            preg_match('#\{\w+[*+]\}$#', $route) === 1 => 2,
            str_contains($route, '{') => 1,
            default => 0,
        };

        $indexes = [];
        foreach ($routes as $index => $route) {
            $indexes[] = $index;
        }

        usort($indexes, static fn(int $a, int $b): int => [$rank($routes[$a]), $a] <=> [$rank($routes[$b]), $b]);

        $ordered = [];
        foreach ($indexes as $index) {
            $ordered[$index] = $routes[$index];
        }

        return $ordered;
    }
}
