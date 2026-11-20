<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * The mutable **filter builder** for a {@see WhereThrough} value filter.
 * {@see build()} freezes the accumulated state into the readonly {@see WhereThrough}
 * value object.
 *
 * @deprecated Declare the filter on the related type's field with `->through()` instead:
 *             `WhereThrough::make('artist.name')` is `Where::make('artist.name', 'name')->through('artist')`,
 *             and a path ending on a relation is `WhereIdIn::make($key)->through($path)`.
 *             The path names relations by API name and the column a field of the reached
 *             type; see the filters guide for the migration. A path continuing past a `pivot`
 *             segment needs exactly one registered resource type for the entity the pivot
 *             row's member reaches, in either form: the adapters refuse one served by no type,
 *             or by several, at warm-up. Removed in the next major version.
 */
final class WhereThroughBuilder implements FilterBuilderInterface
{
    use BuildsEnumValue;
    use BuildsValueConstraints;

    private string $path;

    private string $operator = '=';

    /**
     * @var \Closure(mixed): mixed|null
     */
    private ?\Closure $deserialize = null;

    public function __construct(
        private string $key,
        ?string $path = null,
    ) {
        $this->path = $path ?? $key;
    }

    public static function make(string $key, ?string $path = null): self
    {
        return new self($key, $path);
    }

    public function build(): WhereThrough
    {
        return new WhereThrough($this->key, $this->path, $this->operator, $this->deserialize, $this->constraints, $this->description, $this->hasExample, $this->example, $this->deprecated);
    }

    /**
     * Sets the comparison operator applied at the leaf segment. Same vocabulary as
     * {@see Where} (`=`, `!=`, `<>`, `>`, `>=`, `<`, `<=`, `like`).
     */
    public function operator(string $operator): self
    {
        $this->operator = $operator;

        return $this;
    }

    /**
     * @param \Closure(mixed): mixed $deserialize
     */
    public function deserializeUsing(\Closure $deserialize): self
    {
        $this->deserialize = $deserialize;

        return $this;
    }
}
