<?php

declare(strict_types=1);

namespace silenz\PhpRouter\Tests;

use PHPUnit\Framework\TestCase;
use silenz\PhpRouter\Compiler;
use silenz\PhpRouter\RouteCache;
use silenz\PhpRouter\RouteSet;
use silenz\PhpRouter\Tests\Fixtures\Method;

use function file_put_contents;
use function glob;
use function is_dir;
use function rmdir;
use function sys_get_temp_dir;
use function touch;
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
        $collector = new RouteSet();
        self::define($collector);
        $compiled = Compiler::compile($collector);

        $cache = new RouteCache($this->directory . '/routes.php');
        $cache->write($compiled);

        static::assertSame($compiled, $cache->read());
    }

    public function testLoadCompilesOnceAndThenReusesTheFile(): void
    {
        $file = $this->directory . '/routes.php';
        $cache = new RouteCache($file);
        $calls = 0;
        $define = static function (RouteSet $r) use (&$calls): void {
            $calls++;
            self::define($r);
        };

        $first = $cache->load($define);
        $second = $cache->load($define);

        static::assertSame(1, $calls);
        static::assertEquals($first->match('/api/users/5'), $second->match('/api/users/5'));
        static::assertSame(['id' => '5'], $second->match('/api/users/5')?->params);
        static::assertSame('numeric segment', $second->match('/api/123')?->route);
    }

    public function testCacheIsStaleWhenASourceFileIsNewer(): void
    {
        $file = $this->directory . '/routes.php';
        $source = $this->directory . '/source.php';
        $cache = new RouteCache($file);

        $cache->load(self::define(...));
        file_put_contents($source, data: '<?php');
        touch($file, mtime: 1_000);
        touch($source, mtime: 2_000);

        static::assertFalse($cache->isFresh([$source]));

        touch($file, mtime: 3_000);
        static::assertTrue($cache->isFresh([$source]));
        static::assertFalse($cache->isFresh([$this->directory . '/missing.php']));
    }

    public function testIncompatibleFileIsIgnored(): void
    {
        $file = $this->directory . '/routes.php';
        $cache = new RouteCache($file);
        $cache->load(self::define(...));
        file_put_contents($file, data: "<?php return ['version' => 0];");

        static::assertNull($cache->read());
        static::assertSame('assets', $cache->load(self::define(...))->match('/assets/x')?->route);
    }
}
