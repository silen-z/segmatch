<?php

declare(strict_types=1);

namespace silenz\PhpRouter\Benchmarks;

use FastRoute\RouteCollector as FastRouteCollector;
use LogicException;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;
use silenz\PhpRouter\Compiler;
use silenz\PhpRouter\Matcher;
use silenz\PhpRouter\RouteCache;

use function FastRoute\cachedDispatcher;
use function is_file;
use function unlink;

/**
 * Warm start: turning an existing cache file into a ready matcher, i.e. the per-request cost in
 * production. Without OPcache this is dominated by parsing the file; with OPcache it is mostly the
 * cost of `require` returning the immutable array.
 */
#[Groups(['load'])]
#[BeforeMethods('setUp')]
#[ParamProviders('provideFixtures')]
#[Revs(200)]
#[Iterations(5)]
#[Warmup(1)]
final class LoadBench
{
    private const string CACHE_DIR = __DIR__ . '/../var/bench-cache/';

    private string $flatFile = '';

    private string $fastRouteFile = '';

    /**
     * @param array{fixture: string} $params
     */
    public function setUp(array $params): void
    {
        $routes = Fixtures::get($params['fixture'])['routes'];
        $this->flatFile = self::CACHE_DIR . $params['fixture'] . '.flat.php';
        $this->fastRouteFile = self::CACHE_DIR . $params['fixture'] . '.fast-route.php';

        new RouteCache($this->flatFile)->write(Compiler::compile(Routers::flatCollector($routes)));

        if (is_file($this->fastRouteFile)) {
            unlink($this->fastRouteFile);
        }
        Routers::fastRoute($routes, $this->fastRouteFile);
    }

    public function benchFlat(): void
    {
        new Matcher(new RouteCache($this->flatFile)->read() ?? throw new LogicException('Cache file missing.'));
    }

    public function benchFastRoute(): void
    {
        // The definition callback is never invoked while the cache file exists.
        cachedDispatcher(static function (FastRouteCollector $_collector): void {}, [
            'cacheFile' => $this->fastRouteFile,
        ]);
    }

    /**
     * @return iterable<string, array{fixture: string}>
     */
    public function provideFixtures(): iterable
    {
        return Fixtures::provideFixtures();
    }
}
