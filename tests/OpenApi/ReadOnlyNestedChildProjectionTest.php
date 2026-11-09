<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\RepresentationContext;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A nested child read-only for a write is left out of that write's schema, as a read-only
 * attribute is, wherever the child sits: an object, a map, a list element or a variant.
 */
#[CoversClass(SchemaProjector::class)]
final class ReadOnlyNestedChildProjectionTest extends TestCase
{
    #[Test]
    public function aReadOnlyChildIsLeftOutOfEveryWriteAndKeptOnRead(): void
    {
        foreach ($this->composites() as $name => $field) {
            self::assertSame(['label', 'created', 'locked', 'gated'], $this->childNames($field, RepresentationContext::Read, $name), $name . ' read');
            self::assertSame(['label', 'locked', 'gated'], $this->childNames($field, RepresentationContext::Create, $name), $name . ' create');
            self::assertSame(['label', 'gated'], $this->childNames($field, RepresentationContext::Update, $name), $name . ' update');
        }
    }

    /**
     * @return array<string, FieldInterface>
     */
    private function composites(): array
    {
        return [
            'obj' => Obj::make('box')->fields(...$this->children())->build(),
            'map' => Map::make('box')->fields(...$this->children())->build(),
            'list' => ArrayList::make('box')->of(Obj::make('item')->fields(...$this->children()))->build(),
            'variant' => OneOf::make('box')->discriminator('kind')->variant('one', ...$this->children())->build(),
        ];
    }

    /**
     * @return list<FieldInterface>
     */
    private function children(): array
    {
        return [
            Str::make('label')->build(),
            Str::make('created')->readOnly()->build(),
            Str::make('locked')->readOnlyOnUpdate()->build(),
            // A predicate-gated child stays, as a predicate-gated attribute does.
            Str::make('gated')->readOnly(static fn(): bool => true)->build(),
        ];
    }

    /**
     * @return list<string>
     */
    private function childNames(FieldInterface $field, RepresentationContext $context, string $name): array
    {
        $attributes = (new SchemaProjector())->projectAttributes([$field], $context)->toArray();
        self::assertIsArray($attributes['properties'] ?? null);
        $node = $attributes['properties']['box'];
        self::assertIsArray($node);
        $node = match ($name) {
            'list' => $node['items'],
            'variant' => \is_array($node['oneOf']) ? $node['oneOf'][0] : null,
            default => $node,
        };
        self::assertIsArray($node);
        self::assertIsArray($node['properties'] ?? null);

        return \array_values(\array_diff(\array_keys($node['properties']), ['kind']));
    }
}
