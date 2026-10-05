<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SilenZ\Segmatch\Compiler;
use SilenZ\Segmatch\Exception\InvalidRouteException;
use SilenZ\Segmatch\Matcher;
use SilenZ\Segmatch\NoMatch;
use SilenZ\Segmatch\RouteDefinition;
use SilenZ\Segmatch\RouteMatch;
use SilenZ\Segmatch\RouteTable;
use SilenZ\Segmatch\Tests\Support\RouteOracle;

use function array_map;
use function count;
use function crc32;
use function implode;
use function is_int;
use function mt_rand;
use function mt_srand;
use function str_ends_with;
use function substr;

/**
 * Compares the matcher with {@see RouteOracle} on random route sets, requests and filters.
 */
final class MatcherPropertyTest extends TestCase
{
    private const array SEGMENTS = ['a', 'b', 'me', '{p}', '{q}', '{r*}', '{r+}'];
    private const array REQUEST_SEGMENTS = ['a', 'b', 'me', 'x', '', '7', '%41'];

    /**
     * @return iterable<string, array{int}>
     */
    public static function seedProvider(): iterable
    {
        for ($seed = 1; $seed <= 400; $seed++) {
            yield 'seed ' . $seed => [$seed];
        }
    }

    #[DataProvider('seedProvider')]
    public function testMatcherAgreesWithOracle(int $seed): void
    {
        mt_srand($seed);
        $routes = self::randomRoutes();

        try {
            $set = [];
            foreach ($routes as $index => $path) {
                $set[] = new RouteDefinition($path, $index);
            }

            $matcher = new Matcher(Compiler::compile(new RouteTable($set)));
        } catch (InvalidRouteException) {
            // Random sets may mix {r*} and {r+} on one node, which is rejected by design.
            $this->addToAssertionCount(1);

            return;
        }

        for ($request = 0; $request < 30; $request++) {
            $path = self::randomPath();
            $salt = mt_rand();
            // Accepts roughly two thirds of the candidates, differently for every request.
            $accepts = static fn(int $index): bool => (crc32($path . '|' . $index . '|' . $salt) % 3) !== 0;
            $matcherFilter = static fn(RouteMatch $match): bool => is_int($match->route) && $accepts($match->route);

            self::assertSameResult(RouteOracle::match($routes, $path, null), $matcher->match($path), $path);
            self::assertSameResult(
                RouteOracle::match($routes, $path, $accepts),
                $matcher->match($path, $matcherFilter),
                $path,
            );
        }
    }

    /**
     * @param array{route: ?int, params: array<string, string>, rejected: list<int>} $expected
     */
    private static function assertSameResult(array $expected, RouteMatch|NoMatch $actual, string $path): void
    {
        if ($expected['route'] === null) {
            static::assertInstanceOf(NoMatch::class, $actual, $path);
            static::assertSame(
                $expected['rejected'],
                array_map(static fn(RouteMatch $m): mixed => $m->route, $actual->rejected),
                $path,
            );

            return;
        }

        static::assertInstanceOf(RouteMatch::class, $actual, $path);
        static::assertSame($expected['route'], $actual->route, $path);
        static::assertSame($expected['params'], $actual->params, $path);
    }

    /**
     * A small alphabet, so that routes overlap a lot and often share paths.
     *
     * @return list<string>
     */
    private static function randomRoutes(): array
    {
        $routes = [];
        for ($i = 0, $n = mt_rand(min: 1, max: 12); $i < $n; $i++) {
            $segments = [];
            for ($j = 0, $depth = mt_rand(min: 1, max: 3); $j < $depth; $j++) {
                $segment = self::SEGMENTS[mt_rand(min: 0, max: count(self::SEGMENTS) - 1)];
                if ($segment === '{p}' || $segment === '{q}') {
                    // Names unique per position keep a route from using a name twice.
                    $segment = '{' . substr($segment, offset: 1, length: 1) . $j . '}';
                }

                $segments[] = $segment;
                if (self::isCatchAll($segment)) {
                    break;
                }
            }

            if (mt_rand(min: 0, max: 5) === 0 && !self::isCatchAll($segments[count($segments) - 1])) {
                $segments[] = '';
            }

            $routes[] = '/' . implode('/', $segments);
        }

        return $routes;
    }

    private static function isCatchAll(string $segment): bool
    {
        return str_ends_with($segment, '*}') || str_ends_with($segment, '+}');
    }

    private static function randomPath(): string
    {
        $segments = [];
        for ($j = 0, $depth = mt_rand(min: 0, max: 4); $j < $depth; $j++) {
            $segments[] = self::REQUEST_SEGMENTS[mt_rand(min: 0, max: count(self::REQUEST_SEGMENTS) - 1)];
        }

        return '/' . implode('/', $segments);
    }
}
