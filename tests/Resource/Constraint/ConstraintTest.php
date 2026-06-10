<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Constraint;

use haddowg\JsonApi\Resource\Constraint\After;
use haddowg\JsonApi\Resource\Constraint\AtLeastOneOf;
use haddowg\JsonApi\Resource\Constraint\Before;
use haddowg\JsonApi\Resource\Constraint\Between;
use haddowg\JsonApi\Resource\Constraint\Context;
use haddowg\JsonApi\Resource\Constraint\Each;
use haddowg\JsonApi\Resource\Constraint\EmailFormat;
use haddowg\JsonApi\Resource\Constraint\ExclusiveMax;
use haddowg\JsonApi\Resource\Constraint\ExclusiveMin;
use haddowg\JsonApi\Resource\Constraint\In;
use haddowg\JsonApi\Resource\Constraint\IpFormat;
use haddowg\JsonApi\Resource\Constraint\Max;
use haddowg\JsonApi\Resource\Constraint\MaxItems;
use haddowg\JsonApi\Resource\Constraint\MaxLength;
use haddowg\JsonApi\Resource\Constraint\MaxProperties;
use haddowg\JsonApi\Resource\Constraint\Min;
use haddowg\JsonApi\Resource\Constraint\MinItems;
use haddowg\JsonApi\Resource\Constraint\MinLength;
use haddowg\JsonApi\Resource\Constraint\MinProperties;
use haddowg\JsonApi\Resource\Constraint\MultipleOf;
use haddowg\JsonApi\Resource\Constraint\NotIn;
use haddowg\JsonApi\Resource\Constraint\Nullable;
use haddowg\JsonApi\Resource\Constraint\Pattern;
use haddowg\JsonApi\Resource\Constraint\RelationshipType;
use haddowg\JsonApi\Resource\Constraint\Required;
use haddowg\JsonApi\Resource\Constraint\Sequentially;
use haddowg\JsonApi\Resource\Constraint\SlugFormat;
use haddowg\JsonApi\Resource\Constraint\UniqueItems;
use haddowg\JsonApi\Resource\Constraint\UrlFormat;
use haddowg\JsonApi\Resource\Constraint\UuidFormat;
use haddowg\JsonApi\Resource\Constraint\When;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Context::class)]
#[CoversClass(After::class)]
#[CoversClass(AtLeastOneOf::class)]
#[CoversClass(Before::class)]
#[CoversClass(Between::class)]
#[CoversClass(Each::class)]
#[CoversClass(EmailFormat::class)]
#[CoversClass(ExclusiveMax::class)]
#[CoversClass(ExclusiveMin::class)]
#[CoversClass(In::class)]
#[CoversClass(IpFormat::class)]
#[CoversClass(Max::class)]
#[CoversClass(MaxItems::class)]
#[CoversClass(MaxLength::class)]
#[CoversClass(MaxProperties::class)]
#[CoversClass(Min::class)]
#[CoversClass(MinItems::class)]
#[CoversClass(MinLength::class)]
#[CoversClass(MinProperties::class)]
#[CoversClass(MultipleOf::class)]
#[CoversClass(NotIn::class)]
#[CoversClass(Nullable::class)]
#[CoversClass(Pattern::class)]
#[CoversClass(RelationshipType::class)]
#[CoversClass(Required::class)]
#[CoversClass(Sequentially::class)]
#[CoversClass(SlugFormat::class)]
#[CoversClass(UniqueItems::class)]
#[CoversClass(UrlFormat::class)]
#[CoversClass(UuidFormat::class)]
#[CoversClass(When::class)]
#[Group('spec:document-structure')]
final class ConstraintTest extends TestCase
{
    #[Test]
    public function contextAlwaysAppliesToBothContexts(): void
    {
        $context = Context::always();

        self::assertTrue($context->onCreate);
        self::assertTrue($context->onUpdate);
        self::assertTrue($context->appliesTo(true));
        self::assertTrue($context->appliesTo(false));
    }

    #[Test]
    public function contextOnlyCreateAppliesToCreateOnly(): void
    {
        $context = Context::onlyCreate();

        self::assertTrue($context->appliesTo(true));
        self::assertFalse($context->appliesTo(false));
    }

    #[Test]
    public function contextOnlyUpdateAppliesToUpdateOnly(): void
    {
        $context = Context::onlyUpdate();

        self::assertFalse($context->appliesTo(true));
        self::assertTrue($context->appliesTo(false));
    }

