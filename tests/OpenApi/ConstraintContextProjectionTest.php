<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\RepresentationContext;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Resource\Constraint\Context;
use haddowg\JsonApi\Resource\Constraint\MaxLength;
use haddowg\JsonApi\Resource\Constraint\Min;
use haddowg\JsonApi\Resource\Constraint\MinLength;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\ArrayListBuilder;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\IntegerBuilder;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\StrBuilder;
use haddowg\JsonApi\Resource\Filter\Where;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which constraints reach the read, create and update schemas, and the non-empty
 * keyword a `Required` carries. Each case pins a keyword the server enforces, or the
 * absence of one it does not enforce for every request or every write.
 */
#[CoversClass(SchemaProjector::class)]
#[Group('spec:document-structure')]
final class ConstraintContextProjectionTest extends TestCase
{
    /**
     * The one attribute's schema in the given representation.
     *
     * @return array<string, mixed>
     */
    private function attribute(FieldInterface $field, RepresentationContext $context): array
    {
        $attributes = (new SchemaProjector())->projectAttributes([$field], $context)->toArray();
        self::assertIsArray($attributes['properties'] ?? null);
        $schema = $attributes['properties'][$field->name()] ?? null;
        self::assertIsArray($schema);

        /** @var array<string, mixed> $schema */
        return $schema;
    }

    #[Test]
    public function aCreateOnlyBoundNarrowsOnlyTheCreateBody(): void
    {
        $field = Str::make('excerpt')->onCreate(static function (StrBuilder $f): void {
            $f->maxLength(280);
        })->build();

        self::assertSame(280, $this->attribute($field, RepresentationContext::Create)['maxLength'] ?? null);
        self::assertArrayNotHasKey('maxLength', $this->attribute($field, RepresentationContext::Update));
        // An update can write a longer value, so a response cannot promise the bound.
        self::assertArrayNotHasKey('maxLength', $this->attribute($field, RepresentationContext::Read));
    }

    #[Test]
    public function anUpdateOnlyBoundNarrowsOnlyTheUpdateBody(): void
    {
        $field = Integer::make('stock')->onUpdate(static function (IntegerBuilder $f): void {
            $f->min(0);
        })->build();

        self::assertSame(0, $this->attribute($field, RepresentationContext::Update)['minimum'] ?? null);
        self::assertArrayNotHasKey('minimum', $this->attribute($field, RepresentationContext::Create));
        self::assertArrayNotHasKey('minimum', $this->attribute($field, RepresentationContext::Read));
    }

    #[Test]
    public function aBoundOnEveryWriteThatCanSetTheFieldNarrowsTheRead(): void
    {
        // Only a create writes `revision`, so its create-only bound holds for every
        // stored value.
        $field = Integer::make('revision')->readOnlyOnUpdate()->onCreate(static function (IntegerBuilder $f): void {
            $f->min(1);
        })->build();

        self::assertSame(1, $this->attribute($field, RepresentationContext::Read)['minimum'] ?? null);
        self::assertSame(1, $this->attribute($field, RepresentationContext::Create)['minimum'] ?? null);
    }

    #[Test]
    public function anUnconditionalBoundReachesEverySchema(): void
    {
        $field = Integer::make('quantity')->min(1)->max(99)->build();

        foreach (RepresentationContext::cases() as $context) {
            $schema = $this->attribute($field, $context);
            self::assertSame(1, $schema['minimum'] ?? null, $context->name);
            self::assertSame(99, $schema['maximum'] ?? null, $context->name);
        }
    }

    #[Test]
    public function aNestedChildIsResolvedInTheReadContextToo(): void
    {
        $field = Obj::make('meta')->fields(
            Str::make('code')->onCreate(static function (StrBuilder $f): void {
                $f->maxLength(8);
            })->build(),
        )->build();

        // The child is optional, so a stored object may lack it and the read admits null.
        $read = $this->attribute($field, RepresentationContext::Read);
        self::assertIsArray($read['properties'] ?? null);
        self::assertSame(['type' => ['string', 'null']], $read['properties']['code'] ?? null);

        $create = $this->attribute($field, RepresentationContext::Create);
        self::assertIsArray($create['properties'] ?? null);
        self::assertSame(['type' => 'string', 'maxLength' => 8], $create['properties']['code'] ?? null);
    }

    #[Test]
    public function aNullableOnOneWriteAdmitsNullInTheRead(): void
    {
        $field = Str::make('nickname')->onCreate(static function (StrBuilder $f): void {
            $f->nullable();
        })->build();

        self::assertSame(['string', 'null'], $this->attribute($field, RepresentationContext::Create)['type'] ?? null);
        self::assertSame('string', $this->attribute($field, RepresentationContext::Update)['type'] ?? null);
        // A create can store null, so a response can carry it.
        self::assertSame(['string', 'null'], $this->attribute($field, RepresentationContext::Read)['type'] ?? null);
    }

