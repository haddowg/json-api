<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\OpenApi\EnumComponentCollector;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * A discriminated union attribute: the value is exactly **one of** a set of named
 * object shapes ({@see Obj} variants), selected by a **discriminator** property
 * (default `type`) whose value names the active variant.
 *
 * Like {@see Obj} the whole object lives in a single backing value; the discriminator
 * property is stored/rendered alongside the active variant's children. On hydrate the
 * discriminator selects which variant's children run (so a variant's `DateTime` child
 * still parses, and a variant can map to columns via its children / `fillUsing`),
 * giving per-variant `/data/attributes/<field>/<child>` violation pointers. It
 * projects to OpenAPI `oneOf` + a `discriminator` object, each branch carrying the
 * discriminator as a `const`.
 *
 * The construction path ({@see Obj}) covers the common case; a looser "one of these
 * shapes" whose members you only want documented and validated (not hydrated per
 * variant) is a schema-bearing constraint on a pass-through field instead.
 */
final class OneOf extends AbstractAttribute implements ProvidesFieldSchema
{
    private string $discriminator = 'type';

    /**
     * @var array<string, Obj>
     */
    private array $variants = [];

    /**
     * Sets the discriminator property whose value names the active variant (default
     * `type`).
     *
     * @return static
     */
    public function discriminator(string $property): static
    {
        $this->discriminator = $property;

        return $this;
    }

    /**
     * Registers a named variant from its child fields. The `$name` is the
     * discriminator value that selects this variant; the children address keys inside
     * the object exactly as {@see Obj}'s do.
     *
     * @return static
     */
    public function variant(string $name, FieldInterface ...$children): static
    {
        $this->variants[$name] = Obj::make($name)->fields(...$children);

        return $this;
    }

    public function discriminatorName(): string
    {
        return $this->discriminator;
    }

    /**
     * @return array<string, Obj>
     */
    public function variants(): array
    {
        return $this->variants;
    }

    /**
     * The variant selected by a discriminator value, or `null` when the value names
     * no registered variant.
     */
    public function variantFor(mixed $discriminatorValue): ?Obj
    {
        return \is_string($discriminatorValue) ? ($this->variants[$discriminatorValue] ?? null) : null;
    }

    public function serialize(mixed $model, JsonApiRequestInterface $request, string $name): mixed
    {
        if ($this->serializeUsing !== null) {
            return ($this->serializeUsing)($model, $request, $name);
        }

        if ($this->extractUsing !== null) {
            return ($this->extractUsing)($model, $request, $name);
        }

        $value = Accessor::get($model, $this->column ?? $name);
        if ($value === null) {
            return null;
        }

        if (!\is_array($value)) {
            return $value;
        }

        $discriminatorValue = $value[$this->discriminator] ?? null;
        $variant = $this->variantFor($discriminatorValue);
        if ($variant === null) {
            // An unknown/absent discriminator has no variant to render through — emit the
            // stored value as-is rather than silently dropping it.
            return $value;
        }

        $nested = [$this->discriminator => $discriminatorValue];
        foreach ($variant->children() as $child) {
            if ($child->isWriteOnly()) {
                continue;
            }

            $nested[$child->name()] = $child->serialize($value, $request, $child->name());
        }

        return $nested;
    }

    public function hydrate(mixed $model, mixed $value, array $data, JsonApiRequestInterface $request, bool $creating): mixed
    {
        if ($this->fillUsing !== null) {
            $result = ($this->fillUsing)($model, $value, $data, $this->name);

            return $result ?? $model;
        }

        $column = $this->column ?? $this->name;

        if ($value === null) {
            return Accessor::set($model, $column, null);
        }

        if (!\is_array($value)) {
            return $model;
        }

        $existing = Accessor::get($model, $column);
        $existing = \is_array($existing) ? $existing : [];
        $existingDiscriminator = $existing[$this->discriminator] ?? null;

        // The incoming discriminator, falling back to the stored one on a partial PATCH
        // that does not restate it.
        $discriminatorValue = $value[$this->discriminator] ?? $existingDiscriminator;
        $variant = $this->variantFor($discriminatorValue);
        if ($variant === null) {
            // No variant to hydrate against; store the raw value so the validator can
            // reject the unknown/missing discriminator (hydration does not validate).
            return Accessor::set($model, $column, $value);
        }

        // Merge onto the stored object only when the variant is unchanged; a variant
        // switch starts fresh so stale keys of the previous shape do not linger.
        $nested = $existingDiscriminator === $discriminatorValue ? $existing : [];
        $nested[$this->discriminator] = $discriminatorValue;

        foreach ($variant->children() as $child) {
            if ($child->isReadOnly($creating)) {
                continue;
            }

            if (\array_key_exists($child->name(), $value)) {
                $nested = $child->hydrate($nested, $value[$child->name()], $data, $request, $creating);
            }
        }

        return Accessor::set($model, $column, $nested);
    }

    public function projectFieldSchema(SchemaProjector $projector, bool $creating, ?EnumComponentCollector $collector): Schema
    {
        $branches = [];
        foreach ($this->variants as $variantName => $shape) {
            $schema = $shape->projectFieldSchema($projector, $creating, $collector);

            // Each branch carries the discriminator as a const property (and, on create,
            // in `required`) so a consumer can match the value to its variant.
            /** @var array<string, Schema> $properties */
            $properties = \is_array($schema->get('properties')) ? $schema->get('properties') : [];
            $properties[$this->discriminator] = Schema::ofType('string')->withConst($variantName);
            $schema = $schema->withProperties($properties);

            if ($creating) {
                /** @var list<string> $required */
                $required = \is_array($schema->get('required')) ? \array_values($schema->get('required')) : [];
                if (!\in_array($this->discriminator, $required, true)) {
                    $required[] = $this->discriminator;
                }
                $schema = $schema->withRequired($required);
            }

            $branches[] = $schema;
        }

        return Schema::create()
            ->withOneOf($branches)
            ->withDiscriminator($this->discriminator);
    }
}
