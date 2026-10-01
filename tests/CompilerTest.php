<?php

declare(strict_types=1);

namespace silenz\PhpRouter\Tests;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use silenz\PhpRouter\Compiler;
use silenz\PhpRouter\Exception\InvalidRouteException;
use silenz\PhpRouter\RouteCollector;
use stdClass;

use function preg_quote;

final class CompilerTest extends TestCase
{
    /**
     * @return iterable<string, array{Closure(RouteCollector): void, string}>
     */
    public static function invalidDeclarationProvider(): iterable
    {
        yield 'missing leading slash' => [
            static fn(RouteCollector $r) => $r->add('users', 'x'),
            'must start with "/"',
        ];
        yield 'empty segment' => [
            static fn(RouteCollector $r) => $r->add('/a//b', 'x'),
            'empty segment',
        ];
        yield 'partial placeholder' => [
            static fn(RouteCollector $r) => $r->add('/file.{ext}', 'x'),
            'invalid placeholder',
        ];
        yield 'invalid parameter name' => [
            static fn(RouteCollector $r) => $r->add('/{1st}', 'x'),
            'invalid placeholder',
        ];
        yield 'duplicate parameter name' => [
            static fn(RouteCollector $r) => $r->add('/{id}/{id}', 'x'),
            'more than once',
        ];
        yield 'catch-all not last' => [
            static fn(RouteCollector $r) => $r->add('/{path*}/edit', 'x'),
            'must be the last segment',
        ];
        yield 'duplicate route' => [
            static function (RouteCollector $r): void {
                $r->add('/users', 'a');
                $r->add('/users', 'b');
            },
            'conflicts with route "/users"',
        ];
        yield 'same shape with different parameter names' => [
            static function (RouteCollector $r): void {
                $r->add('/foo/{id}', 'a');
                $r->add('/foo/{name}', 'b');
            },
            'conflicts with route "/foo/{id}"',
        ];
        yield 'two catch-alls on one node' => [
            static function (RouteCollector $r): void {
                $r->add('/files/{a*}', 'a');
                $r->add('/files/{b+}', 'b');
            },
            'conflicts with catch-all route "/files/{a*}"',
        ];
        yield 'empty group prefix' => [
            static fn(RouteCollector $r) => $r->group('', static fn() => null),
            'must not be empty',
        ];
        yield 'root group prefix' => [
            static fn(RouteCollector $r) => $r->group('/', static fn() => null),
            'must not be empty',
        ];
        yield 'group prefix with trailing slash' => [
            static fn(RouteCollector $r) => $r->group('/api/', static fn() => null),
            'must not end with "/"',
        ];
        yield 'group prefix ending with catch-all' => [
            static fn(RouteCollector $r) => $r->group('/files/{path*}', static fn() => null),
            'must not end with a catch-all',
        ];
        yield 'duplicate group prefix' => [
            static function (RouteCollector $r): void {
                $r->group('/api', static fn(RouteCollector $r) => $r->add('/a', 'a'), 'one');
                $r->group('/api', static fn(RouteCollector $r) => $r->add('/b', 'b'), 'two');
            },
            'conflicts with group prefix "/api"',
        ];
        yield 'duplicate parameterised group prefix' => [
            static function (RouteCollector $r): void {
                $r->group('/users/{id}', static fn() => null);
                $r->group('/users/{name}', static fn() => null);
            },
            'conflicts with group prefix "/users/{id}"',
        ];
        yield 'route under group prefix declared outside' => [
            static function (RouteCollector $r): void {
                $r->group('/api', static fn(RouteCollector $r) => $r->add('/users', 'users'), 'auth');
                $r->add('/api/login', 'login');
            },
            'Route "/api/login" lies under group prefix "/api"',
        ];
        yield 'route declared before the group it lies under' => [
            static function (RouteCollector $r): void {
                $r->add('/api/login', 'login');
                $r->group('/api', static fn(RouteCollector $r) => $r->add('/users', 'users'), 'auth');
            },
            'Route "/api/login" lies under group prefix "/api"',
        ];
        yield 'route on group prefix declared outside' => [
            static function (RouteCollector $r): void {
                $r->group('/api', static fn() => null, 'auth');
                $r->add('/api', 'root');
            },
            'Route "/api" lies under group prefix "/api"',
        ];
        yield 'catch-all route on group node declared outside' => [
            static function (RouteCollector $r): void {
                $r->group('/assets', static fn() => null, 'cdn');
                $r->add('/assets/{path*}', 'assets');
            },
            'Route "/assets/{path*}" lies under group prefix "/assets"',
        ];
        yield 'group nested under another group from outside' => [
            static function (RouteCollector $r): void {
                $r->group('/api/v1', static fn() => null);
                $r->group('/api', static fn() => null);
            },
            'Group prefix "/api/v1" lies under group prefix "/api"',
        ];
        yield 'object in route metadata' => [
            static fn(RouteCollector $r) => $r->add('/users', ['handler' => new stdClass()]),
            'Metadata of route "/users" contains a value of type stdClass',
        ];
        yield 'closure in group metadata' => [
            static fn(RouteCollector $r) => $r->group('/api', static fn() => null, [static fn() => null]),
            'Metadata of group "/api" contains a value of type Closure',
        ];
    }

    /**
     * @param Closure(RouteCollector): void $define
     */
    #[DataProvider('invalidDeclarationProvider')]
    public function testRejectsInvalidDeclarations(Closure $define, string $message): void
    {
        $this->expectException(InvalidRouteException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($message, delimiter: '/') . '/');

        $collector = new RouteCollector();
        $define($collector);
        Compiler::compile($collector);
    }

    public function testCompilesToFlatNodeTable(): void
    {
        $collector = new RouteCollector();
        $collector->group(
            '/api',
            static function (RouteCollector $r): void {
                $r->add('/users/{id}', 'user');
                $r->add('/files/{path+}', 'files');
            },
            'auth',
        );

        $compiled = Compiler::compile($collector);

        static::assertSame(
            [
                'version' => 1,
                'nodes' => [
                    [['api' => 1], -1, -1, 0, -1, -1],
                    [['users' => 2, 'files' => 3], -1, -1, 0, -1, 0],
                    [[], 4, -1, 0, -1, -1],
                    [[], -1, 1, 1, -1, -1],
                    [[], -1, -1, 0, 0, -1],
                ],
                'routes' => [
                    ['user', ['id']],
                    ['files', ['path']],
                ],
                'groups' => ['auth'],
            ],
            $compiled,
        );
    }
}