    #[Test]
    public function constraintsDefaultToAlwaysContext(): void
    {
        foreach ([new Required(), new Nullable(), new MaxLength(5), new Min(0)] as $constraint) {
            self::assertInstanceOf(\haddowg\JsonApi\Resource\Constraint\ConstraintInterface::class, $constraint);
            self::assertTrue($constraint->context()->onCreate);
            self::assertTrue($constraint->context()->onUpdate);
        }
    }

    #[Test]
    public function intValuedConstraintsCarryTheirValue(): void
    {
        self::assertSame(1, (new MinLength(1))->value);
        self::assertSame(200, (new MaxLength(200))->value);
        self::assertSame(2, (new MinItems(2))->value);
        self::assertSame(9, (new MaxItems(9))->value);
        self::assertSame(1, (new MinProperties(1))->value);
        self::assertSame(8, (new MaxProperties(8))->value);
    }

    #[Test]
    public function numericConstraintsAcceptIntAndFloat(): void
    {
        self::assertSame(0, (new Min(0))->value);
        self::assertSame(1.5, (new Max(1.5))->value);
        self::assertSame(0.1, (new ExclusiveMin(0.1))->value);
        self::assertSame(99, (new ExclusiveMax(99))->value);
        self::assertSame(3, (new MultipleOf(3))->value);
    }

    #[Test]
    public function patternCarriesItsRegex(): void
    {
        self::assertSame('^[a-z]+$', (new Pattern('^[a-z]+$'))->regex);
    }

    #[Test]
    public function enumConstraintsCarryTheirValueLists(): void
    {
        self::assertSame(['draft', 'published'], (new In(['draft', 'published']))->values);
        self::assertSame([0, 1], (new NotIn([0, 1]))->values);
    }

    #[Test]
    public function eachWrapsConstraints(): void
    {
        $inner = [new MinLength(1)];
        $each = new Each($inner);

        self::assertSame($inner, $each->constraints);
    }

    #[Test]
    public function sequentiallyWrapsConstraints(): void
    {
        $inner = [new MinLength(1)];
        $sequentially = new Sequentially($inner);

        self::assertSame($inner, $sequentially->constraints);
    }

    #[Test]
    public function atLeastOneOfWrapsAlternatives(): void
    {
        $alternatives = [new MinLength(1)];
        $atLeastOneOf = new AtLeastOneOf($alternatives);

        self::assertSame($alternatives, $atLeastOneOf->constraints);
    }

    #[Test]
    public function whenCarriesConditionAndConstraintsButDefaultsToAlways(): void
    {
        $condition = static fn(mixed $value): bool => $value !== null;
        $inner = [new Required()];
        $when = new When($condition, $inner);

        self::assertSame($condition, $when->condition);
        self::assertSame($inner, $when->constraints);
        self::assertTrue($when->context()->onCreate);
    }

    #[Test]
    public function emailFormatCarriesStrictFlag(): void
    {
        self::assertFalse((new EmailFormat())->strict);
        self::assertTrue((new EmailFormat(true))->strict);
    }

    #[Test]
    public function stringFormatConstraintsCarryTheirOptions(): void
    {
        self::assertInstanceOf(EmailFormat::class, new EmailFormat());
        self::assertSame([], (new UrlFormat())->allowedSchemes);
        self::assertSame(['https'], (new UrlFormat(['https']))->allowedSchemes);
        self::assertSame(4, (new UuidFormat(4))->version);
        self::assertNull((new UuidFormat())->version);
        self::assertSame(6, (new IpFormat(6))->version);
        self::assertSame(SlugFormat::DEFAULT_PATTERN, (new SlugFormat())->regex);
        self::assertSame('^custom$', (new SlugFormat('^custom$'))->regex);
    }

    #[Test]
    public function dateBoundsAcceptFixedAndClosureBounds(): void
    {
        $fixed = new \DateTimeImmutable('2020-01-01');
        $before = new Before($fixed);
        self::assertSame($fixed, $before->bound);

        $closure = static fn(): \DateTimeImmutable => new \DateTimeImmutable();
        $after = new After($closure);
        self::assertSame($closure, $after->bound);

        $between = new Between($fixed, $closure);
        self::assertSame($fixed, $between->min);
        self::assertSame($closure, $between->max);
    }

    #[Test]
    public function relationshipTypeCarriesAllowedTypes(): void
    {
        self::assertSame(['users', 'admins'], (new RelationshipType(['users', 'admins']))->types);
    }

    #[Test]
    public function markerConstraintsHonourExplicitContext(): void
    {
        $required = new Required(Context::onlyCreate());

        self::assertTrue($required->context()->appliesTo(true));
        self::assertFalse($required->context()->appliesTo(false));
        self::assertInstanceOf(UniqueItems::class, new UniqueItems());
    }
}
