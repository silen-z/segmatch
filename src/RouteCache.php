<?php

declare(strict_types=1);

namespace silenz\PhpRouter;

use Closure;
use RuntimeException;
use silenz\PhpRouter\Internal\Exporter;
use silenz\PhpRouter\Internal\Layout;

use function bin2hex;
use function dirname;
use function file_put_contents;
use function function_exists;
use function is_array;
use function is_dir;
use function is_file;
use function mkdir;
use function opcache_invalidate;
use function random_bytes;
use function rename;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function unlink;

/**
 * Stores compiled routes as a PHP file that is loaded with a plain `require` (and therefore served
 * from OPcache in production).
 *
 * Like FastRoute's cached dispatcher, the cache is identified by a key and never invalidated
 * automatically: once the file exists it is used as is. Anything that changes the compiled routes
 * (a deploy, configuration that decides which routes exist) must therefore change the key, e.g. by
 * putting a version or a configuration hash into it. In development, disable the cache instead.
 *
 * @psalm-import-type CompiledRoutes from Compiler
 */
final readonly class RouteCache
{
    /**
     * @param string $cacheKey identifies the compiled routes; for this file cache it is the cache file path
     * @param bool $enabled false compiles the routes on every load, without reading or writing the cache
     */
    public function __construct(
        private string $cacheKey,
        private bool $enabled = true,
    ) {}

    /**
     * Returns a matcher from the cache, or compiles (and, when enabled, caches) the routes.
     *
     * @param Closure(RouteSet): void $define declares the routes; only called when there is no usable cache
     */
    public function load(Closure $define): Matcher
    {
        $compiled = $this->enabled ? $this->read() : null;
        if ($compiled === null) {
            $routes = new RouteSet();
            $define($routes);
            $compiled = Compiler::compile($routes);
            if ($this->enabled) {
                $this->write($compiled);
            }
        }

        return new Matcher($compiled);
    }

    /**
     * Writes the compiled routes atomically, so concurrent requests never `require` a partial file.
     *
     * @param CompiledRoutes $compiled
     */
    public function write(array $compiled): void
    {
        $directory = dirname($this->cacheKey);
        if (!is_dir($directory)) {
            self::attempt(
                static fn(): bool => mkdir($directory, permissions: 0o777, recursive: true) || is_dir($directory),
                sprintf('Unable to create route cache directory "%s"', $directory),
            );
        }

        $temporary = $this->cacheKey . '.' . bin2hex(random_bytes(6)) . '.tmp';
        self::attempt(
            static fn(): bool => file_put_contents($temporary, Exporter::export($compiled)) !== false,
            sprintf('Unable to write route cache file "%s"', $temporary),
        );

        try {
            self::attempt(
                fn(): bool => rename($temporary, $this->cacheKey),
                sprintf('Unable to move route cache file into "%s"', $this->cacheKey),
            );
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->cacheKey, force: true);
        }
    }

    /**
     * @return CompiledRoutes|null null when the file is missing or was produced by an incompatible version
     */
    public function read(): ?array
    {
        if (!is_file($this->cacheKey)) {
            return null;
        }

        /** @var mixed $compiled */
        $compiled = require $this->cacheKey;
        if (!is_array($compiled) || ($compiled['version'] ?? null) !== Layout::FORMAT_VERSION) {
            return null;
        }

        /** @var CompiledRoutes */
        return $compiled;
    }

    /**
     * Runs a filesystem operation, turning a `false` result into an exception carrying PHP's warning.
     *
     * @param Closure(): bool $operation
     */
    private static function attempt(Closure $operation, string $failure): void
    {
        $warning = null;
        set_error_handler(static function (int $_level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $result = $operation();
        } finally {
            restore_error_handler();
        }

        if ($result === false) {
            throw new RuntimeException($warning !== null ? $failure . ': ' . $warning : $failure . '.');
        }
    }
}
