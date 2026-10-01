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

    /** Index of the route attached through a catch-all edge. */
    public ?int $catchRoute = null;

    public ?SegmentType $catchType = null;

    /** Index of the route terminating at this node. */
    public ?int $route = null;

    /** Index of the group whose prefix ends at this node. */
    public ?int $group = null;
}
