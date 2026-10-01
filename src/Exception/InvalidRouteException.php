<?php

declare(strict_types=1);

namespace silenz\PhpRouter\Exception;

use InvalidArgumentException;

/**
 * Thrown when a route or group declaration is malformed or conflicts with another declaration.
 */
final class InvalidRouteException extends InvalidArgumentException {}
