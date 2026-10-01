<?php

declare(strict_types=1);

namespace silenz\PhpRouter\Tests;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use silenz\PhpRouter\Compiler;
use silenz\PhpRouter\Matcher;
use silenz\PhpRouter\RouteMatch;
use silenz\PhpRouter\RouteSet;

use function sprintf;

final class MatcherTest extends TestCase
{
    /**
     * @param Closure(RouteSet): void $define
     */
    private static function matcher(Closure $define): Matcher
    {
        $collector = new RouteSet();
        $define($collector);

        return new Matcher(Compiler::compile($collector));
    }

    private static function mustMatch(Matcher $matcher, string $path): RouteMatch
    {
        $match = $matcher->match($path);
        static::assertNotNull($match, sprintf('Expected "%s" to match.', $path));

        return $match;
    }

    public function testMatchesStaticRoutes(): void
    {
        $matcher = self::matcher(static function (RouteSet $r): void {
            $r->add('/', 'home');
            $r->add('/users', 'users');
            $r->add('/users/active', 'active');
        });

        static::assertSame('home', $matcher->match('/')?->route);
        static::assertSame('users', $matcher->match('/users')?->route);
        static::assertSame('active', $matcher->match('/users/active')?->route);
        static::assertNull($matcher->match('/users/inactive'));
        static::assertNull($matcher->match('/missing'));
    }

    public function testRejectsPathsWithoutLeadingSlash(): void
    {
        $matcher = self::matcher(static fn(RouteSet $r) => $r->add('/', 'home'));

        static::assertNull($matcher->match(''));
        static::assertNull($matcher->match('users'));
    }

    public function testTrailingSlashIsSignificant(): void
    {
        $matcher = self::matcher(static function (RouteSet $r): void {
            $r->add('/foo', 'no-slash');
            $r->add('/bar/', 'slash');
        });

        static::assertSame('no-slash', $matcher->match('/foo')?->route);
        static::assertNull($matcher->match('/foo/'));
        static::assertSame('slash', $matcher->match('/bar/')?->route);
        static::assertNull($matcher->match('/bar'));
    }

    public function testExtractsNamedParameters(): void
    {
        $matcher = self::matcher(static fn(RouteSet $r) => $r->add('/users/{id}/posts/{post}', 'post'));

        $match = self::mustMatch($matcher, '/users/123/posts/456');

        static::assertSame('post', $match->route);
        static::assertSame(['id' => '123', 'post' => '456'], $match->params);
    }

    public function testParametersAreUrlDecoded(): void
    {
        $matcher = self::matcher(static fn(RouteSet $r) => $r->add('/search/{term}', 'search'));

        static::assertSame(['term' => 'a b/c'], $matcher->match('/search/a%20b%2Fc')?->params);
    }

    public function testParameterDoesNotMatchEmptySegment(): void
    {
        $matcher = self::matcher(static fn(RouteSet $r) => $r->add('/users/{id}', 'user'));

        static::assertNull($matcher->match('/users/'));
    }

    public function testParameterNamesMayDifferAcrossRoutesSharingANode(): void
    {
        $matcher = self::matcher(static function (RouteSet $r): void {
            $r->add('/items/{id}/edit', 'edit');
            $r->add('/items/{slug}/view', 'view');
        });

        static::assertSame(['id' => '1'], $matcher->match('/items/1/edit')?->params);
        static::assertSame(['slug' => 'x'], $matcher->match('/items/x/view')?->params);
    }

    public function testStaticTakesPrecedenceOverParameter(): void
    {
        $matcher = self::matcher(static function (RouteSet $r): void {
            $r->add('/users/{id}', 'user');
            $r->add('/users/me', 'me');
        });

        static::assertSame('me', $matcher->match('/users/me')?->route);
        static::assertSame('user', $matcher->match('/users/42')?->route);
    }

    public function testStaticRouteDoesNotBlockLongerParameterRoute(): void
    {
        // "/users/me" is answered from the static table and its tree node is pruned, so a longer
        // request must still reach the parameter branch.
        $matcher = self::matcher(static function (RouteSet $r): void {
            $r->add('/users/me', 'me');
            $r->add('/users/{id}/edit', 'edit');
        });

        static::assertSame('me', $matcher->match('/users/me')?->route);
        static::assertSame(['id' => 'me'], $matcher->match('/users/me/edit')?->params);
        static::assertNull($matcher->match('/users/me/'));
    }

    public function testParameterTakesPrecedenceOverCatchAll(): void
    {
        $matcher = self::matcher(static function (RouteSet $r): void {
            $r->add('/files/{rest*}', 'catch');
            $r->add('/files/{name}', 'param');
        });

        static::assertSame('param', $matcher->match('/files/a')?->route);
        static::assertSame('catch', $matcher->match('/files/a/b')?->route);
    }

