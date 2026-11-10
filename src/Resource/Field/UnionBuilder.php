<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The mutable **field builder** for a {@see Union} attribute. Collects the members,
 * each a builder or an already-built field, and at {@see build()} freezes them into a
 * readonly {@see Union}.
 */
final class UnionBuilder extends AbstractFieldBuilder
{
    /**
     * @var list<FieldInterface|FieldBuilderInterface>
     */
    private array $members = [];

    public function build(): Union
    {
        return new Union($this->fieldState(), \array_values(\array_map(
            static fn(FieldInterface|FieldBuilderInterface $member): FieldInterface => $member instanceof FieldBuilderInterface ? $member->build() : $member,
            $this->members,
        )));
    }

    /**
     * Declares the members, at most one per JSON type: a string-family field, an `Integer`
     * or a `Decimal`, a `Boolean`, and an `ArrayList` of scalars or of a nested
     * {@see Union::of()}. Their names are never used, so declare them unnamed
     * (`Str::make()`). Presence, visibility and hooks belong on the union.
     *
     * @return static
     */
    public function of(FieldInterface|FieldBuilderInterface ...$members): static
    {
        $this->members = \array_values($members);

        return $this;
    }
}
