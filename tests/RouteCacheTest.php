<?php

declare(strict_types=1);

namespace silenz\PhpRouter\Tests;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use silenz\PhpRouter\Compiler;
use silenz\PhpRouter\NoMatch;
use silenz\PhpRouter\RouteCache;
use silenz\PhpRouter\RouteMatch;
use silenz\PhpRouter\RouteSet;
use silenz\PhpRouter\Tests\Fixtures\Method;

use function file_put_contents;
use function glob;
use function is_dir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class RouteCacheTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/php-router-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        $files = glob($this->directory . '/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    private static function define(RouteSet $r): void
    {
        $r->add('/api/users/{id}', [
            'methods' => [Method::Get, Method::Put],
            'handler' => ['UserController', 'show'],
            'middleware' => ['auth'],
            'weight' => 1.5,
            'public' => false,
            'extra' => null,
            "quote'd" => "it's",
        ]);
        $r->add('/api/123', 'numeric segment');
        $r->add('/assets/{path*}', 'assets');
    }

    public function testWrittenFileReproducesCompiledRoutes(): void
    {
        $routes = new RouteSet();
        self::define($routes);
        $compiled = Compiler::compile($routes);

        $cache = new RouteCache($this->directory . '/routes.php');
        $cache->write($compiled);

        static::assertSame($compiled, $cache->read());
    }

    public function testLoadCompilesOnceAndThenReusesTheFile(): void
    {
        $cache = new RouteCache($this->directory . '/routes.php');
        $calls = 0;
        $define = static function (RouteSet $r) use (&$calls): void {
            $calls++;
            self::define($r);
        };

        $first = $cache->load($define);
        $second = $cache->load($define);

        static::assertSame(1, $calls);
        static::assertEquals($first->match('/api/users/5'), $second->match('/api/users/5'));
        static::assertSame('numeric segment', self::route($second->match('/api/123')));
    }

    public function testExistingCacheIsUsedEvenWhenTheDefinitionChanges(): void
    {
        $file = $this->directory . '/routes.php';
        new RouteCache($file)->load(static fn(RouteSet $r) => $r->add('/old', 'old'));

        $matcher = new RouteCache($file)->load(static fn(RouteSet $r) => $r->add('/new', 'new'));

        static::assertSame('old', self::route($matcher->match('/old')));
        static::assertNull(self::route($matcher->match('/new')));
    }

    public function testDifferentKeysKeepSeparateCaches(): void
    {
        $v1 = new RouteCache($this->directory . '/routes-v1.php')->load(static fn(RouteSet $r) => $r->add('/a', 'v1'));
        $v2 = new RouteCache($this->directory . '/routes-v2.php')->load(static fn(RouteSet $r) => $r->add('/a', 'v2'));

        static::assertSame('v1', self::route($v1->match('/a')));
        static::assertSame('v2', self::route($v2->match('/a')));
    }

    public function testDisabledCacheCompilesEveryTimeAndWritesNothing(): void
    {
        $file = $this->directory . '/routes.php';
        $cache = new RouteCache($file, enabled: false);
        $counter = new ArrayObject();
        $define = static function (RouteSet $r) use ($counter): void {
            $counter->append(true);
            $r->add('/a', 'a');
        };

        $cache->load($define);
        $matcher = $cache->load($define);

        static::assertCount(2, $counter);
        static::assertSame('a', self::route($matcher->match('/a')));
        static::assertFileDoesNotExist($file);
    }

    public function testDisabledCacheIgnoresAnExistingFile(): void
    {
        $file = $this->directory . '/routes.php';
        new RouteCache($file)->load(static fn(RouteSet $r) => $r->add('/old', 'old'));

        $matcher = new RouteCache($file, enabled: false)->load(static fn(RouteSet $r) => $r->add('/new', 'new'));

        static::assertSame('new', self::route($matcher->match('/new')));
    }

    public function testIncompatibleFileIsIgnored(): void
    {
        $file = $this->directory . '/routes.php';
        $cache = new RouteCache($file);
        $cache->load(self::define(...));
        file_put_contents($file, data: "<?php return ['version' => 0];");

        static::assertNull($cache->read());
        static::assertSame('assets', self::route($cache->load(self::define(...))->match('/assets/x')));
    }

    private static function route(RouteMatch|NoMatch $result): mixed
    {
        return $result instanceof RouteMatch ? $result->route : null;
    }
}
