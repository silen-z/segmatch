<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests;

use Closure;
use PHPUnit\Framework\TestCase;
use SilenZ\Segmatch\Compiler;
use SilenZ\Segmatch\Matcher;
use SilenZ\Segmatch\NoMatch;
use SilenZ\Segmatch\RouteMatch;
use SilenZ\Segmatch\RouteSet;

use function array_key_exists;
use function array_map;
use function ctype_digit;
use function in_array;

final class GuardTest extends TestCase
{
    /**
     * @param list<array{string, array{name: string, methods?: list<string>}}> $routes
     */
    private static function matcher(array $routes): Matcher
    {
        $set = new RouteSet();
        foreach ($routes as [$path, $metadata]) {
            $set->add($path, $metadata);
        }

        return new Matcher(Compiler::compile($set));
    }

    /**
     * @return Closure(mixed, array<string, string>): bool
     */
    private static function method(string $method): Closure
    {
        return static fn(mixed $route): bool => in_array(
            $method,
            self::metadata($route)['methods'] ?? [],
            strict: true,
        );
    }

    /**
     * Route metadata in these tests always has this shape.
     *
     * @return array{name: string, methods?: list<string>}
     */
    private static function metadata(mixed $route): array
    {
        /** @var array{name: string, methods?: list<string>} $route */
        return $route;
    }

    private static function routeName(RouteMatch|NoMatch $result): ?string
    {
        if (!$result instanceof RouteMatch) {
            return null;
        }

        /** @var array{name: string} $route */
        $route = $result->route;

        return $route['name'];
    }

    /**
     * @return list<string>
     */
    private static function rejectedNames(RouteMatch|NoMatch $result): array
    {
        static::assertInstanceOf(NoMatch::class, $result);

        return array_map(static fn(mixed $route): string => self::metadata($route)['name'], $result->rejected);
    }

    public function testWithoutGuardTheFirstDeclaredRouteOfAPathWins(): void
    {
        $matcher = self::matcher([
            ['/users', ['name' => 'list']],
            ['/users', ['name' => 'create']],
            ['/users/{id}', ['name' => 'show']],
            ['/users/{slug}', ['name' => 'by-slug']],
            ['/files/{path+}', ['name' => 'files']],
            ['/files/{rest+}', ['name' => 'other-files']],
        ]);

        static::assertSame('list', self::routeName($matcher->match('/users')));
        static::assertSame('show', self::routeName($matcher->match('/users/1')));
        static::assertSame('files', self::routeName($matcher->match('/files/a/b')));
    }

    public function testGuardChoosesBetweenRoutesOfTheSamePath(): void
    {
        $matcher = self::matcher([
            ['/users', ['name' => 'list', 'methods' => ['GET']]],
            ['/users', ['name' => 'create', 'methods' => ['POST']]],
            ['/users/{id}', ['name' => 'show', 'methods' => ['GET']]],
            ['/users/{id}', ['name' => 'update', 'methods' => ['PUT', 'PATCH']]],
            ['/files/{path+}', ['name' => 'download', 'methods' => ['GET']]],
            ['/files/{path+}', ['name' => 'upload', 'methods' => ['PUT']]],
        ]);

        static::assertSame('list', self::routeName($matcher->match('/users', self::method('GET'))));
        static::assertSame('create', self::routeName($matcher->match('/users', self::method('POST'))));
        static::assertSame('show', self::routeName($matcher->match('/users/1', self::method('GET'))));
        static::assertSame('update', self::routeName($matcher->match('/users/1', self::method('PATCH'))));
        static::assertSame('upload', self::routeName($matcher->match('/files/a.txt', self::method('PUT'))));
    }

    public function testRejectedRoutesAreReportedForA405(): void
    {
        $matcher = self::matcher([
            ['/users', ['name' => 'list', 'methods' => ['GET']]],
            ['/users', ['name' => 'create', 'methods' => ['POST']]],
        ]);

        static::assertSame(['list', 'create'], self::rejectedNames($matcher->match('/users', self::method('DELETE'))));
    }

