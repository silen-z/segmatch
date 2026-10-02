<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Cache;

use RuntimeException;
use SilenZ\Segmatch\Internal\Exporter;

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
use function rtrim;
use function sprintf;
use function strlen;
use function strspn;
use function substr;
use function unlink;

/**
 * Stores compiled routes as PHP files that are loaded with a plain `require`, and therefore served
 * from OPcache in production.
 *
 * Each key maps to its own file in the directory: a key made of `A-Z a-z 0-9 . _ -` is used as the
 * file name directly. Any other key is made safe and gets a short hash after a `~`, which cannot
 * appear in a safe key, so no two keys ever share a file.
 */
final class FileCache implements RouteCache
{
    private const string SAFE_CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-';

    private readonly string $directory;

    /** @var array<string, string> key => file, so long-running processes build each name once */
    private array $files = [];

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
        // @mago-expect lint:no-error-control-operator - the failure is handled below; we don't need the warning text
        if (!is_dir($directory) && !@mkdir($directory, permissions: 0o777, recursive: true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create route cache directory "%s".', $directory));
        }

        $file = $this->file($key);
        $temporary = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        // @mago-expect lint:no-error-control-operator - the failure is handled below; we don't need the warning text
        if (@file_put_contents($temporary, Exporter::export($compiled)) === false) {
            throw new RuntimeException(sprintf('Unable to write route cache file "%s".', $temporary));
        }

        try {
            // @mago-expect lint:no-error-control-operator - the failure is handled below; we don't need the warning text
            if (@rename($temporary, $file) === false) {
                throw new RuntimeException(sprintf('Unable to move route cache file into "%s".', $file));
            }
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
     * The file a key is stored in, e.g. "routes-v2" => "<directory>/routes-v2.php" and
     * "tenant/a" => "<directory>/tenant_a~1f3c8a2b.php".
     */
    public function file(string $key): string
    {
        return $this->files[$key] ??= $this->directory . '/' . self::fileName($key) . '.php';
    }

    private static function fileName(string $key): string
    {
        if ($key !== '' && strspn($key, self::SAFE_CHARACTERS) === strlen($key)) {
            return $key;
        }

        $readable = (string) preg_replace('/[^A-Za-z0-9._-]+/', replacement: '_', subject: $key);

        return $readable . '~' . substr(hash('xxh128', $key), offset: 0, length: 8);
    }
}
