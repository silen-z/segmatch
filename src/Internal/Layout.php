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
 *         NODE_CATCH     => 7,   // route id of the catch-all edge, or NONE
 *         NODE_CATCH_MIN => 0,   // 0 for {name*}, 1 for {name+}
 *         NODE_ROUTE     => 42,  // route id terminating at this node, or NONE
 *         NODE_SCOPE     => 1,   // group metadata id attached to this node, or NONE
 *     ]
 *
 * @internal
 */
final class Layout
{
    public const int FORMAT_VERSION = 1;

    public const int NONE = -1;

    public const int NODE_STATIC = 0;
    public const int NODE_PARAM = 1;
    public const int NODE_CATCH = 2;
    public const int NODE_CATCH_MIN = 3;
    public const int NODE_ROUTE = 4;
    public const int NODE_SCOPE = 5;

    public const int ROUTE_METADATA = 0;
    public const int ROUTE_PARAMS = 1;
}
