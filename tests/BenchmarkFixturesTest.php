<?php

declare(strict_types=1);

namespace silenz\PhpRouter\Tests;

use FastRoute\Dispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use silenz\PhpRouter\Benchmarks\Fixtures;
use silenz\PhpRouter\Benchmarks\Routers;

use function array_filter;

/**
 * Benchmarks are only meaningful if both routers do the same work, so every benchmark request
 * must produce the same result in both.
 */
final class BenchmarkFixturesTest extends TestCase
{
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
        $routes = Fixtures::get($fixture)['routes'];

        $flat = Routers::flat($routes)->match($path);
        $fastRoute = Routers::fastRoute($routes)->dispatch('GET', $path);

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

    public function testCatchAllPatternTranslation(): void
    {
        static::assertSame('/assets[/{path:.*}]', Routers::toFastRoutePattern('/assets/{path*}'));
        static::assertSame('/assets/{path:.+}', Routers::toFastRoutePattern('/assets/{path+}'));
        static::assertSame('/users/{id}', Routers::toFastRoutePattern('/users/{id}'));
    }
}
