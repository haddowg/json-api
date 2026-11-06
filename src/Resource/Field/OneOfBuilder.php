<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The mutable **field builder** for a {@see OneOf} union attribute. Collects the
 * optional discriminator property and the named variant shapes (each built into
 * an {@see Obj} value object from its child fields) and, at {@see build()}, freezes
 * them into a readonly {@see OneOf} value object.
 */
final class OneOfBuilder extends AbstractFieldBuilder
{
    private ?string $discriminator = null;

    /**
     * @var array<string, Obj>
     */
    private array $variants = [];

    public function build(): OneOf
    {
        return new OneOf($this->fieldState(), $this->discriminator, $this->variants);
    }

    /**
     * Names the discriminator property whose value names the active variant. Without
     * one the union is undiscriminated: each variant needs a required member no other
     * variant declares, and every write replaces the whole value.
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
     * discriminator value that selects this variant, when there is a discriminator; the
     * children address keys inside
     * the object exactly as {@see Obj}'s do.
     *
     * @return static
     */
    public function variant(string $name, FieldInterface|FieldBuilderInterface ...$children): static
    {
        $this->variants[$name] = Obj::make($name)->fields(...$children)->build();

        return $this;
    }
}
