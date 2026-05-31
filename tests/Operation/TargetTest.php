<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Operation;

use haddowg\JsonApi\Operation\Target;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TargetTest extends TestCase
{
    #[Test]
    public function defaultsToACollectionTarget(): void
    {
        $target = new Target('articles');

        self::assertSame('articles', $target->type);
        self::assertNull($target->id);
        self::assertNull($target->relationship);
        self::assertFalse($target->isRelationshipEndpoint);
        self::assertFalse($target->hasId());
        self::assertFalse($target->hasRelationship());
    }

    #[Test]
    public function distinguishesRelationshipFromRelatedEndpoint(): void
    {
        $relationship = new Target('articles', '1', 'author', isRelationshipEndpoint: true);
        $related = new Target('articles', '1', 'author');

        self::assertTrue($relationship->hasId());
        self::assertTrue($relationship->hasRelationship());
        self::assertTrue($relationship->isRelationshipEndpoint);
        self::assertFalse($related->isRelationshipEndpoint);
    }

    #[Test]
    public function isReadonly(): void
    {
        self::assertTrue((new \ReflectionClass(Target::class))->isReadOnly());
    }
}
