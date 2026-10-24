<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\RepresentationContext;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A nested object's read schema lists every child the runtime always renders in its
 * `required`, the way the read attributes object lists every always-present attribute.
 */
#[CoversClass(SchemaProjector::class)]
#[CoversClass(Obj::class)]
#[CoversClass(OneOf::class)]
#[Group('spec:document-structure')]
final class NestedObjectReadRequiredTest extends TestCase
{
    /**
     * The field's schema inside the attributes object for the given representation.
     *
     * @return array<string, mixed>
     */
    private function schema(FieldInterface $field, RepresentationContext $context = RepresentationContext::Read): array
    {
        $attributes = (new SchemaProjector())->projectAttributes([$field], $context)->toArray();
        $schema = self::at($attributes, 'properties', $field->name());
        self::assertIsArray($schema);

        /** @var array<string, mixed> $schema */
        return $schema;
    }

    private static function at(mixed $value, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @return list<FieldInterface>
     */
    private static function mixedChildren(): array
    {
        return [
            Str::make('firstName')->guaranteed()->build(),
            Str::make('nickname')->build(),
            Str::make('secret')->writeOnly()->build(),
            Str::make('internal')->hidden()->build(),
            Str::make('note')->hidden(static fn(mixed $model, JsonApiRequestInterface $request): bool => true)->build(),
            Str::make('token')->writeOnly(static fn(JsonApiRequestInterface $request): bool => true)->build(),
        ];
    }

    #[Test]
    public function aMapListsEveryAlwaysRenderedChildAsRequiredOnRead(): void
    {
        $schema = $this->schema(Map::make('contact')->fields(...self::mixedChildren())->build());

        self::assertSame(['firstName', 'nickname'], self::at($schema, 'required'));
        self::assertSame(['firstName', 'nickname', 'note', 'token'], \array_keys((array) self::at($schema, 'properties')));
    }

    #[Test]
    public function anObjListsEveryAlwaysRenderedChildAsRequiredOnRead(): void
    {
        $schema = $this->schema(Obj::make('contact')->fields(...self::mixedChildren())->build());

        self::assertSame(['firstName', 'nickname'], self::at($schema, 'required'));
        self::assertSame(['firstName', 'nickname', 'note', 'token'], \array_keys((array) self::at($schema, 'properties')));
    }

    #[Test]
    public function presenceIsIndependentOfNull(): void
    {
        $schema = $this->schema(Obj::make('contact')->fields(...self::mixedChildren())->build());

        // Both are always present; only the guaranteed one is never null.
        self::assertSame('string', self::at($schema, 'properties', 'firstName', 'type'));
        self::assertSame(['string', 'null'], self::at($schema, 'properties', 'nickname', 'type'));
    }

    #[Test]
    public function aNullableObjRequiresItsChildrenWhenPresent(): void
    {
        $schema = $this->schema(Obj::make('address')->nullable()->fields(
            Str::make('street')->guaranteed()->build(),
            Str::make('city')->build(),
        )->build());

        self::assertSame(['object', 'null'], self::at($schema, 'type'));
        self::assertSame(['street', 'city'], self::at($schema, 'required'));
    }

    #[Test]
    public function theRuleRecursesThroughNestedObjects(): void
    {
        $schema = $this->schema(Map::make('profile')->fields(
            Obj::make('address')->fields(
                Str::make('street')->build(),
                Obj::make('geo')->fields(
                    Integer::make('lat')->build(),
                    Integer::make('lng')->writeOnly()->build(),
                )->build(),
            )->build(),
        )->build());

        self::assertSame(['address'], self::at($schema, 'required'));
        self::assertSame(['street', 'geo'], self::at($schema, 'properties', 'address', 'required'));
        self::assertSame(['lat'], self::at($schema, 'properties', 'address', 'properties', 'geo', 'required'));
    }

    #[Test]
    public function aParentWithASerializationHookClaimsNoChild(): void
    {
        $children = [Str::make('street')->guaranteed()->build()];
        $hook = static fn(mixed $model): array => [];

        foreach ([
            Obj::make('a')->serializeUsing($hook)->fields(...$children)->build(),
            Obj::make('b')->extractUsing($hook)->fields(...$children)->build(),
            Map::make('c')->serializeUsing($hook)->fields(...$children)->build(),
        ] as $field) {
            self::assertArrayNotHasKey('required', $this->schema($field), $field->name());
        }
    }

    #[Test]
    public function aOneOfBranchRequiresItsDiscriminatorAndRenderedChildrenOnRead(): void
    {
        $schema = $this->schema(OneOf::make('block')->discriminator('kind')
            ->variant('heading', Str::make('text')->build(), Str::make('draft')->writeOnly()->build())
            ->build());

        self::assertSame(['text', 'kind'], self::at($schema, 'oneOf', 0, 'required'));
    }

    #[Test]
    public function aOneOfWithASerializationHookClaimsNothing(): void
    {
        $schema = $this->schema(OneOf::make('block')->discriminator('kind')
            ->serializeUsing(static fn(mixed $model): array => [])
            ->variant('heading', Str::make('text')->build())
            ->build());

        self::assertArrayNotHasKey('required', (array) self::at($schema, 'oneOf', 0));
    }

    #[Test]
    public function createAndUpdateAreUnchanged(): void
    {
        $field = Obj::make('contact')->fields(
            Str::make('firstName')->required()->build(),
            Str::make('nickname')->build(),
            Str::make('secret')->writeOnly()->build(),
        )->build();

        $create = $this->schema($field, RepresentationContext::Create);
        self::assertSame(['firstName'], self::at($create, 'required'));
        self::assertArrayHasKey('secret', (array) self::at($create, 'properties'));

        $update = $this->schema($field, RepresentationContext::Update);
        self::assertArrayNotHasKey('required', $update);
        self::assertArrayHasKey('secret', (array) self::at($update, 'properties'));
    }

    #[Test]
    public function theReadSchemaAgreesWithWhatTheRuntimeRenders(): void
    {
        $values = ['firstName' => 'Ada', 'nickname' => null, 'secret' => 's', 'internal' => 'i', 'note' => 'n', 'token' => 't'];
        $request = new StubJsonApiRequest();

        foreach ([
            [Map::make('contact')->fields(...self::mixedChildren())->build(), $values],
            [Obj::make('contact')->fields(...self::mixedChildren())->build(), ['contact' => $values]],
        ] as [$field, $model]) {
            $rendered = $field->serialize($model, $request, 'contact');
            self::assertIsArray($rendered);
            $schema = $this->schema($field);

            /** @var list<string> $required */
            $required = self::at($schema, 'required');
            foreach ($required as $name) {
                self::assertArrayHasKey($name, $rendered, $field::class . ' must render a required child');
            }
            foreach (\array_keys($rendered) as $name) {
                self::assertArrayHasKey($name, (array) self::at($schema, 'properties'), $field::class . ' renders an undeclared child');
            }
        }
    }
}
