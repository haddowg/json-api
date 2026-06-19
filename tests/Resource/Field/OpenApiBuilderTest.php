<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\Resource\Constraint\In;
use haddowg\JsonApi\Resource\Constraint\NotIn;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Priority;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Status;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(\haddowg\JsonApi\Resource\Field\AbstractField::class)]
#[CoversClass(In::class)]
#[Group('spec:document-structure')]
final class OpenApiBuilderTest extends TestCase
{
    #[Test]
    public function descriptionAndExampleSurfaceThroughGetters(): void
    {
        $field = Str::make('name')->description('The display name')->example('Ada');

        self::assertSame('The display name', $field->getDescription());
        self::assertTrue($field->hasExample());
        self::assertSame('Ada', $field->getExample());
    }

    #[Test]
    public function aNullExampleIsDistinguishedFromNoExample(): void
    {
        self::assertFalse(Str::make('name')->getDescription() !== null);
        self::assertFalse(Str::make('name')->hasExample());

        $field = Str::make('name')->example(null);
        self::assertTrue($field->hasExample());
        self::assertNull($field->getExample());
    }

    #[Test]
    public function enumExpandsToBackingScalarsAndRetainsTheClassString(): void
    {
        $field = Str::make('status')->enum(Status::class);

        $in = $this->onlyIn($field->constraints());
        self::assertSame(['draft', 'published', 'archived'], $in->values);
        self::assertSame(Status::class, $in->enumClass);
    }

    #[Test]
    public function inAcceptsBackedEnumCasesAndNormalizesThemToScalars(): void
    {
        $field = Str::make('status')->in([Status::Draft, Status::Published]);

        $in = $this->onlyIn($field->constraints());
        self::assertSame(['draft', 'published'], $in->values);
        self::assertSame(Status::class, $in->enumClass);
    }

    #[Test]
    public function inWithPlainScalarsRetainsNoEnumClass(): void
    {
        $field = Str::make('status')->in(['a', 'b']);

        $in = $this->onlyIn($field->constraints());
        self::assertSame(['a', 'b'], $in->values);
        self::assertNull($in->enumClass);
    }

    #[Test]
    public function inWithMixedEnumsRetainsNoSingleEnumClass(): void
    {
        $field = Str::make('mixed')->in([Status::Draft, 'literal']);

        $in = $this->onlyIn($field->constraints());
        self::assertSame(['draft', 'literal'], $in->values);
        self::assertNull($in->enumClass);
    }

    #[Test]
    public function integerEnumExpandsToIntBackingScalars(): void
    {
        $field = Integer::make('priority')->enum(Priority::class);

        $in = $this->onlyIn($field->constraints());
        self::assertSame([1, 2], $in->values);
        self::assertSame(Priority::class, $in->enumClass);
    }

    #[Test]
    public function integerInAcceptsIntBackedEnumCases(): void
    {
        $field = Integer::make('priority')->in([Priority::Low, Priority::High]);

        $in = $this->onlyIn($field->constraints());
        self::assertSame([1, 2], $in->values);
        self::assertSame(Priority::class, $in->enumClass);
    }

    #[Test]
    public function notInNormalizesEnumCasesToScalars(): void
    {
        $field = Str::make('status')->notIn([Status::Archived]);

        $constraints = $field->constraints();
        self::assertCount(1, $constraints);
        $notIn = $constraints[0];
        self::assertInstanceOf(NotIn::class, $notIn);
        self::assertSame(['archived'], $notIn->values);
    }

    /**
     * @param list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface> $constraints
     *
     * @return In<int|string>
     */
    private function onlyIn(array $constraints): In
    {
        self::assertCount(1, $constraints);
        $in = $constraints[0];
        self::assertInstanceOf(In::class, $in);

        return $in;
    }
}
