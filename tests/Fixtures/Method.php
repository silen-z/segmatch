<?php

declare(strict_types=1);

namespace silenz\PhpRouter\Tests\Fixtures;

enum Method: string
{
    case Get = 'GET';
    case Put = 'PUT';
}
