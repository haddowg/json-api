<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The mutable **field builder** for an {@see Obj} attribute. Collects the child
 * fields (each a builder or an already-built field) and, at {@see build()}, freezes
 * them into a readonly {@see Obj} value object carrying the built children.
 */
final class ObjBuilder extends AbstractFieldBuilder
{
    /**
     * @var list<FieldInterface>
     */
    private array $children = [];

    public function build(): Obj
    {
        return new Obj($this->fieldState(), $this->children);
    }

    /**
     * @return static
     */
    public function fields(FieldInterface|FieldBuilderInterface ...$children): static
    {
        $this->children = \array_values(\array_map(
            static fn(FieldInterface|FieldBuilderInterface $child): FieldInterface => $child instanceof FieldBuilderInterface ? $child->build() : $child,
            $children,
        ));

        return $this;
    }
}
