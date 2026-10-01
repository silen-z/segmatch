<?php

declare(strict_types=1);

namespace silenz\PhpRouter;

final readonly class RouteMatch
{
    /**
     * @param mixed $route metadata of the matched route
     * @param list<mixed> $groups metadata of the groups the match passed through, outermost first
     * @param array<string, string> $params URL-decoded parameter values by name
     */
    public function __construct(
        public mixed $route,
        public array $groups,
        public array $params,
    ) {}
}
