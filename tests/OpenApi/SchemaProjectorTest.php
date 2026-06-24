<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\EnumDescriptionMode;
use haddowg\JsonApi\OpenApi\RepresentationContext;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Resource\Constraint\Comparison;
use haddowg\JsonApi\Resource\Constraint\MaxLength;
use haddowg\JsonApi\Resource\Constraint\MinLength;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Email;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Ip;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Slug;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Time;
use haddowg\JsonApi\Resource\Field\Url;
use haddowg\JsonApi\Resource\Field\Uuid;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Priority;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Status;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaProjector::class)]
#[CoversClass(Schema::class)]
#[CoversClass(EnumDescriptionMode::class)]
#[Group('spec:document-structure')]
final class SchemaProjectorTest extends TestCase
{
    private function projector(EnumDescriptionMode $mode = EnumDescriptionMode::Both): SchemaProjector
    {
        return new SchemaProjector($mode);
    }

    /**
     * @return array<string, mixed>
     */
    private function project(FieldInterface $field, bool $creating = false): array
    {
        return $this->projector()->projectField($field, $creating)->toArray();
    }

    /**
     * Walks a nested array by key path, narrowing at each step (the
     * {@see \haddowg\JsonApi\Tests\Validation\SchemaCompilerTest} idiom).
     *
     * @param array<string, mixed> $schema
     */
    private function at(array $schema, string ...$keys): mixed
    {
        $cursor = $schema;
        foreach ($keys as $key) {
            self::assertIsArray($cursor);
            self::assertArrayHasKey($key, $cursor);
            $cursor = $cursor[$key];
        }

        return $cursor;
    }

    /**
     * Like {@see at()} but asserts (and types) the leaf as a list.
     *
     * @param array<string, mixed> $schema
     * @return list<mixed>
     */
    private function listAt(array $schema, string ...$keys): array
    {
        $value = $this->at($schema, ...$keys);
        self::assertIsArray($value);

        return \array_values($value);
    }

    /**
     * Like {@see at()} but asserts (and types) the leaf as an array.
     *
     * @param array<string, mixed> $schema
     * @return array<array-key, mixed>
     */
    private function arrAt(array $schema, string ...$keys): array
    {
        $value = $this->at($schema, ...$keys);
        self::assertIsArray($value);

        return $value;
    }

    /**
     * Like {@see at()} but asserts (and types) the leaf as a string.
     *
     * @param array<string, mixed> $schema
     */
    private function stringAt(array $schema, string ...$keys): string
    {
        $value = $this->at($schema, ...$keys);
        self::assertIsString($value);

        return $value;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function missing(array $schema, string $key): void
    {
        self::assertArrayNotHasKey($key, $schema);
    }

    // ---- 4.1 field type → base schema ----

    #[Test]
    public function stringFieldProjectsToStringType(): void
    {
        self::assertSame('string', $this->at($this->project(Str::make('s')), 'type'));
    }

    #[Test]
    public function integerFieldProjectsToIntegerType(): void
    {
        self::assertSame('integer', $this->at($this->project(Integer::make('n')), 'type'));
    }

    #[Test]
    public function decimalFieldProjectsToNumberType(): void
    {
        self::assertSame('number', $this->at($this->project(Decimal::make('d')), 'type'));
    }

    #[Test]
    public function booleanFieldProjectsToBooleanType(): void
    {
        self::assertSame('boolean', $this->at($this->project(Boolean::make('b')), 'type'));
    }

    #[Test]
    public function dateTimeFamilyProjectsTheRightFormat(): void
    {
        self::assertSame(['type' => 'string', 'format' => 'date'], $this->project(Date::make('d')));
        self::assertSame(['type' => 'string', 'format' => 'time'], $this->project(Time::make('t')));
        self::assertSame(['type' => 'string', 'format' => 'date-time'], $this->project(DateTime::make('dt')));
    }

    #[Test]
    public function formatStringTypesProjectTheirFormat(): void
    {
        self::assertSame('email', $this->at($this->project(Email::make('e')), 'format'));
        self::assertSame('uri', $this->at($this->project(Url::make('u')), 'format'));
        self::assertSame('uuid', $this->at($this->project(Uuid::make('id')), 'format'));
        self::assertSame('ipv4', $this->at($this->project(Ip::make('ip')), 'format'));
    }

    #[Test]
    public function slugProjectsAPatternNotAFormat(): void
    {
        $schema = $this->project(Slug::make('slug'));

        self::assertSame('string', $this->at($schema, 'type'));
        self::assertArrayHasKey('pattern', $schema);
        $this->missing($schema, 'format');

        // A default slug carries a readable example so a renderer does not synthesise a
        // gibberish string from the pattern (and the example satisfies the slug shape).
        self::assertSame('example-slug', $this->at($schema, 'example'));
        self::assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'example-slug');
    }

