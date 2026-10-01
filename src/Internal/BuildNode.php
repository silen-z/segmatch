<?php

declare(strict_types=1);

namespace silenz\PhpRouter\Internal;

/**
 * Mutable node of the compiler's intermediate tree. Never reaches the runtime.
 *
 * @internal
 */
final class BuildNode
{
    /** @var array<array-key, BuildNode> */
    public array $static = [];

    public ?BuildNode $param = null;

    /** @var list<int> indexes of the routes attached through a catch-all edge, in declaration order */
    public array $catchRoutes = [];

    public ?SegmentType $catchType = null;

    /** @var list<int> indexes of the routes terminating at this node, in declaration order */
    public array $routes = [];
}
