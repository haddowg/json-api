<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\Exception\AttributeValueInvalid;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Constraint\MinLength;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\ArrayListBuilder;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A list whose elements are an `Obj` or `OneOf` shape reads and writes each element
 * through that shape's children.
 */
#[CoversClass(ArrayList::class)]
#[CoversClass(ArrayListBuilder::class)]
#[CoversClass(Obj::class)]
#[CoversClass(OneOf::class)]
final class StructuredArrayListTest extends TestCase
{
    private const string AT = '2024-07-01T12:30:00+00:00';

    // ---- Scalar lists keep their behaviour ------------------------------------------

    #[Test]
    public function aScalarListIsUnchanged(): void
    {
        $field = ArrayList::make('tags')->of('integer')->each(new MinLength(1))->sorted()->build();

        self::assertSame('integer', $field->elementType());
        self::assertNull($field->element());
        self::assertSame([1, 2, 3], $field->serialize(['tags' => [3, 1, 2]], $this->request(), 'tags'));
        self::assertSame(['tags' => ['b', 'a']], $field->hydrate([], ['b', 'a'], [], $this->request(), true));
        // A scalar list has never checked its shape on write, and still does not.
        self::assertSame(['tags' => ['k' => 'v']], $field->hydrate([], ['k' => 'v'], [], $this->request(), true));
    }

