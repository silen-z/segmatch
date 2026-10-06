<?php

declare(strict_types=1);

namespace SilenZ\Segmatch;

use SilenZ\Segmatch\Exception\InvalidRouteException;
use SilenZ\Segmatch\Internal\Segment;
use SilenZ\Segmatch\Internal\SegmentType;

use function array_key_last;
use function explode;
use function implode;
use function preg_match;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function substr;

/**
 * One route: a full path and opaque metadata.
 *
 *     new RouteDefinition('/users/{id}', ['handler' => 'users.show']);
 *
 * The path is parsed right away, so a malformed path throws where the route is declared. Conflicts
 * between routes are reported when they are compiled. The router never interprets the metadata; it
 * must survive the compiled PHP file, so it may only consist of scalars, null, enums and arrays of
 * those.
 */
final readonly class RouteDefinition
{
    private const string PLACEHOLDER = '/^\{([A-Za-z_][A-Za-z0-9_]*)([*+]?)\}$/';

    /**
     * @internal the parsed path, for the compiler
     *
     * @var non-empty-list<Segment>
     */
    public array $segments;

    /**
     * The path rendered as a template: static text as-is, every parameter — catch-alls included —
     * as `{name}`, with no `*`/`+` suffix. OpenAPI's path templates use this exact style.
     */
    public string $pathTemplate;

    /**
     * The path's named parameters, in path order.
     *
     * @var list<PathParameter>
     */
    public array $parameters;

    /**
     * @param string $path full route path starting with "/", e.g. "/users/{id}" or "/assets/{path+}"
     *
     * @throws InvalidRouteException when the path is malformed
     */
    public function __construct(
        public string $path,
        public mixed $metadata = null,
    ) {
        $this->segments = self::parse($path);
        $this->pathTemplate = self::template($this->segments);
        $this->parameters = self::parameters($this->segments);
    }

    /**
     * Splits a route path into segments.
     *
     * `/api/{id}/files/{path*}` becomes STATIC(api), PARAM(id), STATIC(files), CATCH_ALL(path).
     * A trailing slash is preserved as a final empty static segment, so `/foo` and `/foo/` stay
     * distinct.
     *
     * @internal shared with {@see Http\UrlGenerator}
     *
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

    /**
     * @param non-empty-list<Segment> $segments
     */
    private static function template(array $segments): string
    {
        $parts = [];
        foreach ($segments as $segment) {
            $parts[] = $segment->type === SegmentType::Static ? $segment->value : '{' . $segment->value . '}';
        }

        return '/' . implode('/', $parts);
    }

    /**
     * @param non-empty-list<Segment> $segments
     *
     * @return list<PathParameter>
     */
    private static function parameters(array $segments): array
    {
        $parameters = [];
        foreach ($segments as $segment) {
            if ($segment->type === SegmentType::Static) {
                continue;
            }

            $parameters[] = new PathParameter($segment->value, required: $segment->type !== SegmentType::CatchAllZero);
        }

        return $parameters;
    }
}