    public function testBacktracksFromStaticToParameter(): void
    {
        $matcher = self::matcher(static function (RouteSet $r): void {
            $r->add('/foo/bar', 'static');
            $r->add('/foo/{id}/baz', 'param');
        });

        $match = self::mustMatch($matcher, '/foo/bar/baz');

        static::assertSame('param', $match->route);
        static::assertSame(['id' => 'bar'], $match->params);
    }

    public function testBacktracksAcrossSeveralLevelsToCatchAll(): void
    {
        $matcher = self::matcher(static function (RouteSet $r): void {
            $r->add('/a/b/c', 'static');
            $r->add('/a/{x}/d', 'param');
            $r->add('/{rest+}', 'catch');
        });

        static::assertSame('static', $matcher->match('/a/b/c')?->route);
        static::assertSame('param', $matcher->match('/a/b/d')?->route);

        $match = self::mustMatch($matcher, '/a/b/e');
        static::assertSame('catch', $match->route);
        static::assertSame(['rest' => 'a/b/e'], $match->params);
    }

    public function testBacktrackingDiscardsParametersOfAbandonedBranch(): void
    {
        $matcher = self::matcher(static function (RouteSet $r): void {
            $r->add('/{a}/{b}/x', 'deep');
            $r->add('/{c}/y', 'shallow');
        });

        static::assertSame(['c' => 'one'], $matcher->match('/one/y')?->params);
        static::assertSame(['a' => 'one', 'b' => 'two'], $matcher->match('/one/two/x')?->params);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function catchAllZeroProvider(): iterable
    {
        yield 'no remainder' => ['/assets', ''];
        yield 'trailing slash' => ['/assets/', ''];
        yield 'one segment' => ['/assets/app.js', 'app.js'];
        yield 'many segments' => ['/assets/css/app.css', 'css/app.css'];
        yield 'keeps trailing slash' => ['/assets/css/', 'css/'];
        yield 'other prefix' => ['/asset', null];
    }

    #[DataProvider('catchAllZeroProvider')]
    public function testCatchAllZeroOrMore(string $path, ?string $expected): void
    {
        $matcher = self::matcher(static fn(RouteSet $r) => $r->add('/assets/{path*}', 'assets'));

        static::assertSame($expected, $matcher->match($path)?->params['path']);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function catchAllOneProvider(): iterable
    {
        yield 'no remainder' => ['/assets', null];
        yield 'empty remainder' => ['/assets/', null];
        yield 'one segment' => ['/assets/app.js', 'app.js'];
        yield 'many segments' => ['/assets/css/app.css', 'css/app.css'];
    }

    #[DataProvider('catchAllOneProvider')]
    public function testCatchAllOneOrMore(string $path, ?string $expected): void
    {
        $matcher = self::matcher(static fn(RouteSet $r) => $r->add('/assets/{path+}', 'assets'));

        static::assertSame($expected, $matcher->match($path)?->params['path']);
    }

    public function testExactRouteTakesPrecedenceOverEmptyCatchAll(): void
    {
        $matcher = self::matcher(static function (RouteSet $r): void {
            $r->add('/docs', 'index');
            $r->add('/docs/{page*}', 'page');
        });

        static::assertSame('index', $matcher->match('/docs')?->route);
        static::assertSame('page', $matcher->match('/docs/')?->route);
    }

    public function testCatchAllAfterParameter(): void
    {
        $matcher = self::matcher(static fn(RouteSet $r) => $r->add('/repo/{name}/{path*}', 'tree'));

        static::assertSame(
            ['name' => 'router', 'path' => 'src/Matcher.php'],
            $matcher->match('/repo/router/src/Matcher.php')?->params,
        );
    }

    public function testParametersAcrossSeveralLevels(): void
    {
        $matcher = self::matcher(static fn(RouteSet $r) => $r->add('/tenants/{tenant}/users/{id}', 'user'));

        $match = self::mustMatch($matcher, '/tenants/acme/users/7');
        static::assertSame('user', $match->route);
        static::assertSame(['tenant' => 'acme', 'id' => '7'], $match->params);
    }

    public function testMetadataIsReturnedUntouched(): void
    {
        $metadata = ['handler' => ['UserController', 'show'], 'middleware' => ['auth'], 'methods' => ['GET']];
        $matcher = self::matcher(static fn(RouteSet $r) => $r->add('/users/{id}', $metadata));

        static::assertSame($metadata, $matcher->match('/users/1')?->route);
    }
}
