<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource;

use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\AdditionalProperties;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\MetaMisdeclared;
use haddowg\JsonApi\Resource\MetaShape;
use haddowg\JsonApi\Resource\ValueTypeMismatch;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Status;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetaShape::class)]
#[CoversClass(AdditionalProperties::class)]
final class MetaShapeTest extends TestCase
{
    #[Test]
    public function aDeclarationSeparatesItsMembersFromTheEntry(): void
    {
        $shape = MetaShape::of([Str::make('reason'), AdditionalProperties::make(), Integer::make('count')->build()], 'the bookings create request');

        self::assertSame(['reason', 'count'], self::names($shape->fields));
        self::assertNotNull($shape->additional);
        self::assertTrue($shape->isOpen());
        self::assertFalse($shape->isEmpty());
        self::assertSame('the bookings create request', $shape->owner);
        self::assertSame('count', $shape->field('count')?->name());
        self::assertNull($shape->field('missing'));
    }

    #[Test]
    public function anEmptyDeclarationStaysOpenAndUnchecked(): void
    {
        $shape = MetaShape::none();

        self::assertTrue($shape->isEmpty());
        self::assertTrue($shape->acceptsUndeclared());
        self::assertSame([], $shape->undeclared(['anything' => 1], '/meta'));
        self::assertSame([], $shape->violations(['anything' => 1]));
        self::assertSame(['anything' => 1, '7' => 'x'], $shape->values(['anything' => 1, 7 => 'x'], true));
    }

    #[Test]
    public function aMemberDeclaredTwiceIsRefused(): void
    {
        $this->expectException(MetaMisdeclared::class);
        $this->expectExceptionMessage('declares the member "reason" twice');

        MetaShape::of([Str::make('reason'), Str::make('reason')], 'the request');
    }

    #[Test]
    public function twoEntriesAreRefused(): void
    {
        $this->expectException(MetaMisdeclared::class);
        $this->expectExceptionMessage('more than one additionalProperties entry');

        MetaShape::of([AdditionalProperties::make(), AdditionalProperties::make()]);
    }

    #[Test]
    public function aMemberNamedLikeTheEntryIsRefused(): void
    {
        $this->expectException(MetaMisdeclared::class);
        $this->expectExceptionMessage('the name of the additionalProperties entry');

        MetaShape::of([Str::make('*')]);
    }

    #[Test]
    public function aClosedObjectReportsEveryUndeclaredMemberAtAnyDepth(): void
    {
        $shape = MetaShape::of([
            Str::make('reason'),
            Obj::make('source')->fields(Str::make('channel')),
            Str::make('locked')->readOnly(),
        ]);

        self::assertSame(
            ['/meta/colour', '/meta/source/extra', '/meta/a~1b'],
            $shape->undeclared(['reason' => 'x', 'colour' => 'red', 'source' => ['channel' => 'web', 'extra' => 1], 'locked' => 'y', 'a/b' => 1], '/meta'),
        );
        self::assertFalse($shape->acceptsUndeclared());
    }

    #[Test]
    public function anOpenObjectTakesUndeclaredMembersAndWalksTheValueField(): void
    {
        $open = MetaShape::of([Str::make('reason'), AdditionalProperties::make()]);
        self::assertSame([], $open->undeclared(['reason' => 'x', 'colour' => 'red'], '/meta'));
        self::assertTrue($open->acceptsUndeclared());

        $typed = MetaShape::of([AdditionalProperties::make()->of(Obj::make('*')->fields(Str::make('label')))]);
        self::assertSame(['/data/meta/first/extra'], $typed->undeclared(['first' => ['label' => 'a', 'extra' => 1]], '/data/meta'));
    }

    #[Test]
    public function mismatchesCoverDeclaredMembersAndTheEntrysValueField(): void
    {
        $shape = MetaShape::of([
            Integer::make('count'),
            Boolean::make('silent'),
            Str::make('locked')->readOnly(),
            AdditionalProperties::make()->of(Integer::make('*')),
        ]);

        $mismatches = $shape->mismatches(['count' => 'three', 'silent' => true, 'locked' => 5, 'extra' => 'nope'], '/meta', true);

        self::assertSame(['/meta/count', '/meta/extra'], \array_map(static fn(ValueTypeMismatch $m): string => $m->pointer, $mismatches));
        self::assertSame(['integer', 'integer'], \array_map(static fn(ValueTypeMismatch $m): string => $m->expected, $mismatches));
    }

    #[Test]
    public function valuesAreCastAsAttributesAreAndReadOnlyOrUndeclaredMembersAreLeftOut(): void
    {
        $shape = MetaShape::of([
            Str::make('status')->enum(Status::class),
            DateTime::make('at'),
            Str::make('locked')->readOnly(),
            Integer::make('count')->nullable(),
        ]);

        $values = $shape->values(['status' => 'published', 'at' => '2026-11-05T09:42:00+00:00', 'locked' => 'x', 'count' => null, 'extra' => 1], true);

        self::assertSame(Status::Published, $values['status']);
        self::assertInstanceOf(\DateTimeImmutable::class, $values['at']);
        self::assertNull($values['count']);
        self::assertArrayNotHasKey('locked', $values);
        self::assertArrayNotHasKey('extra', $values);
    }

