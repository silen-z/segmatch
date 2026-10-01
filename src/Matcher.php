<?php

declare(strict_types=1);

namespace silenz\PhpRouter;

use Closure;
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

    /** @var array<array-key, non-empty-list<int>> full path => route ids, for routes without parameters */
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
     * Finds the route for a path.
     *
     * Without a guard, the first declared route of the best path wins. With a guard, every candidate
     * is offered to it in precedence order (and, among routes with the same path, declaration order);
     * a rejected route is treated as if it did not exist, so matching continues and may backtrack into
     * parameter and catch-all branches.
     *
     * A guard decides whether a route applies to the request: its HTTP method, host, content type,
     * parameter format, a feature switch. It must not check who is asking (authentication, permissions):
     * that is middleware's job after matching, since a rejected route can fall through to another one.
     * Guards may be called several times per match, so they must be cheap and free of side effects.
     *
     * @param string $path request path without query string, starting with "/"
     * @param null|Closure(mixed, array<string, string>): bool $guard receives route metadata and
     *     URL-decoded parameters, returns whether the route applies
     */
    public function match(string $path, ?Closure $guard = null): RouteMatch|NoMatch
    {
        /** @var list<int> $rejected */
        $rejected = [];

        // Routes without parameters are answered by a single hash lookup.
        $static = $this->static[$path] ?? null;
        if ($static !== null) {
            if ($guard === null) {
                return new RouteMatch($this->routes[$static[0]][Layout::ROUTE_METADATA], []);
            }

            $match = $this->select($static, [], $guard, $rejected);
            if ($match !== null) {
                return $match;
            }

            // Every route of this path was rejected; parameter routes in the tree may still apply.
        }

        if ($path === '' || $path[0] !== '/') {
            return $this->miss($rejected);
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
                    $routes = $current[Layout::NODE_ROUTE];
                    if ($routes !== []) {
                        $match = $guard === null
                            ? $this->result($routes[0], $values)
                            : $this->select($routes, $values, $guard, $rejected);
                        if ($match !== null) {
                            return $match;
                        }
                    }

                    $catch = $current[Layout::NODE_CATCH];
                    if ($catch !== [] && $current[Layout::NODE_CATCH_MIN] === 0) {
                        $values[$paramCount] = '';
                        $match = $guard === null
                            ? $this->result($catch[0], $values)
                            : $this->select($catch, $values, $guard, $rejected);
                        if ($match !== null) {
                            return $match;
                        }
                    }

                    $stage = self::EXHAUSTED;
                }
            }

            if ($stage === self::STATIC) {
                $child = $current[Layout::NODE_STATIC][$segments[$index]] ?? Layout::NONE;
                if ($child !== Layout::NONE) {
                    if ($current[Layout::NODE_PARAM] !== Layout::NONE || $current[Layout::NODE_CATCH] !== []) {
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
                    if ($current[Layout::NODE_CATCH] !== []) {
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
                if ($catch !== []) {
                    $rest = implode('/', array_slice($segments, $index));
                    if ($rest !== '' || $current[Layout::NODE_CATCH_MIN] === 0) {
                        $values[$paramCount] = $rest;
                        $match = $guard === null
                            ? $this->result($catch[0], $values)
                            : $this->select($catch, $values, $guard, $rejected);
                        if ($match !== null) {
                            return $match;
                        }
                    }
                }
            }

            $frame = array_pop($stack);
            if ($frame === null) {
                return $this->miss($rejected);
            }

            [$node, $index, $stage, $paramCount] = $frame;
        }
    }

    /**
     * Offers candidates to the guard in declaration order; the rejected ones are collected.
     *
     * @param non-empty-list<int> $candidates
     * @param array<int, string> $values
     * @param Closure(mixed, array<string, string>): bool $guard
     * @param list<int> $rejected
     */
    private function select(array $candidates, array $values, Closure $guard, array &$rejected): ?RouteMatch
    {
        foreach ($candidates as $routeId) {
            $match = $this->result($routeId, $values);
            if ($guard($match->route, $match->params)) {
                return $match;
            }

            $rejected[] = $routeId;
        }

        return null;
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

    /**
     * @param list<int> $rejected
     */
    private function miss(array $rejected): NoMatch
    {
        $metadata = [];
        foreach ($rejected as $routeId) {
            $metadata[] = $this->routes[$routeId][Layout::ROUTE_METADATA];
        }

        return new NoMatch($metadata);
    }
}
