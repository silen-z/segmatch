<?php

declare(strict_types=1);

namespace SilenZ\Segmatch;

use Closure;

/**
 * A {@see RouteTable} from a plain callable and cache key, for quick setups and tests that don't need
 * a dedicated class. `$cacheKey` defaults to `null` (never cached), matching how easy it is to forget
 * one when all you have is a closure.
 */
final class CallableRouteTable implements RouteTable
{
    /** @var Closure(): iterable<mixed, RouteDefinition> */
    private readonly Closure $definitions;

    /**
     * @param callable(): iterable<mixed, RouteDefinition> $definitions
     */
    public function __construct(
        callable $definitions,
        private readonly ?string $cacheKey = null,
    ) {
        $this->definitions = $definitions(...);
    }

    public function cacheKey(): ?string
    {
        return $this->cacheKey;
    }

    public function definitions(): iterable
    {
        return ($this->definitions)();
    }
}
