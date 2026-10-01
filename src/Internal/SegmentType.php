<?php

declare(strict_types=1);

namespace silenz\PhpRouter\Internal;

/**
 * @internal
 */
enum SegmentType
{
    /** Literal text, consumes exactly one segment. */
    case Static;

    /** `{name}`, consumes exactly one non-empty segment. */
    case Param;

    /** `{name*}`, consumes the remaining zero or more segments. */
    case CatchAllZero;

    /** `{name+}`, consumes the remaining one or more segments (non-empty remainder). */
    case CatchAllOne;

    public function isCatchAll(): bool
    {
        return $this === self::CatchAllZero || $this === self::CatchAllOne;
    }
}
