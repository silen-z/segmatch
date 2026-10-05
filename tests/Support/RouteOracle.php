<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Support;

use Closure;

use function array_key_exists;
use function array_slice;
use function count;
use function explode;
use function implode;
use function preg_match;
use function rawurldecode;
use function str_starts_with;
use function substr;
use function usort;

/**
 * Brute-force reference implementation of the documented matching rules.
 *
 * Every route is checked against the request segment by segment. Matching routes are ordered by
 * precedence (per segment static < parameter < catch-all, a route ending earlier first, then
 * declaration order) and offered to the filter in that order. No tree, no static table, no
 * backtracking.
 */
final class RouteOracle
{
    /**
     * @param list<string> $routes route paths; a route's metadata is its index
     * @param null|Closure(int): bool $filter
     *
     * @return array{route: ?int, params: array<string, string>, rejected: list<int>}
     */
    public static function match(array $routes, string $path, ?Closure $filter): array
    {
        $candidates = [];
        if (str_starts_with($path, '/')) {
            $request = explode('/', substr($path, offset: 1));
            foreach ($routes as $index => $route) {
                $match = self::matchRoute(explode('/', substr($route, offset: 1)), $request);
                if ($match !== null) {
                    $candidates[] = ['index' => $index, 'key' => $match['key'], 'params' => $match['params']];
                }
            }
        }

        usort($candidates, static function (array $a, array $b): int {
            $byKey = self::compareKeys($a['key'], $b['key']);

            return $byKey !== 0 ? $byKey : $a['index'] <=> $b['index'];
        });

        $rejected = [];
        foreach ($candidates as $candidate) {
            if ($filter === null || $filter($candidate['index'])) {
                return ['route' => $candidate['index'], 'params' => $candidate['params'], 'rejected' => []];
            }

            $rejected[] = $candidate['index'];
        }

        return ['route' => null, 'params' => [], 'rejected' => $rejected];
    }

    /**
     * Lexicographic order; a key that is a prefix of the other sorts first. (PHP's `<=>` on arrays
     * compares their length first, which is not what precedence means.)
     *
     * @param list<int> $a
     * @param list<int> $b
     */
    private static function compareKeys(array $a, array $b): int
    {
        foreach ($a as $i => $value) {
            if (!array_key_exists($i, $b)) {
                return 1;
            }

            if ($value !== $b[$i]) {
                return $value <=> $b[$i];
            }
        }

        return count($a) <=> count($b);
    }

    /**
     * @param list<string> $pattern
     * @param list<string> $request
     *
     * @return null|array{key: list<int>, params: array<string, string>}
     */
    private static function matchRoute(array $pattern, array $request): ?array
    {
        $key = [];
        $params = [];
        foreach ($pattern as $i => $segment) {
            $placeholder = [];
            if (preg_match('/^\{(\w+)([*+])\}$/', $segment, $placeholder) === 1) {
                $rest = implode('/', array_slice($request, $i));
                if ($placeholder[2] === '+' && $rest === '') {
                    return null;
                }

                $key[] = 2;
                $params[$placeholder[1]] = rawurldecode($rest);

                return ['key' => $key, 'params' => $params];
            }

            if (!array_key_exists($i, $request)) {
                return null;
            }

            if (preg_match('/^\{(\w+)\}$/', $segment, $placeholder) === 1) {
                if ($request[$i] === '') {
                    return null;
                }

                $key[] = 1;
                $params[$placeholder[1]] = rawurldecode($request[$i]);
                continue;
            }

            if ($segment !== $request[$i]) {
                return null;
            }

            $key[] = 0;
        }

        // A route ending here sorts before a catch-all continuing from here: [..] < [.., 2].
        return count($pattern) === count($request) ? ['key' => $key, 'params' => $params] : null;
    }
}
