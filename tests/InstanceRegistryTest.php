<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests;

use PHPUnit\Framework\TestCase;
use SilenZ\Segmatch\Exception\InvalidRouteException;
use SilenZ\Segmatch\InstanceRegistry;
use SilenZ\Segmatch\Tests\Fixtures\Method;
use stdClass;

final class InstanceRegistryTest extends TestCase
{
    public function testPlainValuesPassThroughUnchanged(): void
    {
        $registry = new InstanceRegistry();

        static::assertSame('show', $registry->wrap('show', 'test'));
        static::assertSame(4.2, $registry->wrap(4.2, 'test'));
        static::assertNull($registry->wrap(null, 'test'));
        static::assertSame(Method::Get, $registry->wrap(Method::Get, 'test'));
        static::assertSame(['a', 1, null], $registry->wrap(['a', 1, null], 'test'));
    }

    public function testAnIntegerIsRejectedSinceItWouldReadAsAnId(): void
    {
        $this->expectException(InvalidRouteException::class);
        $this->expectExceptionMessageMatches('/^Route "\/a" handler cannot be an integer \(42\)/');

        new InstanceRegistry()->wrap(42, 'Route "/a" handler');
    }

    public function testNonPlainValuesAreWrappedIntoAnId(): void
    {
        $registry = new InstanceRegistry();
        $object = new stdClass();

        /** @var int $id */
        $id = $registry->wrap($object, 'test');

        static::assertIsInt($id);
        static::assertSame($object, $registry->get($id));
    }

    public function testAnArrayContainingAnyNonPlainValueIsWrappedWhole(): void
    {
        $registry = new InstanceRegistry();
        $mixed = [new stdClass(), 'show'];

        /** @var int $id */
        $id = $registry->wrap($mixed, 'test');

        static::assertIsInt($id);
        static::assertSame($mixed, $registry->get($id));
    }

    public function testIdsAreAssignedInWrappingOrder(): void
    {
        $registry = new InstanceRegistry();
        $first = new stdClass();
        $second = new stdClass();

        /** @var int $firstId */
        $firstId = $registry->wrap($first, 'test');
        /** @var int $secondId */
        $secondId = $registry->wrap($second, 'test');

        static::assertSame($first, $registry->get($firstId));
        static::assertSame($second, $registry->get($secondId));
        static::assertNotSame($firstId, $secondId);
    }

    public function testClosuresAreWrapped(): void
    {
        $registry = new InstanceRegistry();
        $closure = static fn(): string => 'x';

        /** @var int $id */
        $id = $registry->wrap($closure, 'test');

        static::assertIsInt($id);
        static::assertSame($closure, $registry->get($id));
    }
}
