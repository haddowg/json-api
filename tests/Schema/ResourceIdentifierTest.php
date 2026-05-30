<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema;

use haddowg\JsonApi\Exception\ResourceIdentifierIdInvalid;
use haddowg\JsonApi\Exception\ResourceIdentifierIdMissing;
use haddowg\JsonApi\Exception\ResourceIdentifierLidInvalid;
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
    public function exposesTypeIdLidAndMeta(): void
    {
        $identifier = new ResourceIdentifier('user', '1', 'local-1', ['abc' => 'def']);

        self::assertSame('user', $identifier->type);
        self::assertSame('1', $identifier->id);
        self::assertSame('local-1', $identifier->lid);
        self::assertSame(['abc' => 'def'], $identifier->meta);
    }

    #[Test]
    public function idLidAndMetaDefaultToNullOrEmpty(): void
    {
        $identifier = new ResourceIdentifier('user', '1');

        self::assertSame('1', $identifier->id);
        self::assertNull($identifier->lid);
        self::assertSame([], $identifier->meta);
    }

    #[Test]
    public function fromArrayBuildsIdentifierFromId(): void
    {
        $identifier = ResourceIdentifier::fromArray(['type' => 'user', 'id' => '1']);

        self::assertEquals(new ResourceIdentifier('user', '1'), $identifier);
    }

    #[Test]
    public function fromArrayBuildsIdentifierFromLid(): void
    {
        $identifier = ResourceIdentifier::fromArray(['type' => 'user', 'lid' => 'local-1']);

        self::assertEquals(new ResourceIdentifier('user', null, 'local-1'), $identifier);
        self::assertNull($identifier->id);
        self::assertSame('local-1', $identifier->lid);
    }

    #[Test]
    public function fromArrayCarriesMeta(): void
    {
        $identifier = ResourceIdentifier::fromArray([
            'type' => 'user',
            'id' => '1',
            'meta' => ['abc' => 'def'],
        ]);

        self::assertEquals(new ResourceIdentifier('user', '1', null, ['abc' => 'def']), $identifier);
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
    public function fromArrayThrowsWhenTypeNotString(): void
    {
        $this->expectException(ResourceIdentifierTypeInvalid::class);

        ResourceIdentifier::fromArray(['type' => 0, 'id' => '1']);
    }

    #[Test]
    public function fromArrayThrowsWhenNeitherIdNorLidPresent(): void
    {
        $this->expectException(ResourceIdentifierIdMissing::class);

        ResourceIdentifier::fromArray(['type' => 'user']);
    }

    #[Test]
    public function fromArrayThrowsWhenIdAndLidBothEmpty(): void
    {
        $this->expectException(ResourceIdentifierIdMissing::class);

        ResourceIdentifier::fromArray(['type' => 'user', 'id' => '', 'lid' => '']);
    }

    #[Test]
    public function fromArrayThrowsWhenIdNotString(): void
    {
        $this->expectException(ResourceIdentifierIdInvalid::class);

        ResourceIdentifier::fromArray(['type' => 'user', 'id' => 1]);
    }

    #[Test]
    public function fromArrayThrowsWhenLidNotString(): void
    {
        $this->expectException(ResourceIdentifierLidInvalid::class);

        ResourceIdentifier::fromArray(['type' => 'user', 'lid' => 1]);
    }

    #[Test]
    public function transformOmitsEmptyMembers(): void
    {
        $identifier = new ResourceIdentifier('user', '1');

        self::assertSame(['type' => 'user', 'id' => '1'], $identifier->transform());
    }

    #[Test]
    public function transformEmitsLidInsteadOfId(): void
    {
        $identifier = new ResourceIdentifier('user', null, 'local-1');

        self::assertSame(['type' => 'user', 'lid' => 'local-1'], $identifier->transform());
    }

    #[Test]
    public function transformIncludesAllPresentMembers(): void
    {
        $identifier = new ResourceIdentifier('user', '1', 'local-1', ['abc' => 'def']);

        self::assertSame(
            ['type' => 'user', 'id' => '1', 'lid' => 'local-1', 'meta' => ['abc' => 'def']],
            $identifier->transform(),
        );
    }
}
