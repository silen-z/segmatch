<?php

declare(strict_types=1);

namespace silenz\PhpRouter;

use Closure;
use RuntimeException;
use silenz\PhpRouter\Internal\Exporter;
use silenz\PhpRouter\Internal\Layout;

use function bin2hex;
use function clearstatcache;
use function dirname;
use function file_put_contents;
use function filemtime;
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
 * Invalidation is explicit: the cache is considered fresh when the file exists and is not older than
 * any of the given source files.
 *
 * @psalm-import-type CompiledRoutes from Compiler
 */
final readonly class RouteCache
{
    public function __construct(
        private string $file,
    ) {}

    /**
     * Returns a matcher from the cache, recompiling it first when it is stale.
     *
     * @param Closure(RouteCollector): void $define declares the routes
     * @param list<string> $sources files whose modification invalidates the cache (e.g. the route definitions)
     */
    public function load(Closure $define, array $sources = []): Matcher
    {
        $compiled = $this->isFresh($sources) ? $this->read() : null;
        if ($compiled === null) {
            $collector = new RouteCollector();
            $define($collector);
            $compiled = Compiler::compile($collector);
            $this->write($compiled);
        }

        return new Matcher($compiled);
    }

    /**
     * @param list<string> $sources
     */
    public function isFresh(array $sources = []): bool
    {
        clearstatcache(clear_realpath_cache: true, filename: $this->file);
        if (!is_file($this->file)) {
            return false;
        }

        $cached = filemtime($this->file);
        if ($cached === false) {
            return false;
        }

        foreach ($sources as $source) {
            clearstatcache(clear_realpath_cache: true, filename: $source);
            $modified = is_file($source) ? filemtime($source) : false;
            if ($modified === false || $modified > $cached) {
                return false;
            }
        }

        return true;
    }

    /**
     * Writes the compiled routes atomically, so concurrent requests never `require` a partial file.
     *
     * @param CompiledRoutes $compiled
     */
    public function write(array $compiled): void
    {
        $directory = dirname($this->file);
        if (!is_dir($directory)) {
            self::guard(
                static fn(): bool => mkdir($directory, permissions: 0o777, recursive: true) || is_dir($directory),
                sprintf('Unable to create route cache directory "%s"', $directory),
            );
        }

        $temporary = $this->file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        self::guard(
            static fn(): bool => file_put_contents($temporary, Exporter::export($compiled)) !== false,
            sprintf('Unable to write route cache file "%s"', $temporary),
        );

        try {
            self::guard(
                fn(): bool => rename($temporary, $this->file),
                sprintf('Unable to move route cache file into "%s"', $this->file),
            );
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->file, force: true);
        }
    }

    /**
     * @return CompiledRoutes|null null when the file is missing or was produced by an incompatible version
     */
    public function read(): ?array
    {
        if (!is_file($this->file)) {
            return null;
        }

        /** @var mixed $compiled */
        $compiled = require $this->file;
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
    private static function guard(Closure $operation, string $failure): void
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
