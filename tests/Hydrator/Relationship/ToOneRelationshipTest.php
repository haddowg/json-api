<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Hydrator\Relationship;

use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Schema\ResourceIdentifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToOneRelationshipTest extends TestCase
{
    #[Test]
    public function exposesResourceIdentifierFromConstructor(): void
    {
        $resourceIdentifier = new ResourceIdentifier('user', '1');

        $relationship = new ToOneRelationship($resourceIdentifier);

        self::assertSame($resourceIdentifier, $relationship->resourceIdentifier);
    }

    #[Test]
    public function isEmptyIsFalseWhenIdentifierPresent(): void
    {
        $relationship = new ToOneRelationship(new ResourceIdentifier('user', '1'));

        self::assertFalse($relationship->isEmpty());
    }

    #[Test]
    public function isEmptyIsTrueWhenIdentifierAbsent(): void
    {
        $relationship = new ToOneRelationship();

        self::assertTrue($relationship->isEmpty());
        self::assertNull($relationship->resourceIdentifier);
    }
}
