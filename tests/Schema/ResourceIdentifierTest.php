<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema;

use haddowg\JsonApi\Exception\ResourceIdentifierIdInvalid;
use haddowg\JsonApi\Exception\ResourceIdentifierIdMissing;
use haddowg\JsonApi\Exception\ResourceIdentifierTypeInvalid;
use haddowg\JsonApi\Exception\ResourceIdentifierTypeMissing;
use haddowg\JsonApi\Schema\ResourceIdentifier;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:document-structure')]
final class ResourceIdentifierTest extends TestCase
{
    #[Test]
    public function exposesTypeIdAndMeta(): void
    {
        $identifier = new ResourceIdentifier('user', '1', ['abc' => 'def']);

        self::assertSame('user', $identifier->type);
        self::assertSame('1', $identifier->id);
        self::assertSame(['abc' => 'def'], $identifier->meta);
    }

    #[Test]
    public function metaDefaultsToEmpty(): void
    {
        self::assertSame([], (new ResourceIdentifier('user', '1'))->meta);
    }

    #[Test]
    public function fromArrayBuildsIdentifier(): void
    {
        $identifier = ResourceIdentifier::fromArray(['type' => 'user', 'id' => '1']);

        self::assertEquals(new ResourceIdentifier('user', '1'), $identifier);
    }

    #[Test]
    public function fromArrayCarriesMeta(): void
    {
        $identifier = ResourceIdentifier::fromArray([
            'type' => 'user',
            'id' => '1',
            'meta' => ['abc' => 'def'],
        ]);

        self::assertEquals(new ResourceIdentifier('user', '1', ['abc' => 'def']), $identifier);
    }

    #[Test]
    public function fromArrayIgnoresNonArrayMeta(): void
    {
        $identifier = ResourceIdentifier::fromArray([
            'type' => 'user',
            'id' => '1',
            'meta' => 'nonsense',
        ]);

        self::assertSame([], $identifier->meta);
    }

    #[Test]
    public function fromArrayAcceptsZeroStringTypeAndId(): void
    {
        $identifier = ResourceIdentifier::fromArray(['type' => '0', 'id' => '0']);

        self::assertEquals(new ResourceIdentifier('0', '0'), $identifier);
    }

    #[Test]
    public function fromArrayThrowsWhenTypeMissing(): void
    {
        $this->expectException(ResourceIdentifierTypeMissing::class);

        ResourceIdentifier::fromArray(['id' => '1']);
    }

    #[Test]
    public function fromArrayThrowsWhenTypeEmpty(): void
    {
        $this->expectException(ResourceIdentifierTypeMissing::class);

        ResourceIdentifier::fromArray(['type' => '', 'id' => '1']);
    }

    #[Test]
    public function fromArrayThrowsWhenTypeNotString(): void
    {
        $this->expectException(ResourceIdentifierTypeInvalid::class);

        ResourceIdentifier::fromArray(['type' => 0, 'id' => '1']);
    }

    #[Test]
    public function fromArrayThrowsWhenIdMissing(): void
    {
        $this->expectException(ResourceIdentifierIdMissing::class);

        ResourceIdentifier::fromArray(['type' => 'user']);
    }

    #[Test]
    public function fromArrayThrowsWhenIdEmpty(): void
    {
        $this->expectException(ResourceIdentifierIdMissing::class);

        ResourceIdentifier::fromArray(['type' => 'user', 'id' => '']);
    }

    #[Test]
    public function fromArrayThrowsWhenIdNotString(): void
    {
        $this->expectException(ResourceIdentifierIdInvalid::class);

        ResourceIdentifier::fromArray(['type' => 'user', 'id' => 1]);
    }

    #[Test]
    public function transformOmitsEmptyMeta(): void
    {
        $identifier = new ResourceIdentifier('user', '1');

        self::assertSame(['type' => 'user', 'id' => '1'], $identifier->transform());
    }

    #[Test]
    public function transformIncludesMeta(): void
    {
        $identifier = new ResourceIdentifier('user', '1', ['abc' => 'def']);

        self::assertSame(
            ['type' => 'user', 'id' => '1', 'meta' => ['abc' => 'def']],
            $identifier->transform(),
        );
    }
}
