<?php

declare(strict_types=1);

namespace silenz\PhpRouter\Tests;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use silenz\PhpRouter\Compiler;
use silenz\PhpRouter\Exception\InvalidRouteException;
use silenz\PhpRouter\RouteSet;
use stdClass;

use function preg_quote;

final class CompilerTest extends TestCase
{
    /**
     * @return iterable<string, array{Closure(RouteSet): void, string}>
     */
    public static function invalidDeclarationProvider(): iterable
    {
        yield 'missing leading slash' => [
            static fn(RouteSet $r) => $r->add('users', 'x'),
            'must start with "/"',
        ];
        yield 'empty segment' => [
            static fn(RouteSet $r) => $r->add('/a//b', 'x'),
            'empty segment',
        ];
        yield 'partial placeholder' => [
            static fn(RouteSet $r) => $r->add('/file.{ext}', 'x'),
            'invalid placeholder',
        ];
        yield 'invalid parameter name' => [
            static fn(RouteSet $r) => $r->add('/{1st}', 'x'),
            'invalid placeholder',
        ];
        yield 'duplicate parameter name' => [
            static fn(RouteSet $r) => $r->add('/{id}/{id}', 'x'),
            'more than once',
        ];
        yield 'catch-all not last' => [
            static fn(RouteSet $r) => $r->add('/{path*}/edit', 'x'),
            'must be the last segment',
        ];
        yield 'mixed catch-all kinds on one node' => [
            static function (RouteSet $r): void {
                $r->add('/files/{a*}', 'a');
                $r->add('/files/{b+}', 'b');
            },
            'Catch-all route "/files/{b+}" conflicts with catch-all route "/files/{a*}"',
        ];
        yield 'object in route metadata' => [
            static fn(RouteSet $r) => $r->add('/users', ['handler' => new stdClass()]),
            'Metadata of route "/users" contains a value of type stdClass',
        ];
        yield 'closure nested in route metadata' => [
            static fn(RouteSet $r) => $r->add('/users', ['middleware' => [static fn() => null]]),
            'Metadata of route "/users" contains a value of type Closure',
        ];
    }

    /**
     * @param Closure(RouteSet): void $define
     */
    #[DataProvider('invalidDeclarationProvider')]
    public function testRejectsInvalidDeclarations(Closure $define, string $message): void
    {
        $this->expectException(InvalidRouteException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($message, delimiter: '/') . '/');

        $routes = new RouteSet();
        $define($routes);
        Compiler::compile($routes);
    }

    public function testCompilesToFlatNodeTable(): void
    {
        $routes = new RouteSet();
        $routes->add('/api/users', 'users');
        $routes->add('/api/users/{id}', 'user');
        $routes->add('/api/health', 'health');
        $routes->add('/api/files/{path+}', 'files');
        $routes->add('/about', 'about');
        $routes->add('/api/users', 'create-user');
        $routes->add('/api/users/{name}', 'user-by-name');

        static::assertSame(
            [
                'version' => 6,
                // Parameterless routes are looked up by full path. A single route is stored as its
                // id; routes sharing a path as a list in declaration order.
                'static' => [
                    '/api/users' => [0, 5],
                    '/api/health' => 2,
                    '/about' => 4,
                ],
                // "/about" and "/api/health" are pruned from the tree; "/api/users" (node 2) stays as
                // the parent of {id}, but carries no route. {id} and {name} share node 4.
                'edges' => [
                    0 => ['api' => 1],
                    1 => ['users' => 2, 'files' => 3],
                ],
                'param' => [2 => 4],
                'catch' => [3 => 3],
                'catchRequired' => [3 => true],
                'routes' => [4 => [1, 6]],
                'metadata' => ['users', 'user', 'health', 'files', 'about', 'create-user', 'user-by-name'],
                'paramNames' => [1 => ['id'], 3 => ['path'], 6 => ['name']],
            ],
            Compiler::compile($routes),
        );
    }
}