    #[Test]
    public function slugWithACustomRegexGetsNoDefaultExampleAndAnExplicitOneWins(): void
    {
        // A custom slug regex may not match the default example, so no default is preset.
        $custom = $this->project(Str::make('slug')->slug('^[A-Z]+$'));
        self::assertArrayNotHasKey('example', $custom);

        // An author-supplied example always wins over the default.
        $explicit = $this->project(Slug::make('slug')->example('my-mix'));
        self::assertSame('my-mix', $this->at($explicit, 'example'));
    }

    #[Test]
    public function arrayListProjectsToArrayWithItems(): void
    {
        $schema = $this->project(ArrayList::make('tags'));

        self::assertSame('array', $this->at($schema, 'type'));
        self::assertSame([], $this->at($schema, 'items'));
    }

    #[Test]
    public function arrayHashProjectsToObjectWithAdditionalProperties(): void
    {
        $schema = $this->project(ArrayHash::make('meta'));

        self::assertSame('object', $this->at($schema, 'type'));
        self::assertSame([], $this->at($schema, 'additionalProperties'));
    }

    // ---- 4.2 constraint → keyword ----

    #[Test]
    public function stringLengthAndPatternConstraints(): void
    {
        $schema = $this->project(Str::make('s')->minLength(1)->maxLength(5)->pattern('^x'));

        self::assertSame(1, $this->at($schema, 'minLength'));
        self::assertSame(5, $this->at($schema, 'maxLength'));
        self::assertSame('^x', $this->at($schema, 'pattern'));
    }

    #[Test]
    public function numericBoundConstraints(): void
    {
        $schema = $this->project(Integer::make('n')->min(0)->max(10)->multipleOf(2));

        self::assertSame(0, $this->at($schema, 'minimum'));
        self::assertSame(10, $this->at($schema, 'maximum'));
        self::assertSame(2, $this->at($schema, 'multipleOf'));
    }

    #[Test]
    public function exclusiveBoundConstraints(): void
    {
        $schema = $this->project(Decimal::make('d')->exclusiveMin(0.0)->exclusiveMax(1.0));

        self::assertSame(0.0, $this->at($schema, 'exclusiveMinimum'));
        self::assertSame(1.0, $this->at($schema, 'exclusiveMaximum'));
    }

    #[Test]
    public function arrayBoundConstraints(): void
    {
        $schema = $this->project(ArrayList::make('tags')->minItems(1)->maxItems(10)->uniqueItems());

        self::assertSame(1, $this->at($schema, 'minItems'));
        self::assertSame(10, $this->at($schema, 'maxItems'));
        self::assertTrue($this->at($schema, 'uniqueItems'));
    }

    #[Test]
    public function objectPropertyBoundConstraints(): void
    {
        $schema = $this->project(ArrayHash::make('m')->minProperties(1)->maxProperties(3));

        self::assertSame(1, $this->at($schema, 'minProperties'));
        self::assertSame(3, $this->at($schema, 'maxProperties'));
    }

    #[Test]
    public function eachConstraintProjectsToItems(): void
    {
        $schema = $this->project(ArrayList::make('codes')->each(new MinLength(2)));

        self::assertSame(['minLength' => 2], $this->at($schema, 'items'));
    }

    #[Test]
    public function notInProjectsToNotEnum(): void
    {
        $schema = $this->project(Str::make('s')->notIn(['x', 'y']));

        self::assertSame(['enum' => ['x', 'y']], $this->at($schema, 'not'));
    }

    #[Test]
    public function sequentiallyMergesInline(): void
    {
        $schema = $this->project(Str::make('code')->sequentially(new MinLength(3), new MaxLength(8)));

        self::assertSame(3, $this->at($schema, 'minLength'));
        self::assertSame(8, $this->at($schema, 'maxLength'));
    }

    #[Test]
    public function atLeastOneOfProjectsToAnyOf(): void
    {
        $schema = $this->project(Str::make('ref')->atLeastOneOf(new MinLength(8), new MaxLength(2)));

        self::assertSame([['minLength' => 8], ['maxLength' => 2]], $this->at($schema, 'anyOf'));
    }

