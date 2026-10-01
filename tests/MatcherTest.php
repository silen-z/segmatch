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

    /**
     * @param null|Closure(mixed, array<string, string>): bool $guard
     */
    private static function find(Matcher $matcher, string $path, ?Closure $guard = null): ?RouteMatch
    {
        $result = $matcher->match($path, $guard);

        return $result instanceof RouteMatch ? $result : null;
    }

    private static function mustMatch(Matcher $matcher, string $path): RouteMatch
    {
        $match = self::find($matcher, $path);
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

        static::assertSame('home', self::find($matcher, '/')?->route);
        static::assertSame('users', self::find($matcher, '/users')?->route);
        static::assertSame('active', self::find($matcher, '/users/active')?->route);
        static::assertNull(self::find($matcher, '/users/inactive'));
        static::assertNull(self::find($matcher, '/missing'));
    }

    public function testRejectsPathsWithoutLeadingSlash(): void
    {
        $matcher = self::matcher(static fn(RouteSet $r) => $r->add('/', 'home'));

        static::assertNull(self::find($matcher, ''));
        static::assertNull(self::find($matcher, 'users'));
    }

    public function testTrailingSlashIsSignificant(): void
    {
        $matcher = self::matcher(static function (RouteSet $r): void {
            $r->add('/foo', 'no-slash');
            $r->add('/bar/', 'slash');
        });

        static::assertSame('no-slash', self::find($matcher, '/foo')?->route);
        static::assertNull(self::find($matcher, '/foo/'));
        static::assertSame('slash', self::find($matcher, '/bar/')?->route);
        static::assertNull(self::find($matcher, '/bar'));
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

        static::assertSame(['term' => 'a b/c'], self::find($matcher, '/search/a%20b%2Fc')?->params);
    }

    public function testParameterDoesNotMatchEmptySegment(): void
    {
        $matcher = self::matcher(static fn(RouteSet $r) => $r->add('/users/{id}', 'user'));

        static::assertNull(self::find($matcher, '/users/'));
    }

    public function testParameterNamesMayDifferAcrossRoutesSharingANode(): void
    {
        $matcher = self::matcher(static function (RouteSet $r): void {
            $r->add('/items/{id}/edit', 'edit');
            $r->add('/items/{slug}/view', 'view');
        });

        static::assertSame(['id' => '1'], self::find($matcher, '/items/1/edit')?->params);
        static::assertSame(['slug' => 'x'], self::find($matcher, '/items/x/view')?->params);
    }

    public function testStaticTakesPrecedenceOverParameter(): void
    {
        $matcher = self::matcher(static function (RouteSet $r): void {
            $r->add('/users/{id}', 'user');
            $r->add('/users/me', 'me');
        });

        static::assertSame('me', self::find($matcher, '/users/me')?->route);
        static::assertSame('user', self::find($matcher, '/users/42')?->route);
    }

    public function testStaticRouteDoesNotBlockLongerParameterRoute(): void
    {
        // "/users/me" is answered from the static table and its tree node is pruned, so a longer
        // request must still reach the parameter branch.
        $matcher = self::matcher(static function (RouteSet $r): void {
            $r->add('/users/me', 'me');
            $r->add('/users/{id}/edit', 'edit');
        });

        static::assertSame('me', self::find($matcher, '/users/me')?->route);
        static::assertSame(['id' => 'me'], self::find($matcher, '/users/me/edit')?->params);
        static::assertNull(self::find($matcher, '/users/me/'));
    }

    public function testParameterTakesPrecedenceOverCatchAll(): void
    {
        $matcher = self::matcher(static function (RouteSet $r): void {
            $r->add('/files/{rest*}', 'catch');
            $r->add('/files/{name}', 'param');
        });

        static::assertSame('param', self::find($matcher, '/files/a')?->route);
        static::assertSame('catch', self::find($matcher, '/files/a/b')?->route);
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

        static::assertSame('static', self::find($matcher, '/a/b/c')?->route);
        static::assertSame('param', self::find($matcher, '/a/b/d')?->route);

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

        static::assertSame(['c' => 'one'], self::find($matcher, '/one/y')?->params);
        static::assertSame(['a' => 'one', 'b' => 'two'], self::find($matcher, '/one/two/x')?->params);
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

        static::assertSame($expected, self::find($matcher, $path)?->params['path']);
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

        static::assertSame($expected, self::find($matcher, $path)?->params['path']);
    }

    public function testExactRouteTakesPrecedenceOverEmptyCatchAll(): void
    {
        $matcher = self::matcher(static function (RouteSet $r): void {
            $r->add('/docs', 'index');
            $r->add('/docs/{page*}', 'page');
        });

        static::assertSame('index', self::find($matcher, '/docs')?->route);
        static::assertSame('page', self::find($matcher, '/docs/')?->route);
    }

    public function testCatchAllAfterParameter(): void
    {
        $matcher = self::matcher(static fn(RouteSet $r) => $r->add('/repo/{name}/{path*}', 'tree'));

        static::assertSame(
            ['name' => 'router', 'path' => 'src/Matcher.php'],
            self::find($matcher, '/repo/router/src/Matcher.php')?->params,
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

        static::assertSame($metadata, self::find($matcher, '/users/1')?->route);
    }
}
