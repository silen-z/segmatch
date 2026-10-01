<?php

declare(strict_types=1);

namespace silenz\PhpRouter\Internal;

/**
 * @internal
 */
final readonly class Segment
{
    /**
     * @param string $value literal text for static segments, parameter name otherwise
     */
    public function __construct(
        public SegmentType $type,
        public string $value,
    ) {}
}