    #[Test]
    public function fixedDateBoundsDegradeToDescriptionNotes(): void
    {
        // JSON Schema 2020-12 has no standard keyword bounding a date-time STRING
        // (`minimum`/`maximum` are numeric-only; `formatMinimum`/`formatMaximum`
        // are a non-standard vocab no conformant consumer honours), so a fixed
        // bound surfaces as a human-readable note carrying its literal value rather
        // than a wrong keyword.
        $field = DateTime::make('when')->between(
            new \DateTimeImmutable('2020-01-01T00:00:00+00:00'),
            new \DateTimeImmutable('2020-12-31T00:00:00+00:00'),
        );
        $schema = $this->project($field);

        $this->missing($schema, 'formatMinimum');
        $this->missing($schema, 'formatMaximum');
        $this->missing($schema, 'minimum');
        $this->missing($schema, 'maximum');

        $description = $this->stringAt($schema, 'description');
        self::assertStringContainsString('must be on or after `2020-01-01T00:00:00+00:00`', $description);
        self::assertStringContainsString('must be on or before `2020-12-31T00:00:00+00:00`', $description);
    }

    // ---- nullable ----

    #[Test]
    public function nullableWidensTheType(): void
    {
        self::assertSame(['integer', 'null'], $this->at($this->project(Integer::make('n')->nullable()), 'type'));
    }

    #[Test]
    public function nullableEnumAddsNullToTheEnumList(): void
    {
        // `enum` is an absolute whitelist that overrides the type union, so a
        // nullable enumerated field must carry `null` in `enum` to accept its own
        // legitimate null value.
        $schema = $this->project(Str::make('status')->enum(Status::class)->nullable());

        self::assertSame(['string', 'null'], $this->at($schema, 'type'));
        self::assertSame(['draft', 'published', 'archived', null], $this->at($schema, 'enum'));
    }

    #[Test]
    public function nullableInListAddsNullToTheEnumList(): void
    {
        $schema = $this->project(Str::make('size')->in(['s', 'm', 'l'])->nullable());

        self::assertSame(['string', 'null'], $this->at($schema, 'type'));
        self::assertSame(['s', 'm', 'l', null], $this->at($schema, 'enum'));
    }

    // ---- Map cascade ----

    #[Test]
    public function mapCascadesChildFieldsIntoProperties(): void
    {
        $field = Map::make('address')->fields(
            Str::make('street')->maxLength(100),
            Integer::make('zip')->min(0),
        );
        $schema = $this->project($field);

        self::assertSame('object', $this->at($schema, 'type'));
        self::assertSame(['type' => 'string', 'maxLength' => 100], $this->at($schema, 'properties', 'street'));
        self::assertSame(['type' => 'integer', 'minimum' => 0], $this->at($schema, 'properties', 'zip'));
    }

    #[Test]
    public function mapCollectsRequiredChildrenOnCreateOnly(): void
    {
        $field = Map::make('address')->fields(
            Str::make('street')->required(),
            Str::make('line2'),
        );

        // Read projection: no nested `required` (mirrors top-level attributes).
        $this->missing($this->project($field), 'required');

        // Create projection: the required child populates the Map object `required`.
        $create = $this->project($field, creating: true);
        self::assertSame(['street'], $this->at($create, 'required'));
    }

    // ---- enums (4.8) ----

    #[Test]
    public function enumWithDescriptionsEmitsAllSurfacesInBothMode(): void
    {
        $schema = $this->project(Str::make('status')->enum(Status::class));

        self::assertSame('string', $this->at($schema, 'type'));
        self::assertSame(['draft', 'published', 'archived'], $this->at($schema, 'enum'));
        self::assertSame(['Draft', 'Published', 'Archived'], $this->at($schema, 'x-enum-varnames'));
        self::assertSame(['Not yet visible to readers', 'Live and public', ''], $this->at($schema, 'x-enum-descriptions'));

        $description = $this->stringAt($schema, 'description');
        self::assertStringContainsString('| Value | Description |', $description);
        self::assertStringContainsString('| `draft` | Not yet visible to readers |', $description);
        self::assertStringContainsString('| `archived` |  |', $description);
    }

    #[Test]
    public function intBackedEnumFollowsBackingTypeAndEmitsVarnamesWithoutDescriptions(): void
    {
        $schema = $this->project(Integer::make('priority')->enum(Priority::class));

        self::assertSame('integer', $this->at($schema, 'type'));
        self::assertSame([1, 2], $this->at($schema, 'enum'));
        self::assertSame(['Low', 'High'], $this->at($schema, 'x-enum-varnames'));
        $this->missing($schema, 'x-enum-descriptions');
        $this->missing($schema, 'description');
    }

