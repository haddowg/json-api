<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\Exception\AttributeValueInvalid;
use haddowg\JsonApi\Exception\RequestBodyInvalidJsonApi;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\In;
use haddowg\JsonApi\Resource\Field\AbstractFieldBuilder;
use haddowg\JsonApi\Resource\Field\AbstractFieldValue;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Union;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortHandler;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Validation\DocumentValidator;
use haddowg\JsonApi\Validation\SchemaCompiler;
use haddowg\JsonApi\Validation\VendoredSchemaProvider;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A field declared with `enum()` hydrates a written backing value as the enum's case and
 * renders a case as its backing value. A value nested in a JSON column keeps the backing
 * value, as a nested date keeps its string.
 */
#[CoversClass(AbstractFieldValue::class)]
#[CoversClass(AbstractFieldBuilder::class)]
#[CoversClass(ArrayList::class)]
#[CoversClass(Union::class)]
#[CoversClass(Obj::class)]
#[CoversClass(OneOf::class)]
#[CoversClass(Map::class)]
#[CoversClass(ArrayFilterHandler::class)]
#[CoversClass(ArraySortHandler::class)]
final class EnumHydrationTest extends TestCase
{
    // ---- Top-level writes -----------------------------------------------------------

    #[Test]
    public function aTypedSetterReceivesTheCase(): void
    {
        $run = new EnumHydrationRun();

        $this->resource()->hydrate($this->create(['state' => 'cancelled']), $run);

        self::assertSame(EnumHydrationState::Cancelled, $run->state());
    }

    #[Test]
    public function aTypedPropertyReceivesTheCase(): void
    {
        $run = new EnumHydrationRun();

        $this->resource()->hydrate($this->create(['priority' => 2]), $run);

        self::assertSame(EnumHydrationPriority::High, $run->priority);
    }

    #[Test]
    public function nullStaysNullOnANullableField(): void
    {
        $run = new EnumHydrationRun();
        $run->setPrevious(EnumHydrationState::Running);

        $this->resource()->hydrate($this->update(['previous' => null]), $run);

        self::assertNull($run->previous());
    }

    #[Test]
    public function aDecimalFieldTakesAnIntBackedEnumFromAWholeNumber(): void
    {
        $field = Decimal::make('priority')->enum(EnumHydrationPriority::class)->build();

        self::assertSame(['priority' => EnumHydrationPriority::High], $field->hydrate([], 2, [], $this->request(), true));
    }

    #[Test]
    public function inOverOneEnumsCasesConvertsAsEnumDoes(): void
    {
        $field = Str::make('state')->in([EnumHydrationState::Running, EnumHydrationState::Cancelled])->build();

        self::assertSame(['state' => EnumHydrationState::Running], $field->hydrate([], 'running', [], $this->request(), true));
    }

    #[Test]
    public function aPlainInIsUntouched(): void
    {
        $field = Str::make('state')->in(['running', 'cancelled'])->build();

        self::assertSame(['state' => 'running'], $field->hydrate([], 'running', [], $this->request(), true));
    }

    #[Test]
    public function aListOfEnumsHydratesEachElementAsItsCase(): void
    {
        $field = $this->stateList()->build();

        self::assertSame(
            ['states' => [EnumHydrationState::Running, EnumHydrationState::Cancelled]],
            $field->hydrate([], ['running', 'cancelled'], [], $this->request(), true),
        );
    }

    #[Test]
    public function aMapChildWritesItsColumnAsTheCase(): void
    {
        $field = Map::make('status')->fields(Str::make('state')->enum(EnumHydrationState::class))->build();

        self::assertSame(['state' => EnumHydrationState::Running], $field->hydrate([], ['state' => 'running'], [], $this->request(), true));
    }

    #[Test]
    public function aTopLevelUnionMemberHydratesTheCase(): void
    {
        $field = $this->union()->build();

        self::assertSame(['value' => EnumHydrationState::Cancelled], $field->hydrate([], 'cancelled', [], $this->request(), true));
        self::assertSame(['value' => true], $field->hydrate([], true, [], $this->request(), true));
    }

    #[Test]
    public function aUnionStoresTheBackingValueAndReadsBackTheCase(): void
    {
        $field = $this->union()->build();

        self::assertSame('cancelled', $field->toStoredForm(EnumHydrationState::Cancelled));
        self::assertSame(EnumHydrationState::Cancelled, $field->fromStoredForm('cancelled'));
    }

    #[Test]
    public function castWireValueReturnsTheCase(): void
    {
        self::assertSame(EnumHydrationState::Running, Str::make('state')->enum(EnumHydrationState::class)->build()->castWireValue('running'));
    }

    // ---- Nested writes --------------------------------------------------------------

    #[Test]
    public function anObjChildKeepsTheBackingValue(): void
    {
        $field = Obj::make('settings')->fields(
            Str::make('state')->enum(EnumHydrationState::class),
            ArrayList::make('history')->each(new In(['running', 'cancelled'], enumClass: EnumHydrationState::class)),
            $this->union(),
        )->build();

        self::assertSame(
            ['settings' => ['state' => 'running', 'history' => ['cancelled'], 'value' => 'cancelled']],
            $field->hydrate([], ['state' => 'running', 'history' => ['cancelled'], 'value' => 'cancelled'], [], $this->request(), true),
        );
    }

