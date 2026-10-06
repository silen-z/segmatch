<?php

declare(strict_types=1);

namespace SilenZ\Segmatch;

/**
 * A named parameter in a route's path, in the order it appears ({@see RouteDefinition::$parameters}).
 *
 * `required` is `false` only for a zero-or-more catch-all (`{name*}`); every other parameter kind,
 * including a one-or-more catch-all (`{name+}`), must be present to match the route at all.
 */
final readonly class PathParameter
{
    public function __construct(
        public string $name,
        public bool $required,
    ) {}
}
