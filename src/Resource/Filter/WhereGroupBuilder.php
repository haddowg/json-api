<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * The mutable base **filter builder** for the server-composed boolean groups
 * ({@see WhereAllBuilder} / {@see WhereAnyBuilder}). It holds the group key, the
 * child filters (each itself a built {@see FilterInterface} or an unbuilt
 * {@see FilterBuilderInterface}) and the group's shared-value authoring surface
 * ({@see BuildsValueConstraints}); {@see build()} freezes them — building any child
 * builder — into the concrete readonly {@see WhereGroup} value object.
 *
 * @phpstan-consistent-constructor
 */
abstract class WhereGroupBuilder implements FilterBuilderInterface
{
    use BuildsValueConstraints;

    /**
     * @var list<FilterInterface|FilterBuilderInterface>
     */
    protected array $children;

    public function __construct(
        protected string $key,
        FilterInterface|FilterBuilderInterface ...$children,
    ) {
        $this->children = \array_values($children);
    }

    /**
     * Composes the group from its child filters. A child's own key is ignored as a
     * request parameter (only this group's `$key` is a `filter[...]`), but still
     * drives the child's column/operator.
     *
     * @return static
     */
    public static function make(string $key, FilterInterface|FilterBuilderInterface ...$children): static
    {
        return new static($key, ...$children);
    }

    abstract public function build(): WhereGroup;

    /**
     * The group's children as built value objects: any child builder is frozen into
     * its {@see FilterInterface}, so the {@see WhereGroup} value object always carries
     * a `list<FilterInterface>`.
     *
     * @return list<FilterInterface>
     */
    protected function buildChildren(): array
    {
        return \array_values(\array_map(
            static fn(FilterInterface|FilterBuilderInterface $child): FilterInterface => $child instanceof FilterBuilderInterface
                ? $child->build()
                : $child,
            $this->children,
        ));
    }
}