    #[Test]
    public function emptyEnumIsOmittedRatherThanProjectedAsInvalidEnum(): void
    {
        // `enum: []` is an invalid 2020-12 schema (the keyword requires a non-empty
        // array), so a degenerate empty value set emits no `enum` at all.
        $schema = $this->project(Str::make('x')->in([]));

        $this->missing($schema, 'enum');
        self::assertSame('string', $this->at($schema, 'type'));
    }

    #[Test]
    public function extensionsOnlyModeOmitsTheMarkdownTable(): void
    {
        $schema = $this->projector(EnumDescriptionMode::Extensions)
            ->projectField(Str::make('status')->enum(Status::class))
            ->toArray();

        self::assertSame(['Draft', 'Published', 'Archived'], $this->at($schema, 'x-enum-varnames'));
        self::assertArrayHasKey('x-enum-descriptions', $schema);
        $this->missing($schema, 'description');
    }

    #[Test]
    public function descriptionOnlyModeOmitsTheExtensions(): void
    {
        $schema = $this->projector(EnumDescriptionMode::Description)
            ->projectField(Str::make('status')->enum(Status::class))
            ->toArray();

        $this->missing($schema, 'x-enum-varnames');
        $this->missing($schema, 'x-enum-descriptions');
        self::assertStringContainsString('| `draft` | Not yet visible to readers |', $this->stringAt($schema, 'description'));
    }

    #[Test]
    public function plainInEnumEmitsNoEnumMetadata(): void
    {
        $schema = $this->project(Str::make('s')->in(['a', 'b']));

        self::assertSame(['a', 'b'], $this->at($schema, 'enum'));
        $this->missing($schema, 'x-enum-varnames');
        $this->missing($schema, 'description');
    }

    // ---- lossy degradation ----

    #[Test]
    public function compareFieldDegradesToADescriptionNote(): void
    {
        $schema = $this->project(Integer::make('end')->compareWith('start', Comparison::GreaterThan));

        $this->missing($schema, 'not');
        self::assertStringContainsString('compared against the `start` field', $this->stringAt($schema, 'description'));
    }

    #[Test]
    public function conditionalWhenDegradesToADescriptionNote(): void
    {
        $field = Str::make('s')->when(static fn(mixed $v): bool => true, static function (Str $f): void {
            $f->minLength(5);
        });
        $schema = $this->project($field);

        $this->missing($schema, 'minLength');
        self::assertStringContainsString('conditional rule', $this->stringAt($schema, 'description'));
    }

    #[Test]
    public function closureDateBoundDegradesToADescriptionNote(): void
    {
        $field = DateTime::make('when')->after(static fn(): \DateTimeImmutable => new \DateTimeImmutable('now'));
        $schema = $this->project($field);

        $this->missing($schema, 'formatMinimum');
        self::assertStringContainsString('dynamically-resolved date/time bound', $this->stringAt($schema, 'description'));
    }

    #[Test]
    public function authorDescriptionPrecedesDegradationNotes(): void
    {
        $field = Integer::make('end')
            ->describedAs('The end value.')
            ->compareWith('start', Comparison::GreaterThan);
        $schema = $this->project($field);

        $description = $this->stringAt($schema, 'description');
        self::assertStringStartsWith('The end value.', $description);
        self::assertStringContainsString('compared against the `start` field', $description);
    }

    // ---- description / example surfacing ----

    #[Test]
    public function descriptionAndExampleSurfaceOnTheSchema(): void
    {
        $schema = $this->project(Str::make('name')->describedAs('A name')->example('Ada'));

        self::assertSame('A name', $this->at($schema, 'description'));
        self::assertSame('Ada', $this->at($schema, 'example'));
    }

    // ---- attributes + resource object ----

    #[Test]
    public function attributesProjectionExcludesIdRelationshipsHiddenAndWriteOnlyOnRead(): void
    {
        $schema = $this->projector()->projectAttributes($this->fields())->toArray();

        self::assertSame('object', $this->at($schema, 'type'));
        $properties = $this->at($schema, 'properties');
        self::assertIsArray($properties);
        self::assertArrayHasKey('name', $properties);
        self::assertArrayHasKey('status', $properties);
        // id is not an attribute; secret is write-only (excluded from read); internal is hidden.
        self::assertArrayNotHasKey('id', $properties);
        self::assertArrayNotHasKey('secret', $properties);
        self::assertArrayNotHasKey('internal', $properties);
        // No required[] on a read projection.
        $this->missing($schema, 'required');
    }

