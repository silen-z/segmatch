<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use SilenZ\Segmatch\Cache\RouteCache;
use SilenZ\Segmatch\CallableRouteTable;
use SilenZ\Segmatch\Compiler;
use SilenZ\Segmatch\NoMatch;
use SilenZ\Segmatch\RouteDefinition;
use SilenZ\Segmatch\RouteMatch;
use SilenZ\Segmatch\Router;
use SilenZ\Segmatch\RouteTable;

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

    /**
     * @param callable(): iterable<int, RouteDefinition> $definitions
     */
    private static function table(callable $definitions, ?string $cacheKey = null): RouteTable
    {
        return new CallableRouteTable($definitions, $cacheKey);
    }

    private static function route(RouteMatch|NoMatch $result): mixed
    {
        return $result instanceof RouteMatch ? $result->route : null;
    }

    public function testMatchesWithoutACache(): void
    {
        $router = new Router(self::table(static fn(): array => [new RouteDefinition('/users/{id}', 'user')]));

        $result = $router->match('/users/7');

        static::assertInstanceOf(RouteMatch::class, $result);
        static::assertSame(['id' => '7'], $result->params);
    }

    public function testRoutesMayComeFromAGenerator(): void
    {
        $users =
            /** @return iterable<int, RouteDefinition> */
            static function (): iterable {
                yield new RouteDefinition('/users', 'users');
                yield new RouteDefinition('/users/{id}', 'user');
            };
        $router = new Router(self::table(
            /** @return iterable<int, RouteDefinition> */
            static function () use ($users): iterable {
                yield new RouteDefinition('/', 'home');
                yield from $users();
            },
        ));

        static::assertSame('home', self::route($router->match('/')));
        static::assertSame('user', self::route($router->match('/users/7')));
    }

    public function testNullCacheCompilesForEveryRouterInstance(): void
    {
        $calls = new ArrayObject();
        $define = static function () use ($calls): array {
            $calls->append(true);

            return [new RouteDefinition('/a', 'a')];
        };

        new Router(self::table($define))->match('/a');
        new Router(self::table($define))->match('/a');

        static::assertCount(2, $calls);
    }

    public function testRoutesAreDeclaredLazilyAndOnlyOncePerInstance(): void
    {
        $calls = new ArrayObject();
        $router = new Router(self::table(static function () use ($calls): array {
            $calls->append(true);

            return [new RouteDefinition('/a', 'a')];
        }));

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
        $define = static function () use ($calls): array {
            $calls->append(true);

            return [new RouteDefinition('/a', 'a')];
        };

        new Router(self::table($define, 'routes'), $cache)->match('/a');
        $result = new Router(self::table($define, 'routes'), $cache)->match('/a');

        static::assertCount(1, $calls);
        static::assertSame('a', self::route($result));
    }

    public function testExistingEntryIsUsedEvenWhenTheDefinitionChanges(): void
    {
        $cache = self::memoryCache();
        new Router(self::table(static fn(): array => [new RouteDefinition('/old', 'old')], 'routes'), $cache)->match(
            '/old',
        );

        $router = new Router(self::table(static fn(): array => [new RouteDefinition('/new', 'new')], 'routes'), $cache);

        static::assertSame('old', self::route($router->match('/old')));
        static::assertNull(self::route($router->match('/new')));
    }

    public function testDifferentKeysKeepSeparateEntries(): void
    {
        $cache = self::memoryCache();
        $v1 = new Router(self::table(static fn(): array => [new RouteDefinition('/a', 'v1')], 'routes-v1'), $cache);
        $v2 = new Router(self::table(static fn(): array => [new RouteDefinition('/a', 'v2')], 'routes-v2'), $cache);

        static::assertSame('v1', self::route($v1->match('/a')));
        static::assertSame('v2', self::route($v2->match('/a')));
    }

    public function testANullKeyNeverCachesEvenWithACacheConfigured(): void
    {
        $cache = self::memoryCache();
        $calls = new ArrayObject();
        $define = static function () use ($calls): array {
            $calls->append(true);

            return [new RouteDefinition('/a', 'a')];
        };

        new Router(self::table($define), $cache)->match('/a');
        new Router(self::table($define), $cache)->match('/a');

        static::assertCount(2, $calls);
    }

    public function testEntryFromAnIncompatibleVersionIsRecompiled(): void
    {
        $cache = self::memoryCache();
        $cache->set('routes', ['version' => 0] + Compiler::compile([]));

        $router = new Router(self::table(static fn(): array => [new RouteDefinition('/a', 'a')], 'routes'), $cache);

        static::assertSame('a', self::route($router->match('/a')));
        static::assertSame(
            'a',
            self::route(new Router(self::table(static fn(): array => [], 'routes'), $cache)->match('/a')),
        );
    }

    public function testFilterIsPassedThrough(): void
    {
        $router = new Router(self::table(static fn(): array => [
            new RouteDefinition('/users', ['methods' => ['GET']]),
            new RouteDefinition('/users', ['methods' => ['POST']]),
        ]));

        $result = $router->match(
            '/users',
            static fn(RouteMatch $match): bool => $match->route === ['methods' => ['POST']],
        );

        static::assertSame(['methods' => ['POST']], self::route($result));
    }

    public function testMatchAllReturnsEveryCandidateForThePath(): void
    {
        $router = new Router(self::table(static fn(): array => [
            new RouteDefinition('/users', ['methods' => ['GET']]),
            new RouteDefinition('/users', ['methods' => ['POST']]),
        ]));

        static::assertSame(
            [['methods' => ['GET']], ['methods' => ['POST']]],
            array_map(static fn(RouteMatch $match): mixed => $match->route, $router->matchAll('/users')),
        );
    }

    public function testMatchAllIsEmptyForAnUnknownPath(): void
    {
        $router = new Router(self::table(static fn(): array => [new RouteDefinition('/users', 'list')]));

        static::assertSame([], $router->matchAll('/nope'));
    }

    public function testDefinitionsReturnsTheRoutesAsDeclared(): void
    {
        $router = new Router(self::table(static fn(): array => [
            new RouteDefinition('/users', 'list'),
            new RouteDefinition('/users/{id}', 'show'),
        ]));

        $definitions = [...$router->definitions()];

        static::assertCount(2, $definitions);
        static::assertSame('/users', $definitions[0]->path);
        static::assertSame('/users/{id}', $definitions[1]->path);
    }

    public function testDefinitionsCallsTheRoutesCallableEveryTime(): void
    {
        $calls = new ArrayObject();
        $router = new Router(self::table(static function () use ($calls): array {
            $calls->append(true);

            return [new RouteDefinition('/a', 'a')];
        }));

        $router->definitions();
        $router->definitions();

        static::assertCount(2, $calls);
    }

    public function testDefinitionsIgnoresTheCache(): void
    {
        $cache = self::memoryCache();
        $router = new Router(self::table(static fn(): array => [new RouteDefinition('/a', 'first')], 'routes'), $cache);
        $router->match('/a'); // populates the cache

        $laterRouter = new Router(self::table(static fn(): array => [new RouteDefinition(
            '/a',
            'second',
        )], 'routes'), $cache);

        static::assertSame('first', self::route($laterRouter->match('/a')));
        static::assertSame('second', iterator_to_array($laterRouter->definitions())[0]->metadata);
    }

    public function testTableMetadataIsNullByDefault(): void
    {
        $router = new Router(self::table(static fn(): array => [new RouteDefinition('/a', 'a')]));

        static::assertNull($router->tableMetadata());
    }

    public function testTableMetadataComesFromTheCacheWithoutDeclaringAgain(): void
    {
        $cache = self::memoryCache();
        $calls = new ArrayObject();
        $table = static fn(string $middleware): RouteTable => new class($calls, $middleware) extends RouteTable {
            /**
             * @param ArrayObject<int, string> $calls
             */
            public function __construct(
                private readonly ArrayObject $calls,
                private readonly string $middleware,
            ) {}

            public function cacheKey(): string
            {
                return 'routes';
            }

            public function definitions(): array
            {
                $this->calls->append('definitions');

                return [new RouteDefinition('/a', 'a')];
            }

            public function metadata(): array
            {
                $this->calls->append('metadata');

                return ['middleware' => [$this->middleware]];
            }
        };

        static::assertSame(['middleware' => ['first']], new Router($table('first'), $cache)->tableMetadata());
        // Answered from the cache: neither the routes nor the table metadata are produced again.
        static::assertSame(['middleware' => ['first']], new Router($table('second'), $cache)->tableMetadata());
        static::assertSame(['definitions', 'metadata'], $calls->getArrayCopy());
    }

    public function testTableMetadataIsNeverMatched(): void
    {
        $router = new Router(new class extends RouteTable {
            public function cacheKey(): null
            {
                return null;
            }

            public function definitions(): array
            {
                return [];
            }

            public function metadata(): string
            {
                return 'table';
            }
        });

        static::assertInstanceOf(NoMatch::class, $router->match('/'));
        static::assertSame([], $router->matcher()->metadata());
    }
}