    #[Test]
    public function aOneOfVariantChildKeepsTheBackingValue(): void
    {
        $field = OneOf::make('block')->discriminator('kind')
            ->variant('run', Str::make('state')->enum(EnumHydrationState::class))
            ->build();

        self::assertSame(
            ['block' => ['kind' => 'run', 'state' => 'running']],
            $field->hydrate([], ['kind' => 'run', 'state' => 'running'], [], $this->request(), true),
        );
    }

    #[Test]
    public function aStructuredListElementKeepsTheBackingValue(): void
    {
        $field = ArrayList::make('steps')->of(Obj::make('step')->fields(Str::make('state')->enum(EnumHydrationState::class)))->build();

        self::assertSame(['steps' => [['state' => 'running']]], $field->hydrate([], [['state' => 'running']], [], $this->request(), true));
    }

    #[Test]
    public function aListOfUnionsKeepsTheBackingValue(): void
    {
        $field = ArrayList::make('values')->of(Union::of(Str::make()->enum(EnumHydrationState::class), Boolean::make()))->build();

        self::assertSame(['values' => ['running', false]], $field->hydrate([], ['running', false], [], $this->request(), true));
    }

    // ---- Hooks ----------------------------------------------------------------------

    #[Test]
    public function aFillHookReceivesTheCase(): void
    {
        $received = null;
        $field = Str::make('state')->enum(EnumHydrationState::class)
            ->fillUsing(static function (mixed $model, mixed $value) use (&$received): mixed {
                $received = $value;

                return $model;
            })
            ->build();

        $field->hydrate([], 'running', [], $this->request(), true);

        self::assertSame(EnumHydrationState::Running, $received);
    }

    #[Test]
    public function aDeserializeHookKeepsTheString(): void
    {
        $field = Str::make('state')->enum(EnumHydrationState::class)
            ->deserializeUsing(static fn(mixed $value): mixed => $value)
            ->build();

        self::assertSame(['state' => 'running'], $field->hydrate([], 'running', [], $this->request(), true));
    }

    // ---- Reads ----------------------------------------------------------------------

    #[Test]
    public function aCaseRendersAsItsBackingValue(): void
    {
        $run = new EnumHydrationRun();
        $run->setState(EnumHydrationState::Running);
        $run->priority = EnumHydrationPriority::High;
        $run->states = [EnumHydrationState::Running, EnumHydrationState::Cancelled];

        $attributes = $this->attributes($run);

        self::assertSame('running', $attributes['state']);
        self::assertSame(2, $attributes['priority']);
        self::assertSame(['running', 'cancelled'], $attributes['states']);
    }

    #[Test]
    public function aPlainStringRendersAsItIs(): void
    {
        $field = Str::make('state')->enum(EnumHydrationState::class)->build();

        self::assertSame('running', $field->serialize(['state' => 'running'], $this->request(), 'state'));
        self::assertSame(['running'], $this->stateList()->build()->serialize(['states' => ['running']], $this->request(), 'states'));
    }

    #[Test]
    public function anExtractedCaseRendersAsItsBackingValue(): void
    {
        $field = Str::make('state')->enum(EnumHydrationState::class)
            ->extractUsing(static fn(): EnumHydrationState => EnumHydrationState::Cancelled)
            ->build();

        self::assertSame('cancelled', $field->serialize([], $this->request(), 'state'));
    }

    #[Test]
    public function aNestedCaseRendersAsItsBackingValue(): void
    {
        $field = Obj::make('settings')->fields(Str::make('state')->enum(EnumHydrationState::class), $this->union())->build();

        self::assertSame(
            '{"state":"running","value":"cancelled"}',
            \json_encode($field->serialize(['settings' => ['state' => EnumHydrationState::Running, 'value' => EnumHydrationState::Cancelled]], $this->request(), 'settings')),
        );
    }

    // ---- Filters and sorts ----------------------------------------------------------

    #[Test]
    public function inMemoryFiltersCompareAStoredCaseByItsBackingValue(): void
    {
        $rows = [['id' => 1, 'state' => EnumHydrationState::Running], ['id' => 2, 'state' => EnumHydrationState::Cancelled]];
        $handler = new ArrayFilterHandler();

        self::assertSame([2], \array_column(\array_values($handler->apply(Where::make('state')->build(), $rows, 'cancelled')), 'id'));
        self::assertSame([1, 2], \array_column(\array_values($handler->apply(WhereIn::make('state')->build(), $rows, ['running', 'cancelled'])), 'id'));
    }

