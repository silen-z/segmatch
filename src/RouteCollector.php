<?php

declare(strict_types=1);

namespace silenz\PhpRouter;

use Closure;
use silenz\PhpRouter\Exception\InvalidRouteException;
use silenz\PhpRouter\Internal\GroupDefinition;
use silenz\PhpRouter\Internal\PathParser;
use silenz\PhpRouter\Internal\RouteDefinition;

use function count;
use function sprintf;
use function str_ends_with;
use function str_starts_with;

/**
 * Build-time API for declaring routes and groups.
 *
 * The router never interprets metadata. Route metadata is whatever the application needs to dispatch
 * (handler, allowed methods, ...); group metadata is whatever applies to every route inside the group
 * (typically middleware). Metadata ends up in the compiled PHP file, so it must consist of scalars,
 * null, enums and arrays of those.
 */
final class RouteCollector
{
    /** @var list<RouteDefinition> */
    private array $routes = [];

    /** @var list<GroupDefinition> */
    private array $groups = [];

    private string $prefix = '';

    /** @var list<int> */
    private array $stack = [];

    /**
     * Declares a route. Inside a group the path is appended to the group prefix and may be empty,
     * which declares a route on the prefix itself.
     *
     * @throws InvalidRouteException
     */
    public function add(string $path, mixed $metadata): void
    {
        if (!($path === '' && $this->stack !== []) && !str_starts_with($path, '/')) {
            throw new InvalidRouteException(sprintf('Route path "%s" must start with "/".', $path));
        }

        $full = $this->prefix . $path;
        $this->routes[] = new RouteDefinition($full, PathParser::parse($full), $metadata, $this->stack);
    }

    /**
     * Declares a group of routes sharing a path prefix. Group metadata is returned with every match
     * that passes through the group; `null` means the group carries no metadata.
     *
     * Each group prefix must be unique, and every route below a group prefix must be declared inside
     * that group, so that group metadata never leaks onto unrelated routes.
     *
     * @param Closure(RouteCollector): void $define
     *
     * @throws InvalidRouteException
     */
    public function group(string $prefix, Closure $define, mixed $metadata = null): void
    {
        if ($prefix === '' || $prefix === '/') {
            throw new InvalidRouteException('Group prefix must not be empty.');
        }

        if (!str_starts_with($prefix, '/') || str_ends_with($prefix, '/')) {
            throw new InvalidRouteException(sprintf(
                'Group prefix "%s" must start with "/" and must not end with "/".',
                $prefix,
            ));
        }

        $full = $this->prefix . $prefix;
        $segments = PathParser::parse($full);
        $last = $segments[count($segments) - 1];
        if ($last->type->isCatchAll()) {
            throw new InvalidRouteException(sprintf(
                'Group prefix "%s" must not end with a catch-all parameter.',
                $full,
            ));
        }

        $index = count($this->groups);
        $this->groups[] = new GroupDefinition($full, $segments, $metadata, $this->stack);

        $previousPrefix = $this->prefix;
        $previousStack = $this->stack;
        $this->prefix = $full;
        $this->stack[] = $index;

        try {
            $define($this);
        } finally {
            $this->prefix = $previousPrefix;
            $this->stack = $previousStack;
        }
    }

    /**
     * @internal
     *
     * @return list<RouteDefinition>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * @internal
     *
     * @return list<GroupDefinition>
     */
    public function groups(): array
    {
        return $this->groups;
    }
}
