<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SilenZ\Segmatch\Compiler;
use SilenZ\Segmatch\Exception\InvalidRouteException;
use SilenZ\Segmatch\RouteDefinition;
use stdClass;

use function preg_quote;

final class CompilerTest extends TestCase
{
    /**
     * @return iterable<string, array{Closure(): list<mixed>, string}>
     */
    public static function invalidDeclarationProvider(): iterable
    {
        yield 'missing leading slash' => [
            static fn(): array => [new RouteDefinition('users', 'x')],
            'must start with "/"',
        ];
        yield 'empty segment' => [
            static fn(): array => [new RouteDefinition('/a//b', 'x')],
            'empty segment',
        ];
        yield 'partial placeholder' => [
            static fn(): array => [new RouteDefinition('/file.{ext}', 'x')],
            'invalid placeholder',
        ];
        yield 'invalid parameter name' => [
            static fn(): array => [new RouteDefinition('/{1st}', 'x')],
            'invalid placeholder',
        ];
        yield 'duplicate parameter name' => [
            static fn(): array => [new RouteDefinition('/{id}/{id}', 'x')],
            'more than once',
        ];
        yield 'catch-all not last' => [
            static fn(): array => [new RouteDefinition('/{path*}/edit', 'x')],
            'must be the last segment',
        ];
        yield 'mixed catch-all kinds on one node' => [
            static fn(): array => [
                new RouteDefinition('/files/{a*}', 'a'),
                new RouteDefinition('/files/{b+}', 'b'),
            ],
            'Catch-all route "/files/{b+}" conflicts with catch-all route "/files/{a*}"',
        ];
        yield 'object in route metadata' => [
            static fn(): array => [new RouteDefinition('/users', ['handler' => new stdClass()])],
            'Metadata of route "/users" contains a value of type stdClass',
        ];
        yield 'closure nested in route metadata' => [
            static fn(): array => [new RouteDefinition('/users', ['middleware' => [static fn() => null]])],
            'Metadata of route "/users" contains a value of type Closure',
        ];
        yield 'something other than a route definition' => [
            static fn(): array => [new RouteDefinition('/a', 'a'), '/b'],
            'Routes must be given as ' . RouteDefinition::class . ' instances, got string',
        ];
    }

    /**
     * @param Closure(): list<mixed> $define
     */
    #[DataProvider('invalidDeclarationProvider')]
    public function testRejectsInvalidDeclarations(Closure $define, string $message): void
    {
        $this->expectException(InvalidRouteException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($message, delimiter: '/') . '/');

        Compiler::compile($define());
    }

    public function testCompilesToFlatNodeTable(): void
    {
        $routes = [
            new RouteDefinition('/api/users', 'users'),
            new RouteDefinition('/api/users/{id}', 'user'),
            new RouteDefinition('/api/health', 'health'),
            new RouteDefinition('/api/files/{path+}', 'files'),
            new RouteDefinition('/about', 'about'),
            new RouteDefinition('/api/users', 'create-user'),
            new RouteDefinition('/api/users/{name}', 'user-by-name'),
        ];

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