    #[Test]
    public function aRequiredStringListOrObjectMayNotBeEmpty(): void
    {
        $string = Str::make('name')->required()->build();
        $list = ArrayList::make('tags')->required()->build();
        $hash = ArrayHash::make('settings')->required()->build();
        $map = Map::make('address')->fields(Str::make('city')->build())->required()->build();

        foreach (RepresentationContext::cases() as $context) {
            self::assertSame(1, $this->attribute($string, $context)['minLength'] ?? null, $context->name);
            self::assertSame(1, $this->attribute($list, $context)['minItems'] ?? null, $context->name);
            self::assertSame(1, $this->attribute($hash, $context)['minProperties'] ?? null, $context->name);
            self::assertSame(1, $this->attribute($map, $context)['minProperties'] ?? null, $context->name);
        }
    }

    #[Test]
    public function aRequiredFieldKeepsItsOwnTighterMinimum(): void
    {
        $string = Str::make('title')->required()->minLength(3)->build();
        $list = ArrayList::make('tags')->required()->minItems(2)->build();

        self::assertSame(3, $this->attribute($string, RepresentationContext::Create)['minLength'] ?? null);
        self::assertSame(2, $this->attribute($list, RepresentationContext::Create)['minItems'] ?? null);
    }

    #[Test]
    public function aRequiredNullableStringStillAdmitsNull(): void
    {
        $schema = $this->attribute(Str::make('note')->required()->nullable()->build(), RepresentationContext::Create);

        self::assertSame(['string', 'null'], $schema['type'] ?? null);
        self::assertSame(1, $schema['minLength'] ?? null);
    }

    #[Test]
    public function aRequiredScalarWithNoEmptyFormGainsNoKeyword(): void
    {
        self::assertSame(['type' => 'integer'], $this->attribute(Integer::make('count')->required()->build(), RepresentationContext::Create));
        self::assertSame(['type' => 'boolean'], $this->attribute(Boolean::make('agreed')->required()->build(), RepresentationContext::Create));
    }

    #[Test]
    public function aCreateOnlyRequiredIsNonEmptyOnlyOnCreate(): void
    {
        $field = Str::make('handle')->requiredOnCreate()->build();

        self::assertSame(1, $this->attribute($field, RepresentationContext::Create)['minLength'] ?? null);
        self::assertArrayNotHasKey('minLength', $this->attribute($field, RepresentationContext::Update));
        self::assertArrayNotHasKey('minLength', $this->attribute($field, RepresentationContext::Read));
    }

    #[Test]
    public function aConditionalConstraintIsNeverProjectedAsUnconditional(): void
    {
        $field = Str::make('reason')
            ->when(static fn(mixed $value): bool => $value !== null, static function (StrBuilder $f): void {
                $f->required()->minLength(10);
            })
            ->build();

        foreach (RepresentationContext::cases() as $context) {
            $schema = $this->attribute($field, $context);
            self::assertArrayNotHasKey('minLength', $schema, $context->name);
            self::assertIsString($schema['description'] ?? null, $context->name);
        }
        self::assertSame('string', $this->attribute($field, RepresentationContext::Create)['type'] ?? null);
        self::assertSame('string', $this->attribute($field, RepresentationContext::Update)['type'] ?? null);
        // A conditional `required()` binds only some creates, so the value is not guaranteed.
        self::assertSame(['string', 'null'], $this->attribute($field, RepresentationContext::Read)['type'] ?? null);

        $create = (new SchemaProjector())->projectAttributes([$field], RepresentationContext::Create)->toArray();
        self::assertSame([], $create['required'] ?? []);
    }

    #[Test]
    public function aConditionalNullableAdmitsNullInEverySchema(): void
    {
        $field = Str::make('reviewNote')
            ->when(static fn(mixed $value): bool => $value === null, static function (StrBuilder $f): void {
                $f->nullable();
            })
            ->build();

        foreach (RepresentationContext::cases() as $context) {
            self::assertSame(['string', 'null'], $this->attribute($field, $context)['type'] ?? null, $context->name);
        }
    }

    #[Test]
    public function anAlternativeKeepsItsPlaceWhateverItsContext(): void
    {
        // Dropping the create-only alternative from an update's `anyOf` would reject
        // the long values the server accepts through it.
        $field = Str::make('code')->atLeastOneOf(
            new MaxLength(4),
            new MinLength(20, Context::onlyCreate()),
        )->build();

        $anyOf = $this->attribute($field, RepresentationContext::Update)['anyOf'] ?? null;
        self::assertSame([['maxLength' => 4], ['minLength' => 20]], $anyOf);
    }

    #[Test]
    public function aListItemBoundFollowsTheContextToo(): void
    {
        $field = ArrayList::make('scores')->of('integer')
            ->onCreate(static function (ArrayListBuilder $f): void {
                $f->each(new Min(0, Context::onlyCreate()));
            })
            ->build();

        $create = $this->attribute($field, RepresentationContext::Create);
        self::assertSame(['type' => 'integer', 'minimum' => 0], $create['items'] ?? null);
        self::assertSame(['type' => 'integer'], $this->attribute($field, RepresentationContext::Read)['items'] ?? null);
    }

    #[Test]
    public function aFilterValueProjectsEveryConstraintItDeclares(): void
    {
        // A query value has no create/update context; a filter validator runs every
        // declared constraint.
        $filter = Where::make('code')->constrain(new MaxLength(5, Context::onlyCreate()), new MinLength(2, Context::onlyUpdate()))->build();

        self::assertSame(
            ['minLength' => 2, 'maxLength' => 5],
            (new SchemaProjector())->projectConstraints($filter->constraints())->toArray(),
        );
    }
}
