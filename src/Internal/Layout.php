<?php

declare(strict_types=1);

namespace silenz\PhpRouter\Internal;

/**
 * Field positions of the compiled runtime structure, shared by the compiler and the matcher.
 *
 * A compiled node is a list:
 *
 *     [
 *         NODE_STATIC    => ['users' => 12, 'posts' => 17], // segment => child node id
 *         NODE_PARAM     => 3,   // child node id of the {param} edge, or NONE
 *         NODE_CATCH     => 7,   // route id(s) of the catch-all edge, or NONE
 *         NODE_CATCH_MIN => 0,   // 0 for {name*}, 1 for {name+}
 *         NODE_ROUTE     => [42, 43], // route id(s) terminating at this node, or NONE
 *     ]
 *
 * Route id fields hold NONE, a single id, or a list of ids in declaration order when several routes
 * share a path. The static table uses the same form.
 *
 * A compiled route is a list:
 *
 *     [
 *         ROUTE_METADATA => [...],     // the route's metadata, untouched
 *         ROUTE_PARAMS   => ['id'],    // parameter names in capture order
 *     ]
 *
 * Routes without any parameter bypass the tree entirely through the static table, which maps the
 * full path to its route id(s).
 *
 * @internal
 */
final class Layout
{
    public const int FORMAT_VERSION = 5;

    public const int NONE = -1;

    public const int NODE_STATIC = 0;
    public const int NODE_PARAM = 1;
    public const int NODE_CATCH = 2;
    public const int NODE_CATCH_MIN = 3;
    public const int NODE_ROUTE = 4;

    public const int ROUTE_METADATA = 0;
    public const int ROUTE_PARAMS = 1;
}
