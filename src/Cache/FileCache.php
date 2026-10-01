<?php

declare(strict_types=1);

namespace silenz\PhpRouter\Cache;

use Closure;
use RuntimeException;
use silenz\PhpRouter\Internal\Exporter;

use function bin2hex;
use function file_put_contents;
use function function_exists;
use function hash;
use function is_array;
use function is_dir;
use function is_file;
use function mkdir;
use function opcache_invalidate;
use function preg_replace;
use function random_bytes;
use function rename;
use function restore_error_handler;
use function rtrim;
use function set_error_handler;
use function sprintf;
use function substr;
use function unlink;

/**
 * Stores compiled routes as PHP files that are loaded with a plain `require`, and therefore served
 * from OPcache in production.
 *
 * Each key maps to its own file in the directory. The file name keeps the key readable and adds a
 * short hash, so keys differing only in characters that are unsafe in file names never collide.
 */
final readonly class FileCache implements RouteCache
{
    private string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, characters: '/\\');
    }

    public function get(string $key): ?array
    {
        $file = $this->file($key);
        if (!is_file($file)) {
            return null;
        }

        /** @var mixed $compiled */
        $compiled = require $file;

        return is_array($compiled) ? $compiled : null;
    }

    /**
     * Writes atomically (temporary file + rename), so concurrent requests never `require` a partial file.
     */
    public function set(string $key, array $compiled): void
    {
        $directory = $this->directory;
        if (!is_dir($directory)) {
            self::attempt(
                static fn(): bool => mkdir($directory, permissions: 0o777, recursive: true) || is_dir($directory),
                sprintf('Unable to create route cache directory "%s"', $directory),
            );
        }

        $file = $this->file($key);
        $temporary = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        self::attempt(
            static fn(): bool => file_put_contents($temporary, Exporter::export($compiled)) !== false,
            sprintf('Unable to write route cache file "%s"', $temporary),
        );

        try {
            self::attempt(
                static fn(): bool => rename($temporary, $file),
                sprintf('Unable to move route cache file into "%s"', $file),
            );
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, force: true);
        }
    }

    /**
     * The file a key is stored in, e.g. key "routes-v2" => "<directory>/routes-v2.1f3c8a2b.php".
     */
    public function file(string $key): string
    {
        $readable = (string) preg_replace('/[^A-Za-z0-9._-]+/', replacement: '_', subject: $key);

        return $this->directory . '/' . $readable . '.' . substr(hash('xxh128', $key), offset: 0, length: 8) . '.php';
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
