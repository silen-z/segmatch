<?php

declare(strict_types=1);

namespace silenz\PhpRouter\Internal;

/**
 * @internal
 */
final readonly class RouteDefinition
{
    /**
     * @param non-empty-list<Segment> $segments
     * @param list<int> $groups indexes of the enclosing groups, outermost first
     */
    public function __construct(
        public string $path,
        public array $segments,
        public mixed $metadata,
        public array $groups,
    ) {}
}
