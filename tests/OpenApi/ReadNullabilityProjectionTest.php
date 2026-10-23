<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\EnumComponentCollector;
use haddowg\JsonApi\OpenApi\RepresentationContext;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Url;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Status;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A read schema types an attribute without `null` only when the server guarantees a
 * value: every create had to supply it, a hook typed non-null produces it, or the
 * author asserted it with `guaranteed()`.
 */
#[CoversClass(SchemaProjector::class)]
#[Group('spec:document-structure')]
final class ReadNullabilityProjectionTest extends TestCase
{
    /**
     * The read attributes object for the given fields.
     *
     * @param list<FieldInterface> $fields
     *
     * @return array<string, mixed>
     */
    private function read(array $fields, ?EnumComponentCollector $collector = null): array
    {
        /** @var array<string, mixed> */
        return (new SchemaProjector())->projectAttributes($fields, RepresentationContext::Read, $collector)->toArray();
    }

    /**
     * The one attribute's schema in the given representation.
     *
     * @return array<string, mixed>
     */
    private function attribute(FieldInterface $field, RepresentationContext $context = RepresentationContext::Read): array
    {
        $attributes = (new SchemaProjector())->projectAttributes([$field], $context)->toArray();
        self::assertIsArray($attributes['properties'] ?? null);
        $schema = $attributes['properties'][$field->name()] ?? null;
        self::assertIsArray($schema);

        /** @var array<string, mixed> $schema */
        return $schema;
    }

