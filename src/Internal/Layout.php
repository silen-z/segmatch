<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Internal;

/**
 * The compiled runtime structure, shared by the compiler and the matcher.
 *
 * Compiled routes are a handful of flat tables. Tree tables are sparse maps keyed by node id (the
 * root is node 0); route tables are keyed by route id (declaration order):
 *
 *     'table'         => [...],                          // the route table's own metadata, never matched
 *     'static'        => ['/users' => 4, '/' => [0, 1]], // full path => route id(s), parameterless routes
 *     'edges'         => [0 => ['users' => 1]],          // node => static segment => child node
 *     'param'         => [1 => 2],                       // node => child node of its {param} edge
 *     'catch'         => [3 => [false, 7]],               // node => [needs a non-empty rest
 *                                                         // ({name+}, not {name*}), ...route ids]
 *     'routes'        => [2 => 5],                       // node => route id(s) ending there
 *     'metadata'      => [[...], ...],                   // route id => the route's metadata
 *     'paramNames'    => [5 => ['id']],                  // route id => parameter names, capture order
 *
 * 'table' is whatever {@see \SilenZ\Segmatch\RouteTable::metadata()} gave (null by default): it
 * belongs to the table as a whole, not to any route, so no node points to it.
 *
 * Route id(s) are a single id, or a list in declaration order when several routes share a path.
 *
 * A child node id in 'edges' or 'param' is stored negative (`-id - 1`, never NONE) when a miss past
 * that edge still has somewhere left to go on the *source* node (its {param} edge for 'edges', its
 * catch-all for 'param'): that flags the matcher to push a backtrack frame without a separate lookup.
 *
 * @internal
 */
final class Layout
{
    public const int FORMAT_VERSION = 10;

    public const int NONE = -1;
}
