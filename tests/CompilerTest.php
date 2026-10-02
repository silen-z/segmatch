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
                'version' => 9,
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
                'catch' => [3 => [true, 3]],
                'routes' => [4 => [1, 6]],
                'metadata' => ['users', 'user', 'health', 'files', 'about', 'create-user', 'user-by-name'],
                'paramNames' => [1 => ['id'], 3 => ['path'], 6 => ['name']],
            ],
            Compiler::compile($routes),
        );
    }

    public function testFlagsBacktrackInChildNodeIds(): void
    {
        $routes = [
            new RouteDefinition('/items/archive/{id}', 'archived'),
            new RouteDefinition('/items/{id}', 'item'),
            new RouteDefinition('/items/{rest*}', 'catch'),
        ];

        static::assertSame(
            [
                'version' => 9,
                'static' => [],
                // Node 1 ("items") has both a {id} param edge and a catch-all, so a miss past its
                // static "archive" edge (to node 2) must still try them: the child id is stored as
                // -2 - 1 = -3. Node 2 only has a param edge of its own (to node 4, {id} again), so
                // its child id (4) is stored as-is.
                'edges' => [
                    0 => ['items' => 1],
                    1 => ['archive' => -3],
                ],
                // Node 1's param edge (to node 3) carries the same flag for its catch-all: -3 - 1 = -4.
                'param' => [1 => -4, 2 => 4],
                'catch' => [1 => [false, 2]],
                'routes' => [3 => 1, 4 => 0],
                'metadata' => ['archived', 'item', 'catch'],
                'paramNames' => [0 => ['id'], 1 => ['id'], 2 => ['rest']],
            ],
            Compiler::compile($routes),
        );
    }

    public function testFlattensSeveralRoutesSharingACatchAll(): void
    {
        $routes = [
            new RouteDefinition('/files/{a*}', 'download'),
            new RouteDefinition('/files/{b*}', 'upload'),
            new RouteDefinition('/files/{c*}', 'delete'),
        ];

        $compiled = Compiler::compile($routes);

        // Node 1 ("files") carries all three route ids after the "needs a non-empty rest" flag,
        // flattened into the same list rather than nested as [false, [0, 1, 2]].
        static::assertSame([false, 0, 1, 2], $compiled['catch'][1]);
    }
}
