<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Atomic;

use haddowg\JsonApi\Atomic\LocalIdRegistry;
use haddowg\JsonApi\Exception\LocalIdConflict;
use haddowg\JsonApi\Exception\LocalIdNotFound;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:extensions-and-profiles')]
final class LocalIdRegistryTest extends TestCase
{
    #[Test]
    public function registersResolvesAndReportsPresence(): void
    {
        $registry = new LocalIdRegistry();

        self::assertFalse($registry->has('articles', 'a1'));

        $registry->register('articles', 'a1', '100');

        self::assertTrue($registry->has('articles', 'a1'));
        self::assertSame('100', $registry->resolve('articles', 'a1'));
    }

    #[Test]
    public function scopesLidsByType(): void
    {
        $registry = new LocalIdRegistry();

        $registry->register('articles', 'x', '1');
        $registry->register('people', 'x', '2');

        self::assertSame('1', $registry->resolve('articles', 'x'));
        self::assertSame('2', $registry->resolve('people', 'x'));
        self::assertFalse($registry->has('comments', 'x'));
    }

    #[Test]
    public function throwsLocalIdConflictOnADuplicateRegistration(): void
    {
        $registry = new LocalIdRegistry();
        $registry->register('articles', 'a1', '100');

        try {
            $registry->register('articles', 'a1', '200');
        } catch (LocalIdConflict $exception) {
            self::assertSame(400, $exception->getStatusCode());
            self::assertSame('articles', $exception->type);
            self::assertSame('a1', $exception->lid);
            self::assertNull($exception->getErrors()[0]->source);

            return;
        }

        self::fail('Expected LocalIdConflict to be thrown.');
    }

    #[Test]
    public function throwsLocalIdNotFoundWhenResolvingAnUnknownLid(): void
    {
        $registry = new LocalIdRegistry();

        try {
            $registry->resolve('articles', 'missing');
        } catch (LocalIdNotFound $exception) {
            self::assertSame(400, $exception->getStatusCode());
            self::assertSame('articles', $exception->type);
            self::assertSame('missing', $exception->lid);
            self::assertNull($exception->getErrors()[0]->source);

            return;
        }

        self::fail('Expected LocalIdNotFound to be thrown.');
    }
}