    public function testUnknownPathIsAPlain404(): void
    {
        $matcher = self::matcher([['/users', ['name' => 'list', 'methods' => ['GET']]]]);

        static::assertSame([], self::rejectedNames($matcher->match('/nope', self::method('GET'))));
        static::assertSame([], self::rejectedNames($matcher->match('/nope')));
        static::assertSame([], self::rejectedNames($matcher->match('users', self::method('GET'))));
    }

    public function testRejectedStaticRouteFallsThroughToAParameterRoute(): void
    {
        $matcher = self::matcher([
            ['/foo/bar', ['name' => 'static', 'methods' => ['POST']]],
            ['/foo/{id}', ['name' => 'param', 'methods' => ['GET']]],
        ]);

        $result = $matcher->match('/foo/bar', self::method('GET'));

        static::assertSame('param', self::routeName($result));
        static::assertInstanceOf(RouteMatch::class, $result);
        static::assertSame(['id' => 'bar'], $result->params);
    }

    public function testRejectionsFromAllBranchesAreMerged(): void
    {
        $matcher = self::matcher([
            ['/foo/bar', ['name' => 'static', 'methods' => ['POST']]],
            ['/foo/{id}', ['name' => 'param', 'methods' => ['PUT']]],
            ['/foo/{rest+}', ['name' => 'catch', 'methods' => ['DELETE']]],
        ]);

        static::assertSame(
            ['static', 'param', 'catch'],
            self::rejectedNames($matcher->match('/foo/bar', self::method('GET'))),
        );
    }

    public function testRejectionDeepInATreeBranchBacktracks(): void
    {
        $matcher = self::matcher([
            ['/a/b/{c}', ['name' => 'deep', 'methods' => ['POST']]],
            ['/a/{x}/{y}', ['name' => 'shallow', 'methods' => ['GET']]],
        ]);

        static::assertSame('shallow', self::routeName($matcher->match('/a/b/c', self::method('GET'))));
    }

    public function testGuardReceivesDecodedParametersForConstraints(): void
    {
        $matcher = self::matcher([
            ['/users/{id}', ['name' => 'by-id']],
            ['/users/{slug}', ['name' => 'by-slug']],
        ]);
        $numericId =
            /** @param array<string, string> $params */
            static fn(mixed $_route, array $params): bool => (
                !array_key_exists('id', $params) || ctype_digit($params['id'])
            );

        $byId = $matcher->match('/users/42', $numericId);
        $bySlug = $matcher->match('/users/john%20doe', $numericId);

        static::assertSame('by-id', self::routeName($byId));
        static::assertSame('by-slug', self::routeName($bySlug));
        static::assertInstanceOf(RouteMatch::class, $bySlug);
        static::assertSame(['slug' => 'john doe'], $bySlug->params);
    }

    public function testEmptyCatchAllIsOfferedToTheGuard(): void
    {
        $matcher = self::matcher([
            ['/docs/{page*}', ['name' => 'docs', 'methods' => ['GET']]],
        ]);

        $result = $matcher->match('/docs', self::method('GET'));

        static::assertInstanceOf(RouteMatch::class, $result);
        static::assertSame(['page' => ''], $result->params);
        static::assertSame(['docs'], self::rejectedNames($matcher->match('/docs', self::method('POST'))));
    }

    public function testDisabledRouteFallsThroughToACatchAll(): void
    {
        $matcher = self::matcher([
            ['/beta/{id}', ['name' => 'beta']],
            ['/{path+}', ['name' => 'frontend']],
        ]);
        $disabled = ['beta' => true];
        $enabled = static fn(mixed $route): bool => !($disabled[self::metadata($route)['name']] ?? false);

        static::assertSame('frontend', self::routeName($matcher->match('/beta/1', $enabled)));
    }

    public function testGuardIsNotCalledWhenNothingMatchesThePath(): void
    {
        $matcher = self::matcher([['/users/{id}', ['name' => 'show']]]);
        $calls = 0;
        $guard = static function () use (&$calls): bool {
            $calls++;

            return true;
        };

        $matcher->match('/posts/1', $guard);
        $matcher->match('/users/1/edit', $guard);

        static::assertSame(0, $calls);
    }
}