    #[Test]
    public function createAttributesProjectionIncludesWriteOnlyAndRequired(): void
    {
        $schema = $this->projector()->projectAttributes($this->fields(), RepresentationContext::Create)->toArray();

        $properties = $this->at($schema, 'properties');
        self::assertIsArray($properties);
        self::assertArrayHasKey('secret', $properties);
        self::assertContains('name', $this->listAt($schema, 'required'));
    }

    #[Test]
    public function writeContextsExcludeReadOnlyAndUpdateCarriesNoRequired(): void
    {
        $read = $this->projector()->projectAttributes($this->fields(), RepresentationContext::Read)->toArray();
        $create = $this->projector()->projectAttributes($this->fields(), RepresentationContext::Create)->toArray();
        $update = $this->projector()->projectAttributes($this->fields(), RepresentationContext::Update)->toArray();

        // A read-only field is in the read body but neither write body.
        self::assertArrayHasKey('derived', $this->arrAt($read, 'properties'));
        self::assertArrayNotHasKey('derived', $this->arrAt($create, 'properties'));
        self::assertArrayNotHasKey('derived', $this->arrAt($update, 'properties'));

        // A write-only field is writable in create AND update, absent from the read.
        self::assertArrayHasKey('secret', $this->arrAt($create, 'properties'));
        self::assertArrayHasKey('secret', $this->arrAt($update, 'properties'));
        self::assertArrayNotHasKey('secret', $this->arrAt($read, 'properties'));

        // create carries the required set; update is partial (no `required`).
        self::assertContains('name', $this->listAt($create, 'required'));
        $this->missing($update, 'required');
    }

    #[Test]
    public function readOnlyContextDistinguishesCreateFromUpdate(): void
    {
        // `readOnlyOnCreate()` is writable on update but not on create.
        $fields = [Id::make(), Str::make('name'), Str::make('handle')->readOnlyOnCreate()];

        $create = $this->projector()->projectAttributes($fields, RepresentationContext::Create)->toArray();
        $update = $this->projector()->projectAttributes($fields, RepresentationContext::Update)->toArray();

        self::assertArrayNotHasKey('handle', $this->arrAt($create, 'properties'));
        self::assertArrayHasKey('handle', $this->arrAt($update, 'properties'));
    }

    #[Test]
    public function resourceObjectProjectionHasTheCanonicalMembers(): void
    {
        $schema = $this->projector()->projectResourceObject('articles', $this->fields())->toArray();

        self::assertSame('object', $this->at($schema, 'type'));
        self::assertSame(['type' => 'string', 'const' => 'articles'], $this->at($schema, 'properties', 'type'));
        // A response resource object requires both `type` and `id` (JSON:API §7.2).
        self::assertSame(['type', 'id'], $this->at($schema, 'required'));
        self::assertSame('string', $this->at($schema, 'properties', 'id', 'type'));
        self::assertSame('object', $this->at($schema, 'properties', 'attributes', 'type'));

        $properties = $this->at($schema, 'properties');
        self::assertIsArray($properties);
        self::assertArrayHasKey('relationships', $properties);
        self::assertArrayHasKey('links', $properties);
        self::assertArrayHasKey('meta', $properties);
    }

    #[Test]
    public function resourceObjectIdHonoursTheDeclaredIdPattern(): void
    {
        $schema = $this->projector()->projectResourceObject('articles', [Id::make()->numeric(), Str::make('name')])->toArray();

        $id = $this->at($schema, 'properties', 'id');
        self::assertIsArray($id);
        self::assertArrayHasKey('pattern', $id);
    }

    #[Test]
    public function resourceObjectFallsBackToAGeneratedDescriptionWhenNoneIsDeclared(): void
    {
        $schema = $this->projector()->projectResourceObject('articles', $this->fields())->toArray();

        self::assertSame('An `articles` resource object.', $this->at($schema, 'description'));
    }

    #[Test]
    public function resourceObjectSurfacesTheDeclaredDescription(): void
    {
        $schema = $this->projector()
            ->projectResourceObject('articles', $this->fields(), null, 'A blog article.')
            ->toArray();

        self::assertSame('A blog article.', $this->at($schema, 'description'));
    }

    /**
     * The fixture field inventory shared by the attributes/resource-object tests.
     *
     * @return list<FieldInterface>
     */
    private function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->required()->maxLength(50),
            Str::make('status')->enum(Status::class),
            Str::make('secret')->writeOnly(),
            Str::make('derived')->readOnly(),
            Str::make('internal')->hidden(),
        ];
    }
}
