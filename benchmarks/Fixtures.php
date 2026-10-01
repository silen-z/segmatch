<?php

declare(strict_types=1);

namespace silenz\PhpRouter\Benchmarks;

/**
 * Route sets shared by all benchmarks. Each fixture is a list of route patterns in this router's
 * syntax plus named request paths exercising the interesting matching cases.
 *
 * @psalm-type Fixture = array{routes: list<string>, requests: array<string, string>}
 */
final class Fixtures
{
    public const array NAMES = ['small', 'medium', 'large', 'deep', 'branching', 'parameters'];

    /** @var array<string, Fixture> */
    private static array $loaded = [];

    /**
     * @return Fixture
     */
    public static function get(string $name): array
    {
        if (!array_key_exists($name, self::$loaded)) {
            /** @var Fixture $fixture */
            $fixture = require __DIR__ . '/fixtures/' . $name . '.php';
            self::$loaded[$name] = $fixture;
        }

        return self::$loaded[$name];
    }

    /**
     * @return iterable<string, array{fixture: string}>
     */
    public static function provideFixtures(): iterable
    {
        foreach (self::NAMES as $name) {
            yield $name => ['fixture' => $name];
        }
    }

    /**
     * @return iterable<string, array{fixture: string, case: string, path: string}>
     */
    public static function provideRequests(): iterable
    {
        foreach (self::NAMES as $name) {
            foreach (self::get($name)['requests'] as $case => $path) {
                yield $name . '/' . $case => ['fixture' => $name, 'case' => $case, 'path' => $path];
            }
        }
    }
}
