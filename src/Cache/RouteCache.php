<?php

declare(strict_types=1);

namespace silenz\PhpRouter\Cache;

use silenz\PhpRouter\Compiler;

/**
 * Storage for compiled routes, addressed by a cache key.
 *
 * Implementations only store and return the data. {@see \silenz\PhpRouter\Router} decides when to
 * compile and rejects entries written by an incompatible version of the router.
 *
 * @psalm-import-type CompiledRoutes from Compiler
 */
interface RouteCache
{
    /**
     * @return array<array-key, mixed>|null the stored compiled routes, or null when there are none
     */
    public function get(string $key): ?array;

    /**
     * @param CompiledRoutes $compiled
     */
    public function set(string $key, array $compiled): void;
}
