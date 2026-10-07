<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Cache;

use RuntimeException;
use SilenZ\Segmatch\Compiler;
use UnitEnum;

use function array_is_list;
use function bin2hex;
use function file_put_contents;
use function function_exists;
use function hash;
use function implode;
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
use function unlink;
use function var_export;

/**
 * Stores compiled routes as PHP files that are loaded with a plain `require`, and therefore served
 * from OPcache in production.
 *
 * Each key maps to its own file in the directory: a key made of `A-Z a-z 0-9 . _ -` is used as the
 * file name directly. Any other key is made safe and gets a short hash after a `~`, which cannot
 * appear in a safe key, so no two keys ever share a file.
 *
 * A file returns the compiled structure unchanged, one line per table entry with a short comment
 * above each table, so it stays small and still readable.
 *
 * @psalm-import-type CompiledRoutes from Compiler
 */
final class FileCache implements RouteCache
{
    private const string SAFE_CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-';

    /** The tables of the compiled routes, in file order, each with the comment written above it. */
    private const array TABLES = [
        'static' => 'full path => route id(s), for routes without parameters',
        'edges' => 'node => static segment => child node (node 0 is the root)',
        'param' => 'node => child node of its {param} edge',
        'catch' => 'node => [needs a non-empty rest ({name+}), ...route ids of its catch-all edge]',
        'routes' => 'node => route id(s) ending there',
        'routeMetadata' => 'route id => metadata',
        'paramNames' => 'route id => parameter names, in capture order',
    ];

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
        if (@file_put_contents($temporary, self::export($compiled)) === false) {
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
     * "tenant/a" => "<directory>/tenant_a~48da3de1.php".
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

        return $readable . '~' . hash('xxh32', $key);
    }

    /**
     * @param CompiledRoutes $compiled
     */
    private static function export(array $compiled): string
    {
        $lines = [
            '<?php',
            '',
            '// Generated by silenz/segmatch. Do not edit.',
            '',
            'declare(strict_types=1);',
            '',
            'return [',
            "    'version' => " . var_export($compiled['version'], return: true) . ',',
            '',
            '    // metadata of the route table as a whole, not of any one route',
            "    'metadata' => " . self::inline($compiled['metadata']) . ',',
        ];

        foreach (self::TABLES as $name => $description) {
            $table = $compiled[$name];
            $lines[] = '';
            $lines[] = '    // ' . $description;
            if ($table === []) {
                $lines[] = '    ' . var_export($name, return: true) . ' => [],';
                continue;
            }

            $lines[] = '    ' . var_export($name, return: true) . ' => [';
            $list = array_is_list($table);
            // @mago-expect analysis:mixed-assignment
            foreach ($table as $key => $value) {
                $prefix = $list ? '/* ' . $key . ' */ ' : var_export($key, return: true) . ' => ';
                $lines[] = '        ' . $prefix . self::inline($value) . ',';
            }
            $lines[] = '    ],';
        }

        $lines[] = '];';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private static function inline(mixed $value): string
    {
        if (!is_array($value)) {
            return self::scalar($value);
        }

        $list = array_is_list($value);
        $items = [];
        // @mago-expect analysis:mixed-assignment
        foreach ($value as $key => $item) {
            $items[] = ($list ? '' : var_export($key, return: true) . ' => ') . self::inline($item);
        }

        return '[' . implode(', ', $items) . ']';
    }

    private static function scalar(mixed $value): string
    {
        if ($value instanceof UnitEnum) {
            return '\\' . $value::class . '::' . $value->name;
        }

        return var_export($value, return: true);
    }
}