    #[Test]
    public function anUnknownScalarTypeIsStillRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ArrayList::make('tags')->of('object');
    }

    // ---- Declaring a structured element ---------------------------------------------

    #[Test]
    public function aBuilderElementIsBuiltWithTheList(): void
    {
        $field = $this->steps()->build();

        self::assertSame('object', $field->elementType());
        self::assertInstanceOf(OneOf::class, $field->element());
        self::assertSame('operation', $field->element()->discriminatorName());
    }

    #[Test]
    public function aBuiltElementIsAccepted(): void
    {
        $element = Obj::make('source')->fields(Str::make('href'))->build();

        self::assertSame($element, ArrayList::make('sources')->of($element)->build()->element());
    }

    #[Test]
    public function aLaterScalarTypeReplacesTheElement(): void
    {
        $field = ArrayList::make('sources')->of(Obj::make('source'))->of('integer')->build();

        self::assertNull($field->element());
        self::assertSame('integer', $field->elementType());
    }

    #[Test]
    public function eachIsRefusedOnAStructuredListWhicheverComesFirst(): void
    {
        foreach ([
            ArrayList::make('sources')->each(new MinLength(1))->of(Obj::make('source')),
            ArrayList::make('sources')->of(Obj::make('source'))->each(new MinLength(1)),
        ] as $builder) {
            try {
                $builder->build();
                self::fail('Expected each() on a structured list to be refused.');
            } catch (\LogicException $e) {
                self::assertStringContainsString('each()', $e->getMessage());
            }
        }
    }

    #[Test]
    public function sortedIsRefusedOnAStructuredList(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('sorted()');

        ArrayList::make('sources')->of(Obj::make('source'))->sorted()->build();
    }

    #[Test]
    public function anElementWithItsOwnHookIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('hook');

        ArrayList::make('sources')->of(Obj::make('source')->fillUsing(static fn(mixed $model): mixed => $model))->build();
    }

    // ---- Reads ----------------------------------------------------------------------

    #[Test]
    public function anObjListRendersEachElementThroughItsChildrenInOrder(): void
    {
        $field = ArrayList::make('sources')->of(Obj::make('source')->fields(
            Str::make('href')->storedAs('url'),
            DateTime::make('retrievedAt'),
            Str::make('secret')->hidden(),
            Str::make('token')->writeOnly(),
        ))->build();

        $stored = [
            3 => ['url' => 'https://b.example', 'retrievedAt' => new \DateTimeImmutable(self::AT), 'secret' => 's', 'token' => 't'],
            1 => ['url' => 'https://a.example', 'retrievedAt' => null],
        ];

        self::assertSame([
            ['href' => 'https://b.example', 'retrievedAt' => self::AT],
            ['href' => 'https://a.example', 'retrievedAt' => null],
        ], $field->serialize(['sources' => $stored], $this->request(), 'sources'));
    }

    #[Test]
    public function aOneOfListRendersEachElementThroughItsVariant(): void
    {
        $stored = [
            ['operation' => 'find_replace', 'params' => ['find' => 'a', 'replace' => 'b']],
            ['operation' => 'trim'],
            ['operation' => 'shout', 'loud' => true],
        ];

        self::assertSame([
            ['operation' => 'find_replace', 'params' => ['find' => 'a', 'replace' => 'b']],
            ['operation' => 'trim'],
            // An element naming no variant renders as stored, as a OneOf attribute does.
            ['operation' => 'shout', 'loud' => true],
        ], $this->steps()->build()->serialize(['steps' => $stored], $this->request(), 'steps'));
    }

    #[Test]
    public function anEmptyOrNullListKeepsItsShape(): void
    {
        $field = $this->steps()->build();

        self::assertSame([], $field->serialize(['steps' => []], $this->request(), 'steps'));
        self::assertNull($field->serialize(['steps' => null], $this->request(), 'steps'));
    }

    #[Test]
    public function aTraversableListRenders(): void
    {
        $field = ArrayList::make('sources')->of(Obj::make('source')->fields(Str::make('href')))->build();

        self::assertSame(
            [['href' => 'x']],
            $field->serialize(['sources' => new \ArrayIterator([['href' => 'x']])], $this->request(), 'sources'),
        );
    }

    #[Test]
    public function anExtractedListRendersEachElementAsAComputedValue(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $field = ArrayList::make('sources')
            ->of(Obj::make('source')->fields(DateTime::make('retrievedAt'), Str::make('href')))
            ->extractUsing(static fn(mixed $model): array => [['retrievedAt' => $at, 'extra' => 1]])
            ->build();

        // A computed element leaves an absent child absent and passes an undeclared key
        // through, as a computed Obj does.
        self::assertSame([['retrievedAt' => self::AT, 'extra' => 1]], $field->serialize([], $this->request(), 'sources'));
    }

    #[Test]
    public function aSerializeHookOnTheListOwnsTheWholeValue(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $field = ArrayList::make('sources')
            ->of(Obj::make('source')->fields(DateTime::make('retrievedAt')))
            ->serializeUsing(static fn(mixed $model, JsonApiRequestInterface $request, string $name): array => [['retrievedAt' => $at]])
            ->build();

        self::assertSame([['retrievedAt' => $at]], $field->serialize([], $this->request(), 'sources'));
    }

    #[Test]
    public function theDefaultOutputTimezoneReachesTheElementsChildren(): void
    {
        $field = ArrayList::make('sources')->of(Obj::make('source')->fields(DateTime::make('retrievedAt')))->build();
        $zoned = $field->withDefaultOutputTimezone('UTC');
        $stored = ['sources' => [['retrievedAt' => new \DateTimeImmutable('2024-07-01 12:30:00', new \DateTimeZone('Europe/Paris'))]]];

        self::assertSame([['retrievedAt' => '2024-07-01T10:30:00+00:00']], $zoned->serialize($stored, $this->request(), 'sources'));
        $scalar = ArrayList::make('tags')->build();
        self::assertSame($scalar, $scalar->withDefaultOutputTimezone('UTC'));
    }

    #[Test]
    public function aListNestedInAnElementRendersThroughTheSameRecursion(): void
    {
        $field = ArrayList::make('sections')->of(Obj::make('section')->fields(
            Str::make('title'),
            ArrayList::make('entries')->of(Obj::make('entry')->fields(DateTime::make('at'))),
        ))->build();

        $stored = ['sections' => [['title' => 'A', 'entries' => [['at' => new \DateTimeImmutable(self::AT)]]]]];

        self::assertSame(
            [['title' => 'A', 'entries' => [['at' => self::AT]]]],
            $field->serialize($stored, $this->request(), 'sections'),
        );

        $model = $field->hydrate([], [['title' => 'B', 'entries' => [['at' => self::AT]]]], [], $this->request(), true);
        self::assertInstanceOf(\DateTimeImmutable::class, self::at($model, 'sections', 0, 'entries', 0, 'at'));
    }

    // ---- Writes ---------------------------------------------------------------------

    #[Test]
    public function anObjListHydratesEachElementThroughItsChildren(): void
    {
        $field = ArrayList::make('sources')->storedAs('source_list')->of(Obj::make('source')->fields(
            Str::make('href')->storedAs('url'),
            DateTime::make('retrievedAt'),
            Integer::make('rank')->fillUsing(static function (mixed $element, mixed $value): array {
                \assert(\is_array($element) && \is_int($value));
                $element['rank_x10'] = $value * 10;

                return $element;
            }),
        ))->build();

        $model = $field->hydrate([], [
            ['href' => 'https://a.example', 'retrievedAt' => self::AT, 'rank' => 2],
            ['href' => 'https://b.example', 'ignored' => true],
        ], [], $this->request(), true);

        self::assertIsArray($model);
        self::assertArrayHasKey('source_list', $model);
        $list = $model['source_list'];
        self::assertIsArray($list);
        self::assertCount(2, $list);
        self::assertIsArray($list[0]);
        self::assertSame('https://a.example', $list[0]['url']);
        self::assertSame(20, $list[0]['rank_x10']);
        self::assertInstanceOf(\DateTimeImmutable::class, $list[0]['retrievedAt']);
        self::assertSame(self::AT, $list[0]['retrievedAt']->format(\DateTimeInterface::ATOM));
        // An undeclared key is not written.
        self::assertSame(['url' => 'https://b.example'], $list[1]);
    }

    #[Test]
    public function aOneOfListHydratesEachElementThroughItsVariant(): void
    {
        $model = $this->steps()->build()->hydrate([], [
            ['operation' => 'trim', 'params' => ['find' => 'x', 'replace' => 'y']],
            ['operation' => 'find_replace', 'params' => ['find' => 'a', 'replace' => 'b']],
        ], [], $this->request(), true);

        self::assertSame(['steps' => [
            // trim declares no children, so only its discriminator is kept.
            ['operation' => 'trim'],
            ['operation' => 'find_replace', 'params' => ['find' => 'a', 'replace' => 'b']],
        ]], $model);
    }

    #[Test]
    public function anUnknownDiscriminatorIsKeptAsSentAtItsIndex(): void
    {
        // As for a OneOf attribute, hydration does not validate: the adapter's validator
        // rejects it at /data/attributes/steps/1/operation.
        $model = $this->steps()->build()->hydrate([], [
            ['operation' => 'trim'],
            ['operation' => 'shout', 'loud' => true],
            ['loud' => true],
        ], [], $this->request(), true);

        self::assertSame(['steps' => [
            ['operation' => 'trim'],
            ['operation' => 'shout', 'loud' => true],
            ['loud' => true],
        ]], $model);
    }

    #[Test]
    public function anUpdateReplacesTheWholeListAndBuildsEveryElementFresh(): void
    {
        $field = ArrayList::make('sources')->of(Obj::make('source')->fields(
            Str::make('href'),
            Str::make('label'),
            Str::make('createdBy')->readOnlyOnUpdate(),
        ))->build();
        $stored = ['sources' => [
            ['href' => 'a', 'label' => 'kept?', 'createdBy' => 'greg'],
            ['href' => 'b', 'label' => 'gone'],
        ]];

        $model = $field->hydrate($stored, [['href' => 'c', 'createdBy' => 'mallory']], [], $this->request(), false);

        // Nothing is merged from the stored element at the same index, and a child an
        // update may not write is left out of the new element.
        self::assertSame(['sources' => [['href' => 'c']]], $model);
    }

    #[Test]
    public function anEmptyListClearsTheElementsAndNullClearsTheList(): void
    {
        $field = $this->steps()->build();
        $stored = ['steps' => [['operation' => 'trim']]];

        self::assertSame(['steps' => []], $field->hydrate($stored, [], [], $this->request(), false));
        self::assertSame(['steps' => null], $field->hydrate($stored, null, [], $this->request(), false));
    }

    #[Test]
    public function aFillHookOnTheListOwnsTheWrite(): void
    {
        $field = ArrayList::make('steps')
            ->of(OneOf::make('step')->variant('trim'))
            ->fillUsing(static fn(mixed $model, mixed $value): array => ['raw' => $value])
            ->build();

        self::assertSame(['raw' => [['nope' => 1]]], $field->hydrate([], [['nope' => 1]], [], $this->request(), true));
    }

    #[Test]
    public function aListForAnElementIsRefusedAtItsIndex(): void
    {
        $this->assertRefused(
            $this->steps()->build(),
            [['operation' => 'trim'], ['operation' => 'trim'], ['trim', 'x']],
            '/data/attributes/steps/2',
            'expected an object, got a list',
        );
    }

    #[Test]
    public function aListForAnObjectChildIsRefusedUnderItsElement(): void
    {
        $this->assertRefused(
            $this->steps()->build(),
            [['operation' => 'find_replace', 'params' => ['a', 'b']]],
            '/data/attributes/steps/0/params',
            'expected an object, got a list',
        );
    }

    #[Test]
    public function anObjectForTheListIsRefused(): void
    {
        $this->assertRefused($this->steps()->build(), ['operation' => 'trim'], '/data/attributes/steps', 'expected a list, got an object');
        $this->assertRefused($this->steps()->build(), 'trim', '/data/attributes/steps', 'expected a list, got string');
    }

    #[Test]
    public function anUnparseableDateChildIsRefusedUnderItsElement(): void
    {
        $field = ArrayList::make('sources')->of(Obj::make('source')->fields(DateTime::make('retrievedAt')))->build();

        $this->assertRefused($field, [['retrievedAt' => self::AT], ['retrievedAt' => 'next blursday']], '/data/attributes/sources/1/retrievedAt');
    }

    #[Test]
    public function aScalarElementIsKeptForTheValidatorToReject(): void
    {
        $field = ArrayList::make('sources')->of(Obj::make('source')->fields(Str::make('href')))->build();

        self::assertSame(['sources' => [['href' => 'a'], 'b', null]], $field->hydrate([], [['href' => 'a'], 'b', null], [], $this->request(), true));
    }

    private function steps(): ArrayListBuilder
    {
        return ArrayList::make('steps')
            ->of(OneOf::make('step')
                ->discriminator('operation')
                ->variant('trim')
                ->variant('find_replace', Obj::make('params')->fields(
                    Str::make('find')->guaranteed(),
                    Str::make('replace')->guaranteed(),
                )))
            ->maxItems(50);
    }

    private function assertRefused(ArrayList $field, mixed $value, string $pointer, ?string $reason = null): void
    {
        try {
            $field->hydrate([], $value, [], $this->request(), true);
            self::fail('Expected an AttributeValueInvalid to be thrown.');
        } catch (AttributeValueInvalid $e) {
            $error = $e->getErrors()[0];
            self::assertSame('ATTRIBUTE_VALUE_INVALID', $error->code);
            self::assertNotNull($error->source);
            self::assertSame($pointer, $error->source->pointer);
            if ($reason !== null) {
                self::assertSame($reason, $e->reason);
            }
        }
    }

    private function request(): StubJsonApiRequest
    {
        return new StubJsonApiRequest();
    }

    private static function at(mixed $value, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
