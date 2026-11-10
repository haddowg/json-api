<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The `component()` method of the builders whose value has a reusable shape: {@see ObjBuilder},
 * {@see OneOfBuilder}, {@see UnionBuilder}, {@see MapBuilder} and {@see ArrayListBuilder}.
 *
 * @phpstan-require-extends AbstractFieldBuilder
 */
trait NamesSchemaComponent
{
    /**
     * Projects the value's shape as the named schema component `#/components/schemas/<$name>`,
     * which every field declaring the same shape under that name shares, rather than inline.
     * A shape that differs on a write gets `<$name>Create` and `<$name>Update` for those
     * writes. The field's description, example, deprecation, presence and `null` stay on
     * the field. A name is a component key, so it takes letters, digits, `.`, `-` and `_`.
     *
     * @return static
     */
    public function component(string $name): static
    {
        if (\preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1) {
            throw new \LogicException(\sprintf(
                'Field "%s" cannot name its schema component "%s": a component name takes only letters, digits, ".", "-" and "_".',
                $this->name,
                $name,
            ));
        }
        $this->component = $name;

        return $this;
    }
}
