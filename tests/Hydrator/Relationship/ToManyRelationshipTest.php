<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Hydrator\Relationship;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Schema\ResourceIdentifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToManyRelationshipTest extends TestCase
{
    #[Test]
    public function exposesResourceIdentifiersFromConstructor(): void
    {
        $first = new ResourceIdentifier('user', '1');
        $second = new ResourceIdentifier('user', '2');

        $relationship = new ToManyRelationship([$first, $second]);

        self::assertSame([$first, $second], $relationship->resourceIdentifiers);
    }

    #[Test]
    public function exposesResourceIdentifierTypes(): void
    {
        $relationship = new ToManyRelationship([
            new ResourceIdentifier('user', '1'),
            new ResourceIdentifier('user', '2'),
        ]);

        self::assertSame(['user', 'user'], $relationship->getResourceIdentifierTypes());
    }

    #[Test]
    public function exposesResourceIdentifierIds(): void
    {
        $relationship = new ToManyRelationship([
            new ResourceIdentifier('user', '1'),
            new ResourceIdentifier('user', '2'),
        ]);

        self::assertSame(['1', '2'], $relationship->getResourceIdentifierIds());
    }

    #[Test]
    public function exposesResourceIdentifierLids(): void
    {
        $relationship = new ToManyRelationship([
            new ResourceIdentifier('user', null, 'local-1'),
            new ResourceIdentifier('user', '2'),
        ]);

        self::assertSame(['local-1', null], $relationship->getResourceIdentifierLids());
        self::assertSame([null, '2'], $relationship->getResourceIdentifierIds());
    }

    #[Test]
    public function isEmptyIsFalseWhenIdentifiersPresent(): void
    {
        $relationship = new ToManyRelationship([new ResourceIdentifier('user', '1')]);

        self::assertFalse($relationship->isEmpty());
    }

    #[Test]
    public function isEmptyIsTrueWhenNoIdentifiers(): void
    {
        $relationship = new ToManyRelationship();

        self::assertTrue($relationship->isEmpty());
        self::assertSame([], $relationship->resourceIdentifiers);
    }
}
