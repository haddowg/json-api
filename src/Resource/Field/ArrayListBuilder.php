<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApi\Resource\Constraint\Each;
use haddowg\JsonApi\Resource\Constraint\MaxItems;
use haddowg\JsonApi\Resource\Constraint\MinItems;
use haddowg\JsonApi\Resource\Constraint\UniqueItems;

/**
 * The mutable **field builder** for a zero-indexed array attribute. Adds the
 * element-type / item-count / uniqueness / per-item helpers on top of the common
 * {@see AbstractFieldBuilder} surface; {@see build()} freezes it into a readonly
 * {@see ArrayList} value object.
 */
final class ArrayListBuilder extends AbstractFieldBuilder
{
    /**
     * The JSON type of each element, surfaced as the OpenAPI `items` type. Defaults
     * to `string` so a list attribute never projects as an untyped `unknown[]`; set
     * another scalar, or a structured element, with {@see of()}.
     */
    private string $elementType = 'string';

    private Obj|ObjBuilder|OneOf|OneOfBuilder|null $element = null;

    private bool $sorted = false;

    public function build(): ArrayList
    {
        $element = $this->element instanceof FieldBuilderInterface ? $this->element->build() : $this->element;
        if ($element !== null) {
            $this->assertStructurable($element);
        }

        return new ArrayList($this->fieldState(), $this->elementType, $this->sorted, $element);
    }

    /**
     * Declares what each list element is. A JSON scalar type — `string` (the default),
     * `integer`, `number` or `boolean` — narrows only the projected `items` schema; it
     * does not cast the serialized value, and any `each()` item constraints compose on
     * top of it.
     *
     * An {@see Obj} or {@see OneOf} (built or still a builder) makes a structured list:
     * every element reads and writes through that shape's children, as the shape would
     * as an attribute of its own. Its name is never used. A structured list takes no
     * `each()` and no `sorted()`; constrain the children instead.
     *
     * @return static
     */
    public function of(string|Obj|ObjBuilder|OneOf|OneOfBuilder $element): static
    {
        if (!\is_string($element)) {
            $this->element = $element;
            $this->elementType = 'object';

            return $this;
        }

        $allowed = ['string', 'integer', 'number', 'boolean'];
        if (!\in_array($element, $allowed, true)) {
            throw new \InvalidArgumentException(\sprintf(
                'ArrayList "%s" element type must be one of %s, or an Obj or OneOf, got "%s".',
                $this->name(),
                \implode(', ', $allowed),
                $element,
            ));
        }

        $this->element = null;
        $this->elementType = $element;

        return $this;
    }

    /**
     * @return static
     */
    public function minItems(int $count): static
    {
        return $this->addConstraint(new MinItems($count, $this->currentContext()));
    }

    /**
     * @return static
     */
    public function maxItems(int $count): static
    {
        return $this->addConstraint(new MaxItems($count, $this->currentContext()));
    }

    /**
     * @return static
     */
    public function uniqueItems(): static
    {
        return $this->addConstraint(new UniqueItems($this->currentContext()));
    }

    /**
     * Applies the given constraints to every item.
     *
     * @return static
     */
    public function each(ConstraintInterface ...$constraints): static
    {
        return $this->addConstraint(new Each(\array_values($constraints), $this->currentContext()));
    }

    /**
     * Sorts the list on serialization.
     *
     * @return static
     */
    public function sorted(): static
    {
        $this->sorted = true;

        return $this;
    }

    private function assertStructurable(Obj|OneOf $element): void
    {
        $problem = match (true) {
            $this->sorted => 'cannot be sorted(), since its elements have no natural order',
            \array_filter($this->fieldState()->constraints, static fn(ConstraintInterface $constraint): bool => $constraint instanceof Each) !== [] => 'cannot take each() item constraints; constrain the element\'s children instead',
            $element->rendersThroughHook() || $element->hydratesThroughHook() => 'cannot use an element with its own serialize, extract, deserialize or fill hook; put the hook on the list or on the element\'s children',
            default => null,
        };
        if ($problem !== null) {
            throw new \LogicException(\sprintf('ArrayList "%s" has structured elements, so it %s.', $this->name(), $problem));
        }
    }
}
