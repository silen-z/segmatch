<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Internal;

/**
 * @internal
 */
final readonly class RouteDefinition
{
    /**
     * @param non-empty-list<Segment> $segments
     */
    public function __construct(
        public string $path,
        public array $segments,
        public mixed $metadata,
    ) {}
}
