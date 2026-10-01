<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Internal;

/**
 * The compiled runtime structure, shared by the compiler and the matcher.
 *
 * Compiled routes are a handful of flat tables. Tree tables are sparse maps keyed by node id (the
 * root is node 0); route tables are keyed by route id (declaration order):
 *
 *     'static'        => ['/users' => 4, '/' => [0, 1]], // full path => route id(s), parameterless routes
 *     'edges'         => [0 => ['users' => 1]],          // node => static segment => child node
 *     'param'         => [1 => 2],                       // node => child node of its {param} edge
 *     'catch'         => [3 => 7],                       // node => route id(s) of its catch-all edge
 *     'catchRequired' => [3 => true],                    // nodes whose catch-all is {name+}, not {name*}
 *     'routes'        => [2 => 5],                       // node => route id(s) ending there
 *     'metadata'      => [[...], ...],                   // route id => the route's metadata
 *     'paramNames'    => [5 => ['id']],                  // route id => parameter names, capture order
 *
 * Route id(s) are a single id, or a list in declaration order when several routes share a path.
 *
 * @internal
 */
final class Layout
{
    public const int FORMAT_VERSION = 6;

    public const int NONE = -1;
}
