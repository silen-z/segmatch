<?php

declare(strict_types=1);

namespace silenz\PhpRouter\Internal;

/**
 * @internal
 */
final readonly class GroupDefinition
{
    /**
     * @param non-empty-list<Segment> $segments
     * @param list<int> $parents indexes of the enclosing groups, outermost first
     */
    public function __construct(
        public string $prefix,
        public array $segments,
        public mixed $metadata,
        public array $parents,
    ) {}
}