    #[Test]
    public function theEntrysMembersAreCastByItsValueField(): void
    {
        $untyped = MetaShape::of([Str::make('reason'), AdditionalProperties::make()]);
        self::assertSame(['reason' => 'x', 'colour' => 'red'], $untyped->values(['reason' => 'x', 'colour' => 'red'], true));

        $typed = MetaShape::of([AdditionalProperties::make()->of(DateTime::make('*'))]);
        $values = $typed->values(['first' => '2026-11-05T09:42:00+00:00', 'none' => null], true);
        self::assertInstanceOf(\DateTimeImmutable::class, $values['first']);
        self::assertNull($values['none']);
    }

    #[Test]
    public function fillHandsOnlyHookedMembersToTheModel(): void
    {
        $shape = MetaShape::of([
            Str::make('source')->fillUsing(static function (\ArrayObject $model, mixed $value): void {
                $model['source'] = $value;
            }),
            Str::make('reason'),
            AdditionalProperties::make()->fillUsing(static function (\ArrayObject $model, array $members): void {
                $model['extras'] = $members;
            }),
        ]);
        $model = new \ArrayObject();

        $shape->fill($model, ['source' => 'web', 'reason' => 'kept off', 'colour' => 'red'], true, self::request());

        self::assertSame(['source' => 'web', 'extras' => ['colour' => 'red']], $model->getArrayCopy());
    }

    #[Test]
    public function fillLeavesTheModelAloneWithoutHooks(): void
    {
        $shape = MetaShape::of([Str::make('reason'), AdditionalProperties::make()]);
        $model = ['reason' => 'stored'];

        self::assertSame($model, $shape->fill($model, ['reason' => 'sent', 'colour' => 'red'], true, self::request()));
    }

    #[Test]
    public function extractRendersHookedMembersThenTheEntrysExtrasWithDeclaredMembersWinning(): void
    {
        $shape = MetaShape::of([
            Integer::make('unread')->extractUsing(static fn(\ArrayObject $inbox): int => \count($inbox)),
            Str::make('note'),
            Str::make('secret')->writeOnly()->extractUsing(static fn(): string => 'never'),
            AdditionalProperties::make()->of(Str::make('*'))->extractUsing(static fn(\ArrayObject $inbox): array => ['unread' => 'shadowed', 'label' => 'work', 'note' => 'shadowed']),
        ]);

        self::assertTrue($shape->extractsAny());
        self::assertSame(['unread' => 2, 'label' => 'work'], $shape->extract(new \ArrayObject([1, 2]), self::request()));
        self::assertFalse(MetaShape::of([Str::make('note')])->extractsAny());
    }

    #[Test]
    public function violationsFlagRequiredMissingUndeclaredAndWrongTypes(): void
    {
        $shape = MetaShape::of([Integer::make('unread')->required(), Str::make('note')]);

        self::assertSame([], $shape->violations(['unread' => 1, 'note' => 'x']));
        self::assertSame(['the required member "unread" is missing'], $shape->violations([]));
        self::assertSame(['the member "unread" is string, not integer', 'the member "extra" is not declared'], $shape->violations(['unread' => 'one', 'extra' => true]));
        self::assertSame([], $shape->violations(['unread' => 1, 'page' => []], ['page']));
    }

    #[Test]
    public function anOpenObjectChecksItsExtrasAgainstTheValueField(): void
    {
        self::assertSame([], MetaShape::of([AdditionalProperties::make()])->violations(['anything' => [1]]));
        self::assertSame(
            ['the member "label" is integer, not string or null'],
            MetaShape::of([AdditionalProperties::make()->of(Str::make('*'))])->violations(['label' => 3]),
        );
    }

    #[Test]
    public function theWritableMembersFollowTheWritesContext(): void
    {
        $shape = MetaShape::of([Str::make('a'), Str::make('b')->readOnlyOnCreate(), Str::make('c')->readOnlyOnUpdate(), Str::make('d')->hidden()]);

        self::assertSame(['a', 'c'], self::names($shape->writableFields(true)));
        self::assertSame(['a', 'b'], self::names($shape->writableFields(false, self::request())));
    }

    #[Test]
    public function theProjectedObjectIsClosedUnlessTheEntryOpensIt(): void
    {
        $schemas = new SchemaProjector();

        $closed = MetaShape::of([Str::make('reason')->required(), Str::make('locked')->readOnly()])->schema($schemas, writing: true);
        self::assertSame(['type' => 'object', 'properties' => ['reason' => ['type' => 'string', 'minLength' => 1]], 'required' => ['reason'], 'additionalProperties' => false], $closed->toArray());

        $read = MetaShape::of([Str::make('reason')->required(), Str::make('locked')->readOnly()])->schema($schemas);
        self::assertSame(['reason', 'locked'], \array_keys(self::properties($read->toArray())));

        self::assertTrue(MetaShape::of([AdditionalProperties::make()])->schema($schemas)->toArray()['additionalProperties']);
        self::assertSame(['type' => 'integer'], MetaShape::of([AdditionalProperties::make()->of(Integer::make('*'))])->schema($schemas, writing: true)->toArray()['additionalProperties']);
    }

    /**
     * @param iterable<\haddowg\JsonApi\Resource\Field\FieldInterface> $fields
     *
     * @return list<string>
     */
    private static function names(iterable $fields): array
    {
        $names = [];
        foreach ($fields as $field) {
            $names[] = $field->name();
        }

        return $names;
    }

    /**
     * @param array<array-key, mixed> $schema
     *
     * @return array<array-key, mixed>
     */
    private static function properties(array $schema): array
    {
        $properties = $schema['properties'] ?? [];
        self::assertIsArray($properties);

        return $properties;
    }

    private static function request(): JsonApiRequestInterface
    {
        return new JsonApiRequest(new ServerRequest('POST', '/bookings'));
    }
}
