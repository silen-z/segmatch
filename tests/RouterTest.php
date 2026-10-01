<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use SilenZ\Segmatch\Cache\RouteCache;
use SilenZ\Segmatch\Compiler;
use SilenZ\Segmatch\NoMatch;
use SilenZ\Segmatch\RouteMatch;
use SilenZ\Segmatch\Router;
use SilenZ\Segmatch\RouteSet;

final class RouterTest extends TestCase
{
    /**
     * An in-memory cache, so these tests are about the router and not the file system.
     */
    private static function memoryCache(): RouteCache
    {
        return new class implements RouteCache {
            /** @var array<string, array<array-key, mixed>> */
            public array $entries = [];

            public function get(string $key): ?array
            {
                return $this->entries[$key] ?? null;
            }

            public function set(string $key, array $compiled): void
            {
                $this->entries[$key] = $compiled;
            }
        };
    }

    private static function route(RouteMatch|NoMatch $result): mixed
    {
        return $result instanceof RouteMatch ? $result->route : null;
    }

    public function testMatchesWithoutACache(): void
    {
        $router = new Router(static fn(RouteSet $r) => $r->add('/users/{id}', 'user'));

        $result = $router->match('/users/7');

        static::assertInstanceOf(RouteMatch::class, $result);
        static::assertSame(['id' => '7'], $result->params);
    }

    public function testNullCacheCompilesForEveryRouterInstance(): void
    {
        $calls = new ArrayObject();
        $define = static function (RouteSet $r) use ($calls): void {
            $calls->append(true);
            $r->add('/a', 'a');
        };

        new Router($define)->match('/a');
        new Router($define)->match('/a');

        static::assertCount(2, $calls);
    }

    public function testRoutesAreDeclaredLazilyAndOnlyOncePerInstance(): void
    {
        $calls = new ArrayObject();
        $router = new Router(static function (RouteSet $r) use ($calls): void {
            $calls->append(true);
            $r->add('/a', 'a');
        });

        static::assertCount(0, $calls);

        $router->match('/a');
        $router->match('/b');

        static::assertCount(1, $calls);
        static::assertSame($router->matcher(), $router->matcher());
    }

    public function testCachedRoutesAreNotDeclaredAgain(): void
    {
        $cache = self::memoryCache();
        $calls = new ArrayObject();
        $define = static function (RouteSet $r) use ($calls): void {
            $calls->append(true);
            $r->add('/a', 'a');
        };

        new Router($define, $cache)->match('/a');
        $result = new Router($define, $cache)->match('/a');

        static::assertCount(1, $calls);
        static::assertSame('a', self::route($result));
    }

    public function testExistingEntryIsUsedEvenWhenTheDefinitionChanges(): void
    {
        $cache = self::memoryCache();
        new Router(static fn(RouteSet $r) => $r->add('/old', 'old'), $cache)->match('/old');

        $router = new Router(static fn(RouteSet $r) => $r->add('/new', 'new'), $cache);

        static::assertSame('old', self::route($router->match('/old')));
        static::assertNull(self::route($router->match('/new')));
    }

    public function testDifferentKeysKeepSeparateEntries(): void
    {
        $cache = self::memoryCache();
        $v1 = new Router(static fn(RouteSet $r) => $r->add('/a', 'v1'), $cache, 'routes-v1');
        $v2 = new Router(static fn(RouteSet $r) => $r->add('/a', 'v2'), $cache, 'routes-v2');

        static::assertSame('v1', self::route($v1->match('/a')));
        static::assertSame('v2', self::route($v2->match('/a')));
    }

    public function testEntryFromAnIncompatibleVersionIsRecompiled(): void
    {
        $cache = self::memoryCache();
        $cache->set('routes', ['version' => 0] + Compiler::compile(new RouteSet()));

        $router = new Router(static fn(RouteSet $r) => $r->add('/a', 'a'), $cache);

        static::assertSame('a', self::route($router->match('/a')));
        static::assertSame('a', self::route(new Router(static fn() => null, $cache)->match('/a')));
    }

    public function testGuardIsPassedThrough(): void
    {
        $router = new Router(static function (RouteSet $r): void {
            $r->add('/users', ['methods' => ['GET']]);
            $r->add('/users', ['methods' => ['POST']]);
        });

        $result = $router->match('/users', static fn(mixed $route): bool => $route === ['methods' => ['POST']]);

        static::assertSame(['methods' => ['POST']], self::route($result));
    }
}
