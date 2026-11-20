<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The mutable **field builder** for a {@see OneOf} union attribute. Collects the
 * optional discriminator property and the named variant shapes (each built into
 * an {@see Obj} value object from its child fields) and, at {@see build()}, freezes
 * them into a readonly {@see OneOf} value object.
 *
 * @template-covariant TValue = mixed
 *
 * @extends AbstractFieldBuilder<TValue>
 */
final class OneOfBuilder extends AbstractFieldBuilder
{
    /** @use DeclaresFillHook<TValue> */
    use DeclaresFillHook;
    use NamesSchemaComponent;

    private ?string $discriminator = null;

    /**
     * @var array<string, ObjBuilder<array<string, mixed>>>
     */
    private array $variants = [];

    public function build(): OneOf
    {
        return new OneOf($this->fieldState(), $this->discriminator, \array_map(static fn(ObjBuilder $variant): Obj => $variant->build(), $this->variants));
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
        $this->variants[$name] = Obj::make($name)->fields(...$children);

        return $this;
    }

    /**
     * Lets a request select keys of the variant `$variant` one by one in every item of a
     * list of this union, as `fields[TYPE]=<attr>.*.<key>`, for each key `$pattern`
     * matches whole and `$accepts` returns `true` for, exactly as an object's
     * `selectableKeys()` does. Each variant opts in on its own: a key is accepted when one
     * variant accepts it, and an item renders the selected keys its own variant accepts,
     * beside the discriminator.
     *
     * @param (\Closure(string, \haddowg\JsonApi\Request\JsonApiRequestInterface): bool)|null $accepts
     * @param string|null                                                                     $pattern an ECMA-262 and PCRE regular expression a whole key must match
     *
     * @return static
     *
     * @throws \InvalidArgumentException when no variant of that name is declared yet, or the declaration is not valid
     */
    public function selectableKeys(string $variant, ?\Closure $accepts = null, ?string $pattern = null): static
    {
        if (!isset($this->variants[$variant])) {
            throw new \InvalidArgumentException(\sprintf('OneOf "%s" declares selectableKeys() for the variant "%s", which it does not declare. Declare the variant first.', $this->name(), $variant));
        }
        $this->variants[$variant]->selectableKeys($accepts, $pattern);

        return $this;
    }
}
