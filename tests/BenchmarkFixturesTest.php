<?php

declare(strict_types=1);

namespace silenz\PhpRouter\Tests;

use FastRoute\Dispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use silenz\PhpRouter\Benchmarks\Fixtures;
use silenz\PhpRouter\Benchmarks\Routers;
use silenz\PhpRouter\Matcher;

use function array_filter;
use function array_key_exists;

/**
 * Benchmarks are only meaningful if both routers do the same work, so every benchmark request
 * must produce the same result in both.
 */
final class BenchmarkFixturesTest extends TestCase
{
    /** @var array<string, array{Matcher, Dispatcher}> */
    private static array $routers = [];

    /**
     * Building the large fixtures is slow, so both routers are built once per fixture.
     *
     * @return array{Matcher, Dispatcher}
     */
    private static function routers(string $fixture): array
    {
        if (!array_key_exists($fixture, self::$routers)) {
            $routes = Fixtures::get($fixture)['routes'];
            self::$routers[$fixture] = [Routers::flat($routes), Routers::fastRoute($routes)];
        }

        return self::$routers[$fixture];
    }

    /**
     * @return iterable<string, array{fixture: string, case: string, path: string}>
     */
    public static function requestProvider(): iterable
    {
        return Fixtures::provideRequests();
    }

    #[DataProvider('requestProvider')]
    public function testRoutersAgree(string $fixture, string $case, string $path): void
    {
        [$flatRouter, $fastRouteRouter] = self::routers($fixture);
        $flat = $flatRouter->match($path);
        $fastRoute = $fastRouteRouter->dispatch('GET', $path);

        if ($case === 'not-found' || $case === 'backtrack-miss') {
            static::assertNull($flat);
            static::assertSame(Dispatcher::NOT_FOUND, $fastRoute[0]);

            return;
        }

        static::assertNotNull($flat, 'flat router found no match');
        static::assertSame(Dispatcher::FOUND, $fastRoute[0], 'FastRoute found no match');
        static::assertSame($fastRoute[1], $flat->route);
        // FastRoute omits an optional catch-all that matched nothing; this router reports ''.
        static::assertSame($fastRoute[2], array_filter($flat->params, static fn(string $v): bool => $v !== ''));
    }

    /**
     * @return iterable<string, array{fixture: string, path: string}>
     */
    public static function mixedPathProvider(): iterable
    {
        return Fixtures::provideMixedPaths();
    }

    #[DataProvider('mixedPathProvider')]
    public function testRoutersAgreeOnMixedPaths(string $fixture, string $path): void
    {
        [$flatRouter, $fastRouteRouter] = self::routers($fixture);
        $flat = $flatRouter->match($path);
        $fastRoute = $fastRouteRouter->dispatch('GET', $path);

        if ($flat === null) {
            static::assertSame(Dispatcher::NOT_FOUND, $fastRoute[0]);

            return;
        }

        static::assertSame(Dispatcher::FOUND, $fastRoute[0], 'FastRoute found no match');
        static::assertSame($fastRoute[1], $flat->route);
        static::assertSame($fastRoute[2], array_filter($flat->params, static fn(string $v): bool => $v !== ''));
    }

    public function testCatchAllPatternTranslation(): void
    {
        static::assertSame('/assets[/{path:.*}]', Routers::toFastRoutePattern('/assets/{path*}'));
        static::assertSame('/assets/{path:.+}', Routers::toFastRoutePattern('/assets/{path+}'));
        static::assertSame('/users/{id}', Routers::toFastRoutePattern('/users/{id}'));
    }
}
