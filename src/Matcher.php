<?php

declare(strict_types=1);

namespace SilenZ\Segmatch;

use InvalidArgumentException;
use SilenZ\Segmatch\Internal\Layout;

use function array_slice;
use function count;
use function explode;
use function implode;
use function is_int;
use function rawurldecode;
use function sprintf;
use function substr;

/**
 * Matches request paths against a compiled route table.
 *
 * Precedence at every node is static > parameter > catch-all, with backtracking: if the static branch
 * fails further down, the parameter and then the catch-all branch of the same node are tried.
 *
 * @psalm-import-type CompiledRoutes from Compiler
 * @psalm-import-type RouteIds from Compiler
 * @psalm-import-type CatchEntry from Compiler
 */
final readonly class Matcher
{
    // What to try next at the current node.
    private const int ENTER = 0;
    private const int STATIC = 1;
    private const int PARAM = 2;
    private const int CATCH = 3;
    private const int EXHAUSTED = 4;

    /** @var array<array-key, RouteIds> full path => route id(s), for routes without parameters */
    private array $static;

    /**
     * @var array<int, array<array-key, int>> node => static segment => child node, negative
     *     (`-id - 1`) when the node also has a {param} edge or a catch-all to fall back to
     */
    private array $edges;

    /**
     * @var array<int, int> node => child node of the {param} edge, negative (`-id - 1`) when the
     *     node also has a catch-all to fall back to
     */
    private array $param;

    /**
     * @var array<int, CatchEntry> node => its catch-all edge, flattened as [needs a non-empty rest
     *     ({name+}, not {name*}), ...route ids]
     */
    private array $catch;

    /** @var array<int, RouteIds> node => route id(s) ending there */
    private array $routes;

    /** @var list<mixed> route id => metadata */
    private array $metadata;

    /** @var array<int, non-empty-list<string>> route id => parameter names */
    private array $paramNames;

    private mixed $table;

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
        $this->edges = $compiled['edges'];
        $this->param = $compiled['param'];
        $this->catch = $compiled['catch'];
        $this->routes = $compiled['routes'];
        $this->metadata = $compiled['metadata'];
        $this->paramNames = $compiled['paramNames'];
        $this->table = $compiled['table'];
    }

    /**
     * The metadata of the route table as a whole, as {@see RouteTable::metadata()} gave it when the
     * routes were compiled — from the cache like everything else, so without declaring the routes
     * again. Never returned by matching: it belongs to no route.
     */
    public function tableMetadata(): mixed
    {
        return $this->table;
    }

    /**
     * The metadata of every route, by route id (declaration order), e.g. for building an index of
     * the routes by name.
     *
     * @return list<mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * Finds the route for a path.
     *
     * Without a filter, the first declared route of the best path wins. With a filter, every candidate
     * is offered to it in precedence order (and, among routes with the same path, declaration order);
     * a rejected route is treated as if it did not exist, so matching continues and may backtrack into
     * parameter and catch-all branches.
     *
     * A filter decides whether a route applies to the request: its HTTP method, host, content type,
     * parameter format, a feature switch. It must not check who is asking (authentication, permissions):
     * that is middleware's job after matching, since a rejected route can fall through to another one.
     * Filters may be called several times per match, so they must be cheap and free of side effects.
     *
     * @param string $path request path without query string, starting with "/"
     * @param null|false|callable(RouteMatch): bool $filter receives the candidate match, returns
     *     whether the route applies; `false` rejects every candidate without being called (see
     *     {@see matchAll()}); `null` skips filtering entirely so the first declared route wins
     */
    public function match(string $path, callable|false|null $filter = null): RouteMatch|NoMatch
    {
        /** @var list<RouteMatch> $rejected */
        $rejected = [];

        // Routes without parameters are answered by a single hash lookup.
        $static = $this->static[$path] ?? Layout::NONE;
        if ($static !== Layout::NONE) {
            if ($filter === null) {
                return new RouteMatch($this->metadata[is_int($static) ? $static : $static[0]], []);
            }

            $match = $this->select(is_int($static) ? [$static] : $static, [], $filter, $rejected);
            if ($match !== null) {
                return $match;
            }

            // Every route of this path was rejected; parameter routes in the tree may still apply.
        }

        if ($path === '' || $path[0] !== '/') {
            return $this->miss($rejected);
        }

        $edges = $this->edges;
        $paramEdges = $this->param;
        $catches = $this->catch;
        $ends = $this->routes;

        $segments = explode('/', substr($path, offset: 1));
        $count = count($segments);

        /** @var array<int, string> $values parameter values by slot; slots past $paramCount are stale */
        $values = [];
        $paramCount = 0;
        // Backtrack frames (node, segment index, next stage, param count) as parallel arrays instead
        // of a stack of tuples, so a push doesn't allocate a new small array.
        /** @var list<int> $stackNode */
        $stackNode = [];
        /** @var list<int> $stackIndex */
        $stackIndex = [];
        /** @var list<int> $stackStage */
        $stackStage = [];
        /** @var list<int> $stackParamCount */
        $stackParamCount = [];
        $stackSize = 0;

        $node = 0;
        $index = 0;
        $stage = self::ENTER;

        while (true) {
            if ($stage === self::ENTER) {
                $stage = self::STATIC;
                if ($index === $count) {
                    $routes = $ends[$node] ?? Layout::NONE;
                    if ($routes !== Layout::NONE) {
                        $match = $filter === null
                            ? $this->result(is_int($routes) ? $routes : $routes[0], $values)
                            : $this->select(is_int($routes) ? [$routes] : $routes, $values, $filter, $rejected);
                        if ($match !== null) {
                            return $match;
                        }
                    }

                    $catch = $catches[$node] ?? Layout::NONE;
                    if ($catch !== Layout::NONE && !$catch[0]) {
                        $values[$paramCount] = '';
                        $match = $filter === null
                            ? $this->result($catch[1], $values)
                            : $this->select($catch, $values, $filter, $rejected, offset: 1);
                        if ($match !== null) {
                            return $match;
                        }
                    }

                    $stage = self::EXHAUSTED;
                }
            }

            if ($stage === self::STATIC) {
                $child = $edges[$node][$segments[$index]] ?? Layout::NONE;
                if ($child !== Layout::NONE) {
                    if ($child < 0) {
                        $child = -$child - 1;
                        $stackNode[$stackSize] = $node;
                        $stackIndex[$stackSize] = $index;
                        $stackStage[$stackSize] = self::PARAM;
                        $stackParamCount[$stackSize] = $paramCount;
                        $stackSize++;
                    }

                    $node = $child;
                    $index++;
                    $stage = self::ENTER;
                    continue;
                }

                $stage = self::PARAM;
            }

            if ($stage === self::PARAM) {
                $param = $paramEdges[$node] ?? Layout::NONE;
                $segment = $segments[$index];
                if ($param !== Layout::NONE && $segment !== '') {
                    if ($param < 0) {
                        $param = -$param - 1;
                        $stackNode[$stackSize] = $node;
                        $stackIndex[$stackSize] = $index;
                        $stackStage[$stackSize] = self::CATCH;
                        $stackParamCount[$stackSize] = $paramCount;
                        $stackSize++;
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
                $catch = $catches[$node] ?? Layout::NONE;
                if ($catch !== Layout::NONE) {
                    $rest = implode('/', array_slice($segments, $index));
                    if ($rest !== '' || !$catch[0]) {
                        $values[$paramCount] = $rest;
                        $match = $filter === null
                            ? $this->result($catch[1], $values)
                            : $this->select($catch, $values, $filter, $rejected, offset: 1);
                        if ($match !== null) {
                            return $match;
                        }
                    }
                }
            }

            if ($stackSize === 0) {
                return $this->miss($rejected);
            }

            $stackSize--;
            $node = $stackNode[$stackSize];
            $index = $stackIndex[$stackSize];
            $stage = $stackStage[$stackSize];
            $paramCount = $stackParamCount[$stackSize];
        }
    }

    /**
     * Every candidate route for a path, rejected, e.g. to build a 405's `Allow` header without
     * matching for a specific request. Equivalent to `match($path, false)`, unwrapped.
     *
     * @param string $path request path without query string, starting with "/"
     *
     * @return list<RouteMatch>
     */
    public function matchAll(string $path): array
    {
        /** @var NoMatch */
        $result = $this->match($path, false);

        return $result->rejected;
    }

    /**
     * Offers candidates to the filter in declaration order, starting at $offset; the rejected ones
     * are collected. $offset lets a flattened {@see CatchEntry} be read in place, past its leading
     * flag, without slicing it into a new array first.
     *
     * @param non-empty-list<int>|CatchEntry $candidates a list of ids, or (with $offset) a flattened
     *     catch entry; callers normalize a single id into a one-element list first
     * @param array<int, string> $values
     * @param false|callable(RouteMatch): bool $filter `false` rejects every candidate without calling
     *     it, see {@see matchAll()}
     * @param list<RouteMatch> $rejected
     */
    private function select(
        array $candidates,
        array $values,
        callable|false $filter,
        array &$rejected,
        int $offset = 0,
    ): ?RouteMatch {
        for ($i = $offset, $count = count($candidates); $i < $count; $i++) {
            /** @var int $routeId */
            $routeId = $candidates[$i];
            $match = $this->result($routeId, $values);
            if ($filter !== false && $filter($match)) {
                return $match;
            }

            $rejected[] = $match;
        }

        return null;
    }

    /**
     * @param array<int, string> $values
     */
    private function result(int $routeId, array $values): RouteMatch
    {
        $params = [];
        foreach ($this->paramNames[$routeId] ?? [] as $position => $name) {
            $params[$name] = rawurldecode($values[$position]);
        }

        return new RouteMatch($this->metadata[$routeId], $params);
    }

    /**
     * @param list<RouteMatch> $rejected
     */
    private function miss(array $rejected): NoMatch
    {
        return new NoMatch($rejected);
    }
}
