<?php

declare(strict_types=1);

namespace silenz\PhpRouter;

use InvalidArgumentException;
use silenz\PhpRouter\Internal\Layout;

use function array_pop;
use function array_slice;
use function count;
use function explode;
use function implode;
use function rawurldecode;
use function sprintf;
use function substr;

/**
 * Matches request paths against a compiled route table.
 *
 * Precedence at every node is static > parameter > catch-all, with backtracking: if the static branch
 * fails further down, the parameter and then the catch-all branch of the same node are tried.
 *
 * @psalm-import-type CompiledNode from Compiler
 * @psalm-import-type CompiledRoute from Compiler
 * @psalm-import-type CompiledRoutes from Compiler
 */
final readonly class Matcher
{
    // What to try next at the current node.
    private const int ENTER = 0;
    private const int STATIC = 1;
    private const int PARAM = 2;
    private const int CATCH = 3;
    private const int EXHAUSTED = 4;

    /** @var array<array-key, int> full path => route id, for routes without parameters */
    private array $static;

    /** @var list<CompiledNode> */
    private array $nodes;

    /** @var list<CompiledRoute> */
    private array $routes;

    /**
     * @param CompiledRoutes $compiled output of {@see Compiler::compile()}
     */
    public function __construct(array $compiled)
    {
        if ($compiled['version'] !== Layout::FORMAT_VERSION) {
            throw new InvalidArgumentException(sprintf(
                'Compiled routes have format version %d, expected %d; recompile them.',
                $compiled['version'],
                Layout::FORMAT_VERSION,
            ));
        }

        $this->static = $compiled['static'];
        $this->nodes = $compiled['nodes'];
        $this->routes = $compiled['routes'];
    }

    /**
     * @param string $path request path without query string, starting with "/"
     */
    public function match(string $path): ?RouteMatch
    {
        // Routes without parameters are answered by a single hash lookup.
        $static = $this->static[$path] ?? Layout::NONE;
        if ($static !== Layout::NONE) {
            return new RouteMatch($this->routes[$static][Layout::ROUTE_METADATA], []);
        }

        if ($path === '' || $path[0] !== '/') {
            return null;
        }

        $nodes = $this->nodes;
        $segments = explode('/', substr($path, offset: 1));
        $count = count($segments);

        /** @var array<int, string> $values parameter values by slot; slots past $paramCount are stale */
        $values = [];
        $paramCount = 0;
        /** @var list<array{int, int, int, int}> $stack node, segment index, next stage, param count */
        $stack = [];

        $node = 0;
        $index = 0;
        $stage = self::ENTER;

        while (true) {
            $current = $nodes[$node];

            if ($stage === self::ENTER) {
                $stage = self::STATIC;
                if ($index === $count) {
                    $route = $current[Layout::NODE_ROUTE];
                    if ($route !== Layout::NONE) {
                        return $this->result($route, $values);
                    }

                    $catch = $current[Layout::NODE_CATCH];
                    if ($catch !== Layout::NONE && $current[Layout::NODE_CATCH_MIN] === 0) {
                        $values[$paramCount] = '';

                        return $this->result($catch, $values);
                    }

                    $stage = self::EXHAUSTED;
                }
            }

            if ($stage === self::STATIC) {
                $child = $current[Layout::NODE_STATIC][$segments[$index]] ?? Layout::NONE;
                if ($child !== Layout::NONE) {
                    if (
                        $current[Layout::NODE_PARAM] !== Layout::NONE
                        || $current[Layout::NODE_CATCH] !== Layout::NONE
                    ) {
                        $stack[] = [$node, $index, self::PARAM, $paramCount];
                    }

                    $node = $child;
                    $index++;
                    $stage = self::ENTER;
                    continue;
                }

                $stage = self::PARAM;
            }

            if ($stage === self::PARAM) {
                $param = $current[Layout::NODE_PARAM];
                $segment = $segments[$index];
                if ($param !== Layout::NONE && $segment !== '') {
                    if ($current[Layout::NODE_CATCH] !== Layout::NONE) {
                        $stack[] = [$node, $index, self::CATCH, $paramCount];
                    }

                    $values[$paramCount++] = $segment;
                    $node = $param;
                    $index++;
                    $stage = self::ENTER;
                    continue;
                }

                $stage = self::CATCH;
            }

            if ($stage === self::CATCH) {
                $catch = $current[Layout::NODE_CATCH];
                if ($catch !== Layout::NONE) {
                    $rest = implode('/', array_slice($segments, $index));
                    if ($rest !== '' || $current[Layout::NODE_CATCH_MIN] === 0) {
                        $values[$paramCount] = $rest;

                        return $this->result($catch, $values);
                    }
                }
            }

            $frame = array_pop($stack);
            if ($frame === null) {
                return null;
            }

            [$node, $index, $stage, $paramCount] = $frame;
        }
    }

    /**
     * @param array<int, string> $values
     */
    private function result(int $routeId, array $values): RouteMatch
    {
        $route = $this->routes[$routeId];

        $params = [];
        foreach ($route[Layout::ROUTE_PARAMS] as $position => $name) {
            $params[$name] = rawurldecode($values[$position]);
        }

        return new RouteMatch($route[Layout::ROUTE_METADATA], $params);
    }
}
