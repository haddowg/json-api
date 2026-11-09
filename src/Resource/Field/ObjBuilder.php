<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Resource\Constraint\MaxProperties;
use haddowg\JsonApi\Resource\Constraint\MinProperties;

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

    private FieldInterface|FieldBuilderInterface|null $additionalProperties = null;

    public function build(): Obj
    {
        $additional = $this->additionalProperties instanceof FieldBuilderInterface ? $this->additionalProperties->build() : $this->additionalProperties;
        if ($additional !== null) {
            $this->assertMemberValueField($additional);
        }

        return new Obj($this->fieldState(), $this->children, $additional);
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

    /**
     * Opens the object to members it does not declare. Every such member's value is
     * described and validated by `$value`, a field whose name is never used; name it
     * `'*'` by convention. A declared child always takes its own key, so the two can
     * be mixed.
     *
     * @return static
     */
    public function additionalProperties(FieldInterface|FieldBuilderInterface $value): static
    {
        $this->additionalProperties = $value;

        return $this;
    }

    /**
     * The fewest members the object may carry, declared and additional alike.
     *
     * @return static
     */
    public function minProperties(int $count): static
    {
        return $this->addConstraint(new MinProperties($count, $this->currentContext()));
    }

    /**
     * The most members the object may carry, declared and additional alike.
     *
     * @return static
     */
    public function maxProperties(int $count): static
    {
        return $this->addConstraint(new MaxProperties($count, $this->currentContext()));
    }

    private function assertMemberValueField(FieldInterface $value): void
    {
        $problem = match (true) {
            $value instanceof RelationInterface || $value instanceof Id || $value->relatedVia() !== null => 'must be an attribute field of the object itself',
            $value instanceof AbstractFieldValue && ($value->rendersThroughHook() || $value->hydratesThroughHook()) => 'cannot use its own serialize, extract, deserialize or fill hook; put the hook on the object',
            $value->column() === null => 'needs a backing key, so it cannot be computed',
            default => null,
        };
        if ($problem !== null) {
            throw new \LogicException(\sprintf('Obj "%s" additionalProperties() value field %s.', $this->name(), $problem));
        }
    }
}