    /**
     * The value at `$path` inside a decoded schema, asserting each step is an array.
     */
    private static function at(mixed $value, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    #[Test]
    public function anOptionalNonNullableAttributeIsNullableOnRead(): void
    {
        // A create may leave it out, and the stored value is then null.
        $field = Str::make('nickname')->build();

        self::assertSame(['string', 'null'], $this->attribute($field)['type'] ?? null);
        self::assertSame('string', $this->attribute($field, RepresentationContext::Create)['type'] ?? null);
        self::assertSame('string', $this->attribute($field, RepresentationContext::Update)['type'] ?? null);
    }

    #[Test]
    public function anAttributeRequiredOnCreateIsNonNullOnRead(): void
    {
        self::assertSame('string', $this->attribute(Str::make('name')->required()->build())['type'] ?? null);
        self::assertSame('string', $this->attribute(Str::make('handle')->requiredOnCreate()->build())['type'] ?? null);
        self::assertSame('integer', $this->attribute(Integer::make('count')->required()->build())['type'] ?? null);
    }

    #[Test]
    public function aRequiredAttributeAWriteMayNullStillAdmitsNull(): void
    {
        $field = Str::make('note')->required()->nullable()->build();

        self::assertSame(['string', 'null'], $this->attribute($field)['type'] ?? null);
    }

    #[Test]
    public function aRequiredValueOnlySomeCreatesSupplyIsNullableOnRead(): void
    {
        // Required only on update, or on a field a create may not write, or on a field
        // a request-aware rule lets a create skip: no create is bound to supply it.
        $onUpdate = Str::make('reason')->requiredOnUpdate()->build();
        $notOnCreate = Str::make('approver')->readOnlyOnCreate()->required()->build();
        $sometimes = Str::make('code')->required()
            ->readOnlyOnCreate(static fn(JsonApiRequestInterface $request): bool => true)
            ->build();

        self::assertSame(['string', 'null'], $this->attribute($onUpdate)['type'] ?? null);
        self::assertSame(['string', 'null'], $this->attribute($notOnCreate)['type'] ?? null);
        self::assertSame(['string', 'null'], $this->attribute($sometimes)['type'] ?? null);
    }

    #[Test]
    public function aComputedValueFollowsItsDeclaredReturnType(): void
    {
        $typed = Integer::make('commentCount')->computedUsing(static fn(mixed $model): int => 0)->build();
        $nullable = Integer::make('rank')->computedUsing(static fn(mixed $model): ?int => null)->build();
        $untyped = Integer::make('score')->computedUsing(static fn(mixed $model) => 0)->build();

        self::assertSame('integer', $this->attribute($typed)['type'] ?? null);
        self::assertSame(['integer', 'null'], $this->attribute($nullable)['type'] ?? null);
        self::assertSame(['integer', 'null'], $this->attribute($untyped)['type'] ?? null);
    }

    #[Test]
    public function aSerializeHookDecidesOverTheWriteConstraints(): void
    {
        // The hook replaces the stored value, so only its return type says what renders.
        $untyped = Str::make('title')->required()->serializeUsing(static fn(mixed $model) => 'x')->build();
        $typed = Str::make('label')->nullable()->serializeUsing(static fn(mixed $model): string => 'x')->build();

        self::assertSame(['string', 'null'], $this->attribute($untyped)['type'] ?? null);
        self::assertSame('string', $this->attribute($typed)['type'] ?? null);
    }

    #[Test]
    public function aReadOnlyAttributeIsNullableUnlessAsserted(): void
    {
        // Core can't tell whether the server always populates a field no write sets.
        $plain = DateTime::make('lastSeenAt')->readOnly()->build();
        $asserted = DateTime::make('createdAt')->readOnly()->guaranteed()->build();

        self::assertSame(['string', 'null'], $this->attribute($plain)['type'] ?? null);
        self::assertSame(['type' => 'string', 'format' => 'date-time'], $this->attribute($asserted));
    }

    #[Test]
    public function anAssertedAttributeIsNonNullOnReadOnly(): void
    {
        // A default the server fills in: optional on create, never null on read. The
        // request bodies keep their own nullability.
        $field = Str::make('status')->nullable()->guaranteed()->build();

        self::assertSame('string', $this->attribute($field)['type'] ?? null);
        self::assertSame(['string', 'null'], $this->attribute($field, RepresentationContext::Create)['type'] ?? null);
        self::assertSame(['string', 'null'], $this->attribute($field, RepresentationContext::Update)['type'] ?? null);
    }

    #[Test]
    public function aNestedChildFollowsTheSameRule(): void
    {
        $field = Obj::make('contact')->required()->fields(
            Str::make('email')->required()->build(),
            Str::make('phone')->build(),
            Str::make('verifiedAt')->readOnly()->guaranteed()->build(),
        )->build();

        $schema = $this->attribute($field);
        self::assertSame('object', $schema['type'] ?? null);
        self::assertIsArray($schema['properties'] ?? null);
        self::assertSame('string', self::at($schema, 'properties', 'email', 'type'));
        self::assertSame(['string', 'null'], self::at($schema, 'properties', 'phone', 'type'));
        self::assertSame('string', self::at($schema, 'properties', 'verifiedAt', 'type'));

        $map = $this->attribute(Map::make('address')->fields(
            Str::make('street')->required()->build(),
            Str::make('city')->build(),
        )->build());
        self::assertSame(['object', 'null'], $map['type'] ?? null);
        self::assertIsArray($map['properties'] ?? null);
        self::assertSame('string', self::at($map, 'properties', 'street', 'type'));
        self::assertSame(['string', 'null'], self::at($map, 'properties', 'city', 'type'));
    }

    #[Test]
    public function anOptionalUnionGainsANullBranchAndKeepsItsDiscriminator(): void
    {
        $field = OneOf::make('block')->discriminator('kind')
            ->variant('heading', Str::make('text')->required()->build())
            ->variant('image', Url::make('src')->build())
            ->build();

        $schema = $this->attribute($field);
        self::assertIsArray($schema['oneOf'] ?? null);
        self::assertCount(3, $schema['oneOf']);
        self::assertSame(['type' => 'null'], self::at($schema, 'oneOf', 2));
        self::assertSame(['type' => 'string', 'const' => 'heading'], self::at($schema, 'oneOf', 0, 'properties', 'kind'));
        self::assertSame('string', self::at($schema, 'oneOf', 0, 'properties', 'text', 'type'));
        self::assertSame(['string', 'null'], self::at($schema, 'oneOf', 1, 'properties', 'src', 'type'));
    }

    #[Test]
    public function aFlattenedAttributeIsNullableEvenWhenRequired(): void
    {
        // Any hop in the to-one chain can be null, which short-circuits the value.
        $required = Str::make('authorName')->on('author')->required()->build();
        $asserted = Str::make('publisherName')->on('publisher')->guaranteed()->build();

        self::assertSame(['string', 'null'], $this->attribute($required)['type'] ?? null);
        self::assertSame('string', $this->attribute($asserted)['type'] ?? null);
    }

    #[Test]
    public function anOptionalEnumAdmitsNullInlineAndByReference(): void
    {
        $inline = $this->attribute(Str::make('status')->enum(Status::class)->build());
        self::assertSame(['string', 'null'], $inline['type'] ?? null);
        self::assertSame(['draft', 'published', 'archived', null], $inline['enum'] ?? null);

        $required = $this->attribute(Str::make('status')->enum(Status::class)->required()->build());
        self::assertSame(['draft', 'published', 'archived'], $required['enum'] ?? null);

        $collector = new EnumComponentCollector();
        $hoisted = $this->read([Str::make('status')->enum(Status::class)->build()], $collector);
        self::assertSame(
            ['anyOf' => [['$ref' => '#/components/schemas/Status'], ['type' => 'null']]],
            self::at($hoisted, 'properties', 'status'),
        );
    }

    #[Test]
    public function theIdIsNeverNullable(): void
    {
        $resource = (new SchemaProjector())->projectResourceObject('articles', [Id::make()->build()])->toArray();

        self::assertSame(['type' => 'string'], self::at($resource, 'properties', 'id'));
    }

    #[Test]
    public function presenceStaysSeparateFromNullability(): void
    {
        // Every rendered attribute is present whatever its value, so a nullable one is
        // still required. A sparse-by-default one renders only when a fieldset names it.
        $read = $this->read([
            Str::make('nickname')->build(),
            Str::make('name')->required()->build(),
            Integer::make('score')->sparseByDefault()->build(),
        ]);

        self::assertSame(['nickname', 'name'], $read['required'] ?? null);
    }
}
