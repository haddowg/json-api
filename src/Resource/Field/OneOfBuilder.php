<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The mutable **field builder** for a {@see OneOf} union attribute. Collects the
 * optional discriminator property and the named variant shapes (each built into
 * an {@see Obj} value object) and, at {@see build()}, freezes them into a readonly
 * {@see OneOf} value object.
 *
 * A discriminator bound to a string-backed enum takes only that enum's cases as variant
 * names, each case's backing value being the discriminator value, and every case needs a
 * variant: {@see variants()} declares them all at once from a `match` over the cases, so
 * a missing case is a static analysis error too.
 *
 * @template-covariant TValue = mixed
 * @template TCase of string|\BackedEnum = string
 *
 * @extends AbstractFieldBuilder<TValue>
 */
final class OneOfBuilder extends AbstractFieldBuilder
{
    /** @use DeclaresFillHook<TValue> */
    use DeclaresFillHook;
    use NamesSchemaComponent;
    use ReceivesNestedFieldset;

    private ?string $discriminator = null;

    /**
     * @var class-string<\BackedEnum>|null
     */
    private ?string $discriminatorEnum = null;

    /**
     * @var array<string, Obj|ObjBuilder<mixed>>
     */
    private array $variants = [];

    /**
     * @throws \LogicException when a variant declares a member named like the discriminator, or an enum case has no variant
     */
    public function build(): OneOf
    {
        $enum = $this->discriminatorEnum;
        if ($enum !== null) {
            foreach ($enum::cases() as $case) {
                if (!isset($this->variants[(string) $case->value])) {
                    throw new \LogicException(\sprintf('OneOf "%s" binds its discriminator "%s" to %s, whose case %s has no variant. Declare one for every case, or use variants() to declare them all.', $this->name(), (string) $this->discriminator, $enum, $case->name));
                }
            }
        }

        $variants = [];
        foreach ($this->variants as $name => $variant) {
            $variants[$name] = $variant instanceof ObjBuilder ? $variant->build() : $variant;
            $this->assertNoDiscriminatorMember($name, $variants[$name]);
        }

        return new OneOf($this->fieldState(), $this->discriminator, $variants, $enum);
    }

    /**
     * Names the discriminator property whose value names the active variant. Without
     * one the union is undiscriminated: each variant needs a required member no other
     * variant declares, and every write replaces the whole value.
     *
     * Pass a string-backed enum to bind the discriminator to it: {@see variant()} then
     * takes only that enum's cases, every case needs a variant, a hook and the hydrated
     * value carry the case rather than its value, and the OpenAPI document references the
     * enum's component.
     *
     * @template TEnum of \BackedEnum
     *
     * @param class-string<TEnum>|null $enum
     *
     * @return ($enum is null ? self<TValue, string> : self<TValue, TEnum>)
     *
     * @phpstan-self-out ($enum is null ? self<TValue, string> : self<TValue, TEnum>)
     *
     * @throws \InvalidArgumentException when the enum is not string-backed
     */
    public function discriminator(string $property, ?string $enum = null): self
    {
        if ($enum !== null && (string) (new \ReflectionEnum($enum))->getBackingType() !== 'string') {
            throw new \InvalidArgumentException(\sprintf('OneOf "%s" binds its discriminator "%s" to %s, which is not a string-backed enum.', $this->name(), $property, $enum));
        }
        $this->discriminator = $property;
        $this->discriminatorEnum = $enum;

        return $this;
    }

    /**
     * Registers a named variant. Its children address keys inside the object exactly as
     * {@see Obj}'s do, given either as the fields themselves or as one {@see Obj} named
     * for the variant, which may also declare `nestedFieldset()` so a request can select
     * the variant's keys. The `$name` is the discriminator value that selects the variant,
     * or the case whose backing value it is when the discriminator is bound to an enum.
     *
     * @param TCase $name
     *
     * @return static
     *
     * @throws \InvalidArgumentException when the name does not suit the discriminator, or the variant declares a member named like it
     */
    public function variant(string|\BackedEnum $name, FieldInterface|FieldBuilderInterface ...$children): static
    {
        $key = $this->variantKey($name);
        $shape = \count($children) === 1 && ($children[0] instanceof Obj || $children[0] instanceof ObjBuilder) && $children[0]->name() === $key
            ? $children[0]
            : Obj::make($key)->fields(...$children);
        $this->variants[$key] = $shape;

        return $this;
    }

    /**
     * Declares a variant for every case of the enum the discriminator is bound to,
     * calling `$shape` once per case: it returns the case's {@see Obj}, or `null` for a
     * variant with no members. A `match` over the case makes a missing case a static
     * analysis error as well as a declaration one.
     *
     * @param \Closure(TCase): (Obj|ObjBuilder<mixed>|null) $shape
     *
     * @return static
     *
     * @throws \LogicException when the discriminator is not bound to an enum
     */
    public function variants(\Closure $shape): static
    {
        $enum = $this->discriminatorEnum
            ?? throw new \LogicException(\sprintf('OneOf "%s" declares variants() without a discriminator bound to an enum. Pass the enum to discriminator(), or declare each variant().', $this->name()));
        foreach ($enum::cases() as $case) {
            $key = (string) $case->value;
            $this->variants[$key] = $this->shapeOf($shape, $case) ?? Obj::make($key);
        }

        return $this;
    }

    /**
     * @return Obj|ObjBuilder<mixed>|null
     */
    private function shapeOf(\Closure $shape, \BackedEnum $case): Obj|ObjBuilder|null
    {
        $result = $shape($case);
        if ($result !== null && !$result instanceof Obj && !$result instanceof ObjBuilder) {
            throw new \LogicException(\sprintf('OneOf "%s" variants() returned %s for the case %s; return an Obj, or null for a variant with no members.', $this->name(), \get_debug_type($result), $case->name));
        }

        return $result;
    }

    private function variantKey(string|\BackedEnum $name): string
    {
        $enum = $this->discriminatorEnum;
        if ($enum !== null && !$name instanceof $enum) {
            throw new \InvalidArgumentException(\sprintf('OneOf "%s" binds its discriminator to %s, so a variant is named by one of its cases, not %s.', $this->name(), $enum, \is_string($name) ? '"' . $name . '"' : $name::class));
        }
        if ($enum === null && $name instanceof \BackedEnum) {
            throw new \InvalidArgumentException(\sprintf('OneOf "%s" names a variant by a case of %s, but its discriminator is not bound to that enum. Pass the enum to discriminator().', $this->name(), $name::class));
        }

        return $name instanceof \BackedEnum ? (string) $name->value : $name;
    }

    private function assertNoDiscriminatorMember(string $variant, Obj $shape): void
    {
        foreach ($shape->children() as $child) {
            if ($this->discriminator !== null && $child->name() === $this->discriminator) {
                throw new \LogicException(\sprintf('OneOf "%s" variant "%s" declares a member named "%s", which is its discriminator. The union renders and writes the discriminator itself.', $this->name(), $variant, $this->discriminator));
            }
        }
    }
}
