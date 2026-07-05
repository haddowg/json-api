<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Constraint;

use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Resource\Constraint\Shape;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Shape::class)]
#[Group('spec:document-structure')]
final class ShapeTest extends TestCase
{
    #[Test]
    public function oneOfContributesAnOneOfWithAnOptionalDiscriminator(): void
    {
        $shape = Shape::oneOf(
            Schema::ofType('object')->withProperties(['src' => Schema::ofType('string')]),
            Schema::ofType('object')->withProperties(['text' => Schema::ofType('string')]),
        )->discriminator('kind');

        $schema = $shape->contribute(Schema::create())->toArray();

        $oneOf = $schema['oneOf'] ?? null;
        self::assertIsArray($oneOf);
        self::assertCount(2, $oneOf);
        self::assertSame(['propertyName' => 'kind'], $schema['discriminator'] ?? null);
    }

    #[Test]
    public function anyOfContributesAnAnyOf(): void
    {
        $schema = Shape::anyOf(
            Schema::ofType('string'),
            Schema::ofType('object'),
        )->contribute(Schema::create())->toArray();

        self::assertArrayHasKey('anyOf', $schema);
        self::assertArrayNotHasKey('discriminator', $schema);
    }

    #[Test]
    public function allOfContributesAnAllOf(): void
    {
        $schema = Shape::allOf(
            Schema::ofType('object')->withProperties(['a' => Schema::ofType('string')]),
            Schema::ofType('object')->withProperties(['b' => Schema::ofType('string')]),
        )->contribute(Schema::create())->toArray();

        self::assertArrayHasKey('allOf', $schema);
    }

    #[Test]
    public function contextDefaultsToAlwaysAndScopesToCreateOrUpdate(): void
    {
        self::assertTrue(Shape::oneOf()->context()->onCreate);
        self::assertTrue(Shape::oneOf()->context()->onUpdate);

        $create = Shape::oneOf()->onCreate()->context();
        self::assertTrue($create->onCreate);
        self::assertFalse($create->onUpdate);

        $update = Shape::oneOf()->onUpdate()->context();
        self::assertFalse($update->onCreate);
        self::assertTrue($update->onUpdate);
    }

    #[Test]
    public function projectsThroughAPassThroughFieldViaTheConstraintSchemaSeam(): void
    {
        // Attached to a JSON-object field, the Shape's combinator reaches the field's
        // projected schema through the ProvidesJsonSchema seam (no field-type change).
        $field = ArrayHash::make('block')->constrain(
            Shape::oneOf(
                Schema::ofType('object')->withProperties(['src' => Schema::ofType('string')]),
                Schema::ofType('object')->withProperties(['text' => Schema::ofType('string')]),
            )->discriminator('kind'),
        );

        $schema = (new SchemaProjector())->projectField($field)->toArray();

        self::assertArrayHasKey('oneOf', $schema);
        $discriminator = $schema['discriminator'] ?? null;
        self::assertIsArray($discriminator);
        self::assertSame('kind', $discriminator['propertyName'] ?? null);
    }
}
