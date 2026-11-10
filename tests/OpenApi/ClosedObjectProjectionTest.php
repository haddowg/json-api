<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\RepresentationContext;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The attributes object and every object a field declares are closed, unless a read hook
 * renders the object on a read.
 */
#[CoversClass(SchemaProjector::class)]
#[CoversClass(Obj::class)]
#[CoversClass(OneOf::class)]
final class ClosedObjectProjectionTest extends TestCase
{
    #[Test]
    public function theAttributesObjectIsClosedInEveryRepresentation(): void
    {
        foreach (RepresentationContext::cases() as $context) {
            $attributes = (new SchemaProjector())->projectAttributes([Str::make('title')->build()], $context)->toArray();

            self::assertFalse($attributes['additionalProperties'] ?? null, $context->name);
        }
    }

    #[Test]
    public function aTypeWithNoFieldInventoryKeepsItsAttributesOpen(): void
    {
        $standalone = (new SchemaProjector())->projectResourceObject('things', [])->toArray();
        $attributeless = (new SchemaProjector())->projectResourceObject('things', [Id::make()->build()])->toArray();

        self::assertSame(['type' => 'object'], self::at($standalone, 'properties', 'attributes'));
        self::assertFalse(self::at($attributeless, 'properties', 'attributes', 'additionalProperties'));
    }

    #[Test]
    public function everyDeclaredNestedObjectIsClosedInEveryRepresentation(): void
    {
        $fields = [
            Obj::make('meta')->fields(Str::make('a'))->build(),
            Map::make('info')->fields(Str::make('label'))->build(),
            OneOf::make('block')->discriminator('kind')->variant('circle', Str::make('radius'))->build(),
            ArrayList::make('steps')->of(Obj::make('step')->fields(Str::make('op')))->build(),
        ];

        foreach (RepresentationContext::cases() as $context) {
            self::assertFalse(self::at($this->schema($fields[0], $context), 'additionalProperties'), $context->name);
            self::assertFalse(self::at($this->schema($fields[1], $context), 'additionalProperties'), $context->name);
            self::assertFalse(self::at($this->schema($fields[2], $context), 'oneOf', 0, 'additionalProperties'), $context->name);
            self::assertFalse(self::at($this->schema($fields[3], $context), 'items', 'additionalProperties'), $context->name);
        }
    }

    #[Test]
    public function aSerializeHookOpensTheObjectOnlyOnRead(): void
    {
        $field = Obj::make('meta')->fields(Str::make('a'))->serializeUsing(static fn(): array => [])->build();

        self::assertArrayNotHasKey('additionalProperties', $this->schema($field, RepresentationContext::Read));
        self::assertFalse(self::at($this->schema($field, RepresentationContext::Create), 'additionalProperties'));
    }

    #[Test]
    public function anExtractHookKeepsEveryDeclaredShapeClosedOnRead(): void
    {
        $hook = static fn(): array => [];
        $fields = [
            Obj::make('meta')->fields(Str::make('a'))->extractUsing($hook)->build(),
            Map::make('info')->fields(Str::make('label'))->extractUsing($hook)->build(),
            OneOf::make('block')->discriminator('kind')->variant('circle', Str::make('radius'))->extractUsing($hook)->build(),
            ArrayList::make('steps')->of(Obj::make('step')->fields(Str::make('op')))->computedUsing($hook)->build(),
        ];

        self::assertFalse(self::at($this->schema($fields[0], RepresentationContext::Read), 'additionalProperties'));
        self::assertFalse(self::at($this->schema($fields[1], RepresentationContext::Read), 'additionalProperties'));
        self::assertFalse(self::at($this->schema($fields[2], RepresentationContext::Read), 'oneOf', 0, 'additionalProperties'));
        self::assertFalse(self::at($this->schema($fields[3], RepresentationContext::Read), 'items', 'additionalProperties'));
    }

    #[Test]
    public function aWriteHookKeepsTheObjectClosed(): void
    {
        $field = OneOf::make('block')->discriminator('kind')->variant('circle', Str::make('radius'))
            ->fillUsing(static fn(mixed $model): mixed => $model)
            ->build();

        foreach (RepresentationContext::cases() as $context) {
            self::assertFalse(self::at($this->schema($field, $context), 'oneOf', 0, 'additionalProperties'), $context->name);
        }
    }

    #[Test]
    public function aListReadHookOpensItsElementsOnlyOnRead(): void
    {
        $read = ArrayList::make('steps')->of(Obj::make('step')->fields(Str::make('op')))->serializeUsing(static fn(): array => [])->build();
        $write = ArrayList::make('steps')->of(Obj::make('step')->fields(Str::make('op')))->deserializeUsing(static fn(mixed $value): mixed => $value)->build();

        self::assertArrayNotHasKey('additionalProperties', self::obj($this->schema($read, RepresentationContext::Read), 'items'));
        self::assertFalse(self::at($this->schema($read, RepresentationContext::Create), 'items', 'additionalProperties'));
        foreach (RepresentationContext::cases() as $context) {
            self::assertFalse(self::at($this->schema($write, $context), 'items', 'additionalProperties'), $context->name);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(FieldInterface $field, RepresentationContext $context): array
    {
        $schema = self::at((new SchemaProjector())->projectAttributes([$field], $context)->toArray(), 'properties', $field->name());
        self::assertIsArray($schema);

        /** @var array<string, mixed> $schema */
        return $schema;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function obj(mixed $value, string|int ...$path): array
    {
        $value = self::at($value, ...$path);
        self::assertIsArray($value);

        return $value;
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
}
