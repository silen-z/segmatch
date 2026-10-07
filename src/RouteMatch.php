<?php

declare(strict_types=1);

namespace SilenZ\Segmatch;

/**
 * A route {@see Matcher::match()} accepted (or, inside a filter or {@see NoMatch::$rejected}, is
 * offering as a candidate): its metadata, as declared, and the request path's parameter values.
 */
final readonly class RouteMatch
{
    /**
     * @param mixed $route metadata of the matched route
     * @param array<string, string> $params URL-decoded parameter values by name
     */
    public function __construct(
        public mixed $route,
        public array $params,
    ) {}
}