    #[Test]
    public function inMemorySortsOrderAStoredCaseByItsBackingValue(): void
    {
        $rows = [['id' => 1, 'priority' => EnumHydrationPriority::High], ['id' => 2, 'priority' => EnumHydrationPriority::Low]];

        $handler = new ArraySortHandler();
        $ascending = $handler->apply([new SortDirective(SortByField::make('priority'), false)], $rows);
        $descending = $handler->apply([new SortDirective(SortByField::make('priority'), true)], \array_reverse($rows));

        self::assertSame([2, 1], \array_column(\array_values($ascending), 'id'));
        self::assertSame([1, 2], \array_column(\array_values($descending), 'id'));
    }

    // ---- Refusals -------------------------------------------------------------------

    #[Test]
    public function validationRefusesAValueOutsideTheEnumBeforeAnyConversion(): void
    {
        $body = ['data' => ['type' => 'runs', 'id' => '1', 'attributes' => ['state' => 'exploded']]];

        $this->expectException(RequestBodyInvalidJsonApi::class);

        (new DocumentValidator(new VendoredSchemaProvider()))->validateRequest($body, [(new SchemaCompiler())->compile($this->resource(), false)]);
    }

    #[Test]
    public function anUnvalidatedValueOutsideTheEnumIsRefusedWithA422(): void
    {
        try {
            $this->resource()->hydrate($this->create(['state' => 'exploded']), new EnumHydrationRun());
            self::fail('Expected the value to be refused.');
        } catch (AttributeValueInvalid $e) {
            self::assertSame(422, $e->getStatusCode());
            self::assertSame('state', $e->attribute);
        }

        try {
            $this->stateList()->build()->hydrate([], ['running', 'exploded'], [], $this->request(), true);
            self::fail('Expected the element to be refused.');
        } catch (AttributeValueInvalid $e) {
            self::assertSame('states/1', $e->attribute);
        }
    }

    #[Test]
    public function aValueOfTheWrongTypeIsLeftForTheValidator(): void
    {
        $field = Str::make('state')->enum(EnumHydrationState::class)->build();

        self::assertSame(['state' => 7], $field->hydrate([], 7, [], $this->request(), true));
    }

    #[Test]
    public function enumRefusesAPureEnum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('enum() takes a backed enum');

        // @phpstan-ignore argument.type (a pure enum is what is refused)
        Str::make('state')->enum(EnumHydrationPure::class);
    }

    // ---- Helpers --------------------------------------------------------------------

    private function stateList(): \haddowg\JsonApi\Resource\Field\ArrayListBuilder
    {
        return ArrayList::make('states')->each(new In(['running', 'cancelled'], enumClass: EnumHydrationState::class));
    }

    private function union(): \haddowg\JsonApi\Resource\Field\UnionBuilder
    {
        return Union::make('value')->of(Str::make()->enum(EnumHydrationState::class), Boolean::make());
    }

    private function resource(): AbstractResource
    {
        return new class extends AbstractResource {
            public static string $type = 'runs';

            public function fields(): array
            {
                return [
                    Id::make(),
                    Str::make('state')->enum(EnumHydrationState::class),
                    Str::make('previous')->enum(EnumHydrationState::class)->nullable(),
                    Integer::make('priority')->enum(EnumHydrationPriority::class),
                    ArrayList::make('states')->each(new In(['running', 'cancelled'], enumClass: EnumHydrationState::class)),
                ];
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(EnumHydrationRun $run): array
    {
        $attributes = [];
        foreach ($this->resource()->getAttributes($run, $this->request()) as $name => $render) {
            $attributes[$name] = $render($run, $this->request(), $name);
        }

        return $attributes;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function create(array $attributes): JsonApiRequest
    {
        return $this->write('POST', ['type' => 'runs', 'attributes' => $attributes]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function update(array $attributes): JsonApiRequest
    {
        return $this->write('PATCH', ['type' => 'runs', 'id' => '1', 'attributes' => $attributes]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(string $method, array $data): JsonApiRequest
    {
        return new JsonApiRequest(
            (new ServerRequest($method, '/runs'))
                ->withParsedBody(['data' => $data])
                ->withHeader('Content-Type', 'application/vnd.api+json'),
        );
    }

    private function request(): StubJsonApiRequest
    {
        return new StubJsonApiRequest();
    }
}

enum EnumHydrationState: string
{
    case Running = 'running';
    case Cancelled = 'cancelled';
}

enum EnumHydrationPriority: int
{
    case Low = 1;
    case High = 2;
}

enum EnumHydrationPure
{
    case Only;
}

final class EnumHydrationRun
{
    public ?EnumHydrationPriority $priority = null;

    /**
     * @var list<EnumHydrationState>
     */
    public array $states = [];

    private ?EnumHydrationState $state = null;

    private ?EnumHydrationState $previous = null;

    public function state(): ?EnumHydrationState
    {
        return $this->state;
    }

    public function getState(): ?EnumHydrationState
    {
        return $this->state;
    }

    public function setState(EnumHydrationState $state): void
    {
        $this->state = $state;
    }

    public function previous(): ?EnumHydrationState
    {
        return $this->previous;
    }

    public function getPrevious(): ?EnumHydrationState
    {
        return $this->previous;
    }

    public function setPrevious(?EnumHydrationState $previous): void
    {
        $this->previous = $previous;
    }
}
