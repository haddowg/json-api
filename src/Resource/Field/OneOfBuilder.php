<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The mutable **field builder** for a {@see OneOf} discriminated-union attribute.
 * Collects the discriminator property and the named variant shapes (each built into
 * an {@see Obj} value object from its child fields) and, at {@see build()}, freezes
 * them into a readonly {@see OneOf} value object.
 */
final class OneOfBuilder extends AbstractFieldBuilder
{
    private string $discriminator = 'type';

    /**
     * @var array<string, Obj>
     */
    private array $variants = [];

    public function build(): OneOf
    {
        return new OneOf($this->fieldState(), $this->discriminator, $this->variants);
    }

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
    public function variant(string $name, FieldInterface|FieldBuilderInterface ...$children): static
    {
        $this->variants[$name] = Obj::make($name)->fields(...$children)->build();

        return $this;
    }
}
