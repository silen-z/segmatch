<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Benchmarks;

use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;
use SilenZ\Segmatch\Compiler;
use SilenZ\Segmatch\Http\Registry;
use SilenZ\Segmatch\Http\Routes;

/**
 * Declaring routes through Http\Routes: building the Route/Routes object graph (benchFlat), and that
 * plus turning it into core route definitions and compiling (benchCompiled). Since Http\Routes now
 * declares eagerly, benchFlat is the cost every request pays unconditionally instead of only on a
 * cache miss, like CompileBench's "cold build" still is. Compare the two to see how much of a cold
 * build is declaring versus compiling.
 */
#[Groups(['declare'])]
#[ParamProviders('provideFixtures')]
#[Revs(100)]
#[Iterations(5)]
#[Warmup(1)]
final class DeclareBench
{
    /**
     * @param array{fixture: string} $params
     */
    public function benchFlat(array $params): void
    {
        self::declare($params['fixture']);
    }

    /**
     * @param array{fixture: string} $params
     */
    public function benchCompiled(array $params): void
    {
        Compiler::compile(self::declare($params['fixture'])->definitions());
    }

    /**
     * @return iterable<string, array{fixture: string}>
     */
    public function provideFixtures(): iterable
    {
        return Fixtures::provideFixtures();
    }

    private static function declare(string $fixture): Routes
    {
        $routes = new Routes(new Registry());
        foreach (Fixtures::get($fixture)['routes'] as $index => $route) {
            $routes->get($route, $index);
        }

        return $routes;
    }
}
