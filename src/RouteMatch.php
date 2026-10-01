<?php

declare(strict_types=1);

namespace SilenZ\Segmatch;

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
