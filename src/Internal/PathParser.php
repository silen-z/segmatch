<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Internal;

use SilenZ\Segmatch\Exception\InvalidRouteException;

use function array_key_last;
use function explode;
use function preg_match;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function substr;

/**
 * Splits a route pattern into segments.
 *
 * `/api/{id}/files/{path*}` becomes STATIC(api), PARAM(id), STATIC(files), CATCH_ALL(path).
 * A trailing slash is preserved as a final empty static segment, so `/foo` and `/foo/` stay distinct.
 *
 * @internal
 */
final class PathParser
{
    private const string PLACEHOLDER = '/^\{([A-Za-z_][A-Za-z0-9_]*)([*+]?)\}$/';

    /**
     * @return non-empty-list<Segment>
     *
     * @throws InvalidRouteException
     */
    public static function parse(string $path): array
    {
        if (!str_starts_with($path, '/')) {
            throw new InvalidRouteException(sprintf('Route path "%s" must start with "/".', $path));
        }

        $parts = explode('/', substr($path, offset: 1));
        $last = array_key_last($parts);
        $segments = [];
        $names = [];

        foreach ($parts as $index => $part) {
            if ($part === '' && $index !== $last) {
                throw new InvalidRouteException(sprintf('Route path "%s" contains an empty segment.', $path));
            }

            if (!str_contains($part, '{') && !str_contains($part, '}')) {
                $segments[] = new Segment(SegmentType::Static, $part);
                continue;
            }

            $match = [];
            if (preg_match(self::PLACEHOLDER, $part, $match) !== 1) {
                throw new InvalidRouteException(sprintf(
                    'Route path "%s" has an invalid placeholder segment "%s"; placeholders must span a whole segment, e.g. "{id}", "{path*}" or "{path+}".',
                    $path,
                    $part,
                ));
            }

            $name = $match[1];
            if ($names[$name] ?? false) {
                throw new InvalidRouteException(sprintf(
                    'Route path "%s" uses parameter "%s" more than once.',
                    $path,
                    $name,
                ));
            }
            $names[$name] = true;

            $type = match ($match[2]) {
                '*' => SegmentType::CatchAllZero,
                '+' => SegmentType::CatchAllOne,
                default => SegmentType::Param,
            };

            if ($type->isCatchAll() && $index !== $last) {
                throw new InvalidRouteException(sprintf(
                    'Catch-all parameter "%s" must be the last segment of "%s".',
                    $name,
                    $path,
                ));
            }

            $segments[] = new Segment($type, $name);
        }

        // explode() always yields at least one part, so at least one segment was added.
        /** @var non-empty-list<Segment> $segments */
        return $segments;
    }
}
